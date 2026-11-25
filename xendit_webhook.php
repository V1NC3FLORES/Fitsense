<?php
/**
 * ============================================================
 *  xendit_webhook.php
 * ============================================================
 *  Receives Xendit Payments API v3 webhook events and settles
 *  transactions locally ONLY after verifying the request really
 *  came from Xendit.
 *
 *  Security:
 *    - The "x-callback-token" header must match the token from
 *      Xendit Dashboard -> Settings -> Developers -> Webhooks.
 *    - No session / no auth cookie is required (Xendit calls this).
 *    - Processing is idempotent and amount/currency validated.
 *
 *  Configure the webhook URL in the Xendit dashboard as:
 *      https://<your-public-host>/xendit_webhook.php
 *  Subscribe to: payment.capture, payment.failure,
 *                payment_request.expiry, payment.expiry
 *
 *  This endpoint must be reachable over public HTTPS.
 */

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/config/xendit_api.php';
require_once __DIR__ . '/config/xendit_settle.php';

header('Content-Type: application/json; charset=utf-8');

function wh_respond(string $json, int $code = 200): void
{
    http_response_code($code);
    echo $json;
    exit;
}

// ---------------------------------------------------------
// Only accept POST
// ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    wh_respond(json_encode(['status' => 'method_not_allowed']), 405);
}

// ---------------------------------------------------------
// Verify the caller is Xendit
// ---------------------------------------------------------
$configuredToken = xendit_webhook_token();
if ($configuredToken === '') {
    // Cannot verify anything yet; ask Xendit to retry later.
    error_log('[Xendit] webhook received but XENDIT_WEBHOOK_TOKEN is not configured.');
    wh_respond(json_encode(['status' => 'not_configured']), 503);
}

$sentToken = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';
if (!is_string($sentToken) || $sentToken === '' || !hash_equals($configuredToken, $sentToken)) {
    error_log('[Xendit] webhook rejected: invalid x-callback-token from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    wh_respond(json_encode(['status' => 'unauthorized']), 401);
}

// ---------------------------------------------------------
// Parse the payload
// ---------------------------------------------------------
$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) {
    wh_respond(json_encode(['status' => 'ignored', 'reason' => 'invalid_json']));
}

$event = (string)($body['event'] ?? '');
// Most events nest the resource under "data"; tolerate flat payloads too.
$data = (isset($body['data']) && is_array($body['data'])) ? $body['data'] : $body;

try {
    switch ($event) {
        case 'payment.capture':
            $captures = $data['captures'] ?? [];
            $captureAmount = null;
            $captureId = null;
            if (is_array($captures) && isset($captures[0]) && is_array($captures[0])) {
                $captureAmount = isset($captures[0]['capture_amount']) ? (float)$captures[0]['capture_amount'] : null;
                $captureId = $captures[0]['capture_id'] ?? null;
            }
            $outcome = [
                'xendit_status' => strtoupper((string)($data['status'] ?? 'SUCCEEDED')),
                'payment_request_id' => $data['payment_request_id'] ?? null,
                'payment_id' => $data['payment_id'] ?? null,
                'capture_id' => $captureId,
                'amount' => $captureAmount ?? (isset($data['request_amount']) ? (float)$data['request_amount'] : null),
                'currency' => $data['currency'] ?? null,
                'channel_code' => $data['channel_code'] ?? null,
            ];
            break;

        case 'payment.failure':
            $outcome = [
                'xendit_status' => 'FAILED',
                'payment_request_id' => $data['payment_request_id'] ?? null,
                'payment_id' => $data['payment_id'] ?? null,
                'capture_id' => null,
                'amount' => isset($data['request_amount']) ? (float)$data['request_amount'] : null,
                'currency' => $data['currency'] ?? null,
                'channel_code' => $data['channel_code'] ?? null,
            ];
            break;

        case 'payment_request.expiry':
        case 'payment.expiry':
            $outcome = [
                'xendit_status' => 'EXPIRED',
                'payment_request_id' => $data['payment_request_id'] ?? ($data['id'] ?? null),
                'payment_id' => $data['payment_id'] ?? null,
                'capture_id' => null,
                'amount' => isset($data['request_amount']) ? (float)$data['request_amount'] : null,
                'currency' => $data['currency'] ?? null,
                'channel_code' => $data['channel_code'] ?? null,
            ];
            break;

        default:
            // Acknowledge events we do not handle so Xendit stops retrying.
            wh_respond(json_encode(['status' => 'ignored', 'event' => $event]));
    }

    // Resolve the transaction by payment request id, falling back to reference id.
    $prId = (string)($outcome['payment_request_id'] ?? '');
    if ($prId === '' && !empty($data['reference_id'])) {
        $prId = (string)$data['reference_id'];
    }

    if ($prId === '') {
        wh_respond(json_encode(['status' => 'ignored', 'reason' => 'no_reference']));
    }

    $res = xendit_settle_by_payment_request_id($conn, $prId, $outcome, 'webhook');

    wh_respond(json_encode([
        'status' => 'ok',
        'settled' => (bool)$res['ok'],
        'changed' => (bool)$res['changed'],
        'transaction_status' => $res['status'],
    ]));
} catch (Throwable $e) {
    error_log('[Xendit] webhook processing error: ' . $e->getMessage());
    // Do not reveal internals; let Xendit retry.
    wh_respond(json_encode(['status' => 'error']), 500);
}
