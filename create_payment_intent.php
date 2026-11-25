<?php
/**
 * ============================================================
 *  create_payment_intent.php
 * ============================================================
 *  Creates a Xendit Payments API v3 payment request for an
 *  existing PENDING transaction (or a membership plan) and
 *  returns the hosted checkout URL to redirect the customer to.
 *
 *  The amount is ALWAYS derived from trusted server-side records;
 *  the client is never trusted with the amount.
 *
 *  Response (JSON):
 *    { "success": true, "checkout_url": "...", "status": "PENDING" }
 *    { "success": false, "error": "..." }
 */

require_once __DIR__ . '/config/session_security.php';
require_once __DIR__ . '/config/error_handling.php';

session_start();
require_once 'conn.php';
require_once __DIR__ . '/config/xendit_api.php';
require_once __DIR__ . '/config/xendit_settle.php';   // provides xendit_txn_columns()

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
$userSession = app_validate_session_user($conn, 'user');
if ($userSession === null) {
    respond(['success' => false, 'error' => app_safe_error_message('Unauthorized', 'auth')], 401);
}
$userId = (int)$_SESSION['user_id'];

// ---------------------------------------------------------
// CSRF
// ---------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
if (!is_array($input)) {
    $input = [];
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !is_string($csrf) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    app_log_error('Payment endpoint rejected invalid CSRF token.', ['ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'user_id' => $userId]);
    respond(['success' => false, 'error' => 'Invalid or missing security token. Please refresh the page.'], 403);
}

// ---------------------------------------------------------
// Gateway configuration
// ---------------------------------------------------------
if (!xendit_is_configured()) {
    respond(['success' => false, 'error' => 'Payment gateway is not configured on the server.'], 503);
}

// ---------------------------------------------------------
// Validate requested channel (only GCash + Maya are enabled)
// ---------------------------------------------------------
$channel = strtoupper(trim((string)($input['channel'] ?? '')));
$allowedChannels = ['GCASH' => 'GCash', 'PAYMAYA' => 'Maya'];
if (!isset($allowedChannels[$channel])) {
    respond(['success' => false, 'error' => 'Please choose GCash or Maya.'], 422);
}

$transactionId = (int)($input['transaction_id'] ?? 0);
if ($transactionId < 0) {
    $transactionId = 0;
}
$membershipType = trim((string)($input['membership_type'] ?? ''));
if ($membershipType !== '' && !preg_match('/^[A-Za-z0-9 ]+$/', $membershipType)) {
    respond(['success' => false, 'error' => 'Invalid membership plan.'], 422);
}

$txn = null;

try {
    // -----------------------------------------------------
    // Resolve the amount from trusted DB records
    // -----------------------------------------------------
    if ($transactionId > 0) {
        $stmt = $conn->prepare(
            "SELECT id, user_id, reference_no, transaction_type, description, amount, status
             FROM transactions WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $transactionId, $userId);
        $stmt->execute();
        $txn = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$txn) {
            respond(['success' => false, 'error' => 'Transaction not found.'], 404);
        }
        if (strcasecmp($txn['status'], 'Paid') === 0) {
            respond(['success' => false, 'error' => 'This transaction is already paid.'], 409);
        }
        if (strcasecmp($txn['status'], 'Pending') !== 0) {
            respond(['success' => false, 'error' => 'This transaction can no longer be paid online. Please contact the gym office.'], 409);
        }
    } elseif ($membershipType !== '') {
        // Paying for a new/renewed membership plan.
        if (!in_array($membershipType, ['One Day', 'Monthly', 'Yearly'], true)) {
            respond(['success' => false, 'error' => 'Invalid membership plan.'], 422);
        }

        $stmt = $conn->prepare(
            "SELECT price FROM membership_prices WHERE membership_type = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->bind_param('s', $membershipType);
        $stmt->execute();
        $priceRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$priceRow) {
            respond(['success' => false, 'error' => 'That plan is not available right now.'], 422);
        }
        $amount = (float)$priceRow['price'];
        if ($amount <= 0) {
            respond(['success' => false, 'error' => 'Invalid plan amount.'], 422);
        }

        $referenceNo = 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $description = $membershipType . ' Membership';
        $stmt = $conn->prepare(
            "INSERT INTO transactions (user_id, reference_no, transaction_type, description, amount, status, created_at)
             VALUES (?, ?, 'Membership', ?, ?, 'Pending', NOW())"
        );
        $stmt->bind_param('issd', $userId, $referenceNo, $description, $amount);
        if (!$stmt->execute()) {
            $stmt->close();
            respond(['success' => false, 'error' => 'Could not create the transaction record.'], 500);
        }
        $transactionId = (int)$conn->insert_id;
        $stmt->close();

        // Make sure a Pending membership exists for this plan so the verified
        // payment can activate it. Reuse an existing Pending membership of the
        // same type to avoid duplicates when the customer retries.
        $memStmt = $conn->prepare(
            "SELECT id FROM memberships
             WHERE user_id = ? AND membership_type = ? AND status = 'Pending'
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        if ($memStmt) {
            $memStmt->bind_param('is', $userId, $membershipType);
            $memStmt->execute();
            $existingMem = $memStmt->get_result()->fetch_assoc();
            $memStmt->close();

            if (!$existingMem) {
                $memIns = $conn->prepare(
                    "INSERT INTO memberships (user_id, membership_type, amount, status, created_at)
                     VALUES (?, ?, ?, 'Pending', NOW())"
                );
                if ($memIns) {
                    $memIns->bind_param('isd', $userId, $membershipType, $amount);
                    $memIns->execute();
                    $memIns->close();
                }
            }
        }

        $txn = [
            'id' => $transactionId,
            'user_id' => $userId,
            'reference_no' => $referenceNo,
            'transaction_type' => 'Membership',
            'description' => $description,
            'amount' => $amount,
            'status' => 'Pending',
        ];
    } else {
        respond(['success' => false, 'error' => 'Nothing to pay for.'], 422);
    }

    $amount = round((float)$txn['amount'], 2);
    if ($amount <= 0) {
        respond(['success' => false, 'error' => 'Invalid amount.'], 422);
    }

    // -----------------------------------------------------
    // Build the customer return URLs (success / cancel / failure).
    // Xendit's v3 Payment Request API REQUIRES channel_properties to
    // include cancel_return_url for e-wallet (GCash / Maya) PAY flows.
    // The base comes from XENDIT_RETURN_URL_BASE (.env) when set, else
    // from the app base / auto-detection.
    // -----------------------------------------------------
    $returnBase = xendit_return_url_base();
    $successUrl = $returnBase . '/payments.php?status=success&tid=' . $transactionId;
    $cancelUrl  = $returnBase . '/payments.php?status=cancelled&tid=' . $transactionId;
    $failureUrl = $returnBase . '/payments.php?status=failed&tid=' . $transactionId;

    // Remember the transaction so a return without ?tid= can still be verified.
    $_SESSION['xendit_last_tid'] = $transactionId;

    $payload = [
        'reference_id' => $txn['reference_no'],
        'type' => 'PAY',
        'country' => 'PH',
        'currency' => xendit_currency(),
        'request_amount' => $amount,
        'capture_method' => 'AUTOMATIC',
        'channel_code' => $channel,
        'channel_properties' => [
            'success_return_url' => $successUrl,
            'failure_return_url' => $failureUrl,
            'cancel_return_url'  => $cancelUrl,
        ],
        'description' => mb_substr((string)$txn['description'], 0, 255),
        'metadata' => [
            'transaction_id' => (string)$transactionId,
            'user_id' => (string)$userId,
        ],
    ];

    $httpCode = 0;
    $err = null;
    $pr = xendit_create_payment_request($payload, $httpCode, $err);

    if ($pr === null) {
        $safe = $err ?: 'Could not start the payment. Please try again.';
        respond(['success' => false, 'error' => $safe], 502);
    }

    $paymentRequestId = (string)($pr['payment_request_id'] ?? '');
    $xenditStatus = strtoupper((string)($pr['status'] ?? 'PENDING'));
    $checkoutUrl = xendit_payment_request_redirect_url($pr);

    // -----------------------------------------------------
    // Persist the Xendit references
    // -----------------------------------------------------
    $cols = xendit_txn_columns($conn);
    $sets = [];
    $params = [];
    $types = '';

    if (isset($cols['payment_provider'])) {
        $sets[] = 'payment_provider = ?';
        $params[] = 'Xendit';
        $types .= 's';
    }
    if (isset($cols['xendit_payment_request_id'])) {
        $sets[] = 'xendit_payment_request_id = ?';
        $params[] = $paymentRequestId;
        $types .= 's';
    }
    if (isset($cols['xendit_channel_code'])) {
        $sets[] = 'xendit_channel_code = ?';
        $params[] = $channel;
        $types .= 's';
    }
    if (isset($cols['xendit_status'])) {
        $sets[] = 'xendit_status = ?';
        $params[] = $xenditStatus;
        $types .= 's';
    }
    if (isset($cols['checkout_url'])) {
        $sets[] = 'checkout_url = ?';
        $params[] = $checkoutUrl;
        $types .= 's';
    }
    if (isset($cols['expires_at'])) {
        // GCash/Maya requests are short lived; record a best-effort expiry.
        $sets[] = 'expires_at = ?';
        $params[] = date('Y-m-d H:i:s', time() + 30 * 60);
        $types .= 's';
    }

    if (!empty($sets)) {
        $params[] = $transactionId;
        $types .= 'i';
        $stmt = $conn->prepare('UPDATE transactions SET ' . implode(', ', $sets) . ' WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
        }
    }

    // If no customer action is required and it already succeeded, settle now.
    if ($xenditStatus === 'SUCCEEDED') {
        require_once __DIR__ . '/config/xendit_settle.php';
        $outcome = [
            'xendit_status' => $xenditStatus,
            'payment_request_id' => $paymentRequestId,
            'payment_id' => $pr['latest_payment_id'] ?? null,
            'capture_id' => null,
            'amount' => $amount,
            'currency' => $pr['currency'] ?? xendit_currency(),
            'channel_code' => $channel,
        ];
        $res = xendit_settle_by_transaction_id($conn, $transactionId, $outcome, 'create');
        respond(['success' => true, 'status' => 'Paid', 'message' => $res['message']]);
    }

    if ($checkoutUrl === '') {
        respond(['success' => false, 'error' => 'The payment provider did not return a checkout page. Please try again.'], 502);
    }

    respond([
        'success' => true,
        'status' => $xenditStatus,
        'checkout_url' => $checkoutUrl,
        'payment_request_id' => $paymentRequestId,
        'transaction_id' => $transactionId,
    ]);
} catch (Throwable $e) {
    error_log('[Xendit] create_payment_intent error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'An unexpected error occurred. Please try again.'], 500);
}
