<?php
/**
 * ============================================================
 *  record_payment.php  (payment status reconciliation)
 * ============================================================
 *  Re-checks the REAL status of a transaction with the Xendit API
 *  and settles it locally only when Xendit confirms success.
 *
 *  It NEVER trusts the browser return URL / posted status. The
 *  client can at most ask "what is the status of MY transaction?".
 *
 *  Response (JSON):
 *    { "success": true, "status": "Paid|Pending|Failed|Expired|Cancelled", "message": "..." }
 */

session_start();
require_once 'conn.php';
require_once __DIR__ . '/config/xendit_api.php';
require_once __DIR__ . '/config/xendit_settle.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ---------------------------------------------------------
// Authentication
// ---------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    respond(['success' => false, 'error' => 'Unauthorized'], 401);
}
$userId = (int)$_SESSION['user_id'];

// ---------------------------------------------------------
// CSRF
// ---------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !is_string($csrf) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    respond(['success' => false, 'error' => 'Invalid or missing security token.'], 403);
}

$transactionId = (int)($input['transaction_id'] ?? 0);
$inputPrId = trim((string)($input['payment_request_id'] ?? ''));

try {
    $cols = xendit_txn_columns($conn);
    $hasPrCol = isset($cols['xendit_payment_request_id']);

    // -----------------------------------------------------
    // Load the transaction and verify ownership
    // -----------------------------------------------------
    if ($transactionId > 0) {
        $stmt = $conn->prepare("SELECT id, user_id, reference_no, amount, status FROM transactions WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param('ii', $transactionId, $userId);
    } elseif ($inputPrId !== '' && $hasPrCol) {
        $stmt = $conn->prepare("SELECT id, user_id, reference_no, amount, status FROM transactions WHERE xendit_payment_request_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param('si', $inputPrId, $userId);
    } else {
        respond(['success' => false, 'error' => 'Missing transaction reference.'], 422);
    }

    $stmt->execute();
    $txn = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$txn) {
        respond(['success' => false, 'error' => 'Transaction not found.'], 404);
    }

    // Already settled — report without hitting the API again.
    if (strcasecmp($txn['status'], 'Paid') === 0) {
        respond(['success' => true, 'status' => 'Paid', 'message' => 'Payment confirmed.']);
    }

    // -----------------------------------------------------
    // Find the Xendit payment request id
    // -----------------------------------------------------
    $paymentRequestId = $inputPrId;
    if ($paymentRequestId === '' && $hasPrCol) {
        $stmt = $conn->prepare("SELECT xendit_payment_request_id FROM transactions WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $txn['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $paymentRequestId = (string)($row['xendit_payment_request_id'] ?? '');
    }

    if ($paymentRequestId === '') {
        respond(['success' => true, 'status' => $txn['status'], 'message' => 'No online payment is associated with this transaction yet.']);
    }

    if (!xendit_is_configured()) {
        respond(['success' => false, 'error' => 'Payment gateway is not configured on the server.'], 503);
    }

    // -----------------------------------------------------
    // Ask Xendit for the authoritative status
    // -----------------------------------------------------
    $httpCode = 0;
    $err = null;
    $remote = xendit_get_payment_request($paymentRequestId, $httpCode, $err);

    if ($remote === null) {
        // Could not verify right now (network/5xx/404). Leave the DB untouched.
        error_log('[Xendit] verify failed txn=' . $txn['id'] . ' http=' . $httpCode . ' err=' . (string)$err);
        respond([
            'success' => true,
            'status' => $txn['status'],
            'message' => 'We could not confirm the payment with the provider yet. It will update automatically once confirmed.',
        ]);
    }

    // -----------------------------------------------------
    // Build a normalized outcome from the remote object
    // -----------------------------------------------------
    $remoteStatus = strtoupper((string)($remote['status'] ?? 'PENDING'));

    $capturedAmount = isset($remote['request_amount']) ? (float)$remote['request_amount'] : null;
    $captureId = null;
    $paymentId = $remote['latest_payment_id'] ?? null;

    if (!empty($remote['latest_payment']) && is_array($remote['latest_payment'])) {
        $lp = $remote['latest_payment'];
        if (!empty($lp['captures'][0]['capture_amount'])) {
            $capturedAmount = (float)$lp['captures'][0]['capture_amount'];
        }
        if (!empty($lp['captures'][0]['capture_id'])) {
            $captureId = (string)$lp['captures'][0]['capture_id'];
        }
        if (empty($paymentId) && !empty($lp['payment_id'])) {
            $paymentId = (string)$lp['payment_id'];
        }
    }

    $outcome = [
        'xendit_status' => $remoteStatus,
        'payment_request_id' => $paymentRequestId,
        'payment_id' => $paymentId,
        'capture_id' => $captureId,
        'amount' => $capturedAmount,
        'currency' => $remote['currency'] ?? xendit_currency(),
        'channel_code' => $remote['channel_code'] ?? null,
    ];

    $res = xendit_settle_by_transaction_id($conn, (int)$txn['id'], $outcome, 'verify');

    respond([
        'success' => (bool)$res['ok'],
        'status' => $res['status'],
        'changed' => (bool)$res['changed'],
        'message' => $res['message'] ?: 'Status checked.',
    ]);
} catch (Throwable $e) {
    error_log('[Xendit] record_payment error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'An unexpected error occurred.'], 500);
}
