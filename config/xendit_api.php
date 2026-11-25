<?php
/**
 * ============================================================
 *  Xendit Payments API (v3) client — server side only
 * ============================================================
 *  Thin cURL wrapper around https://api.xendit.co/v3
 *  Never exposes the secret key to the caller/response/log.
 */

require_once __DIR__ . '/xendit.php';

if (!function_exists('xendit_api_request')) {
    /**
     * Perform an authenticated Xendit API request.
     *
     * @param string     $method   GET|POST|...
     * @param string     $path     e.g. /v3/payment_requests
     * @param array|null $body     JSON body (assoc array) or null
     * @param int|null   $httpCode Out: HTTP status code
     * @param string|null $err     Out: human readable error (safe, no secrets)
     * @return array|null Decoded response body, or null on failure
     */
    function xendit_api_request(string $method, string $path, ?array $body, ?int &$httpCode = null, ?string &$err = null): ?array
    {
        $httpCode = 0;
        $err = null;

        $secret = xendit_secret_key();
        if ($secret === '') {
            $err = 'Payment gateway is not configured (missing secret key).';
            return null;
        }

        $url = 'https://api.xendit.co' . $path;

        $headers = [
            'Authorization: Basic ' . base64_encode($secret . ':'),
            'api-version: ' . xendit_api_version(),
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // Do not follow redirects on API calls.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $err = 'Network error contacting payment gateway.';
            error_log('[Xendit] cURL error: ' . $curlErr);
            return null;
        }

        $decoded = json_decode($raw, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            // Extract a safe error message (never echo the raw body which may
            // contain internal details, but Xendit errors are safe to surface).
            $apiMsg = null;
            if (is_array($decoded)) {
                $apiMsg = $decoded['message']
                    ?? $decoded['error_code']
                    ?? ($decoded['errors'][0]['message'] ?? null);
            }
            $err = is_string($apiMsg) && $apiMsg !== ''
                ? $apiMsg
                : 'Payment gateway returned an error (HTTP ' . $httpCode . ').';
            if (xendit_debug()) {
                error_log('[Xendit] HTTP ' . $httpCode . ' ' . $method . ' ' . $path . ' -> ' . substr((string)$raw, 0, 500));
            }
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('xendit_create_payment_request')) {
    /**
     * Create a v3 payment request.
     *
     * @return array|null Payment request object on success
     */
    function xendit_create_payment_request(array $payload, ?int &$httpCode = null, ?string &$err = null): ?array
    {
        return xendit_api_request('POST', '/v3/payment_requests', $payload, $httpCode, $err);
    }
}

if (!function_exists('xendit_get_payment_request')) {
    /**
     * Fetch a payment request by id.
     */
    function xendit_get_payment_request(string $paymentRequestId, ?int &$httpCode = null, ?string &$err = null): ?array
    {
        if ($paymentRequestId === '') {
            $err = 'Missing payment request id.';
            return null;
        }
        $path = '/v3/payment_requests/' . rawurlencode($paymentRequestId);
        return xendit_api_request('GET', $path, null, $httpCode, $err);
    }
}

if (!function_exists('xendit_payment_request_redirect_url')) {
    /**
     * Extract the customer redirect URL (e.g. GCash/Maya checkout page)
     * from a payment request's "actions" array.
     */
    function xendit_payment_request_redirect_url(array $paymentRequest): ?string
    {
        $actions = $paymentRequest['actions'] ?? [];
        if (!is_array($actions)) {
            return null;
        }
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = $action['type'] ?? '';
            if ($type === 'REDIRECT_CUSTOMER') {
                $url = $action['value'] ?? ($action['url'] ?? null);
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }
        return null;
    }
}

if (!function_exists('xendit_normalize_status')) {
    /**
     * Map an Xendit payment/payment-request status to the app's local
     * transaction status enum.
     */
    function xendit_normalize_status(string $xenditStatus): string
    {
        switch (strtoupper(trim($xenditStatus))) {
            case 'SUCCEEDED':
            case 'CAPTURED':
            case 'PAID':
                return 'Paid';
            case 'FAILED':
                return 'Failed';
            case 'CANCELED':
            case 'CANCELLED':
                return 'Cancelled';
            case 'EXPIRED':
                return 'Expired';
            case 'REQUIRES_ACTION':
            case 'PENDING':
            case 'AUTHORIZED':
            default:
                return 'Pending';
        }
    }
}
