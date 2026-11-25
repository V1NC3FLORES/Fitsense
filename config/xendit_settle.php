<?php
/**
 * ============================================================
 *  Xendit settlement logic — the ONLY place a transaction is
 *  allowed to transition to "Paid".
 * ============================================================
 *  Used by:
 *    - xendit_webhook.php   (primary, asynchronous)
 *    - record_payment.php   (on-return reconciliation, re-queries API)
 *
 *  Rules enforced here:
 *    - Idempotent: a transaction already Paid is never reprocessed.
 *    - Amount must match the stored (trusted) transaction amount.
 *    - Currency must match the configured currency.
 *    - Membership is only activated after a verified success.
 *    - Row is locked (SELECT ... FOR UPDATE) to survive webhook/verify races.
 */

require_once __DIR__ . '/xendit_api.php';

if (!function_exists('xendit_txn_columns')) {
    /**
     * Return the set of columns that actually exist on `transactions`.
     * Allows the code to run before/after the migration safely.
     */
    function xendit_txn_columns(mysqli $conn): array
    {
        static $cols = null;
        if ($cols !== null) {
            return $cols;
        }
        $cols = [];
        try {
            $res = $conn->query("SHOW COLUMNS FROM transactions");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $cols[$row['Field']] = true;
                }
            }
        } catch (Throwable $e) {
            error_log('[Xendit] column detection failed: ' . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('xendit_status_enum')) {
    /**
     * Return the allowed values of transactions.status.
     */
    function xendit_status_enum(mysqli $conn): array
    {
        static $values = null;
        if ($values !== null) {
            return $values;
        }
        $values = ['Paid', 'Pending', 'Cancelled'];
        try {
            $stmt = $conn->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'status'"
            );
            if ($stmt) {
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && !empty($row['COLUMN_TYPE']) && stripos($row['COLUMN_TYPE'], 'enum') === 0) {
                    if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $row['COLUMN_TYPE'], $m)) {
                        $values = $m[1];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[Xendit] enum detection failed: ' . $e->getMessage());
        }
        return $values;
    }
}

if (!function_exists('xendit_local_status_for')) {
    /**
     * Map a normalized status onto a value the current DB enum accepts.
     */
    function xendit_local_status_for(mysqli $conn, string $normalized): string
    {
        $allowed = xendit_status_enum($conn);
        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }
        // Pre-migration fallback: collapse newer statuses into the old enum.
        if (in_array($normalized, ['Failed', 'Expired'], true)) {
            return in_array('Cancelled', $allowed, true) ? 'Cancelled' : 'Pending';
        }
        return in_array('Pending', $allowed, true) ? 'Pending' : ($allowed[0] ?? 'Pending');
    }
}

if (!function_exists('xendit_channel_label')) {
    function xendit_channel_label(?string $channelCode): string
    {
        switch (strtoupper((string)$channelCode)) {
            case 'GCASH':
                return 'GCash';
            case 'PAYMAYA':
            case 'MAYA':
                return 'Maya';
            case 'CARDS':
            case 'CARD':
                return 'Card';
            default:
                return $channelCode ? ('Xendit (' . $channelCode . ')') : 'Xendit';
        }
    }
}

if (!function_exists('xendit_activate_membership')) {
    /**
     * Activate the user's latest Pending membership (mirrors admin behaviour).
     * Returns the new end date or null if nothing to activate.
     */
    function xendit_activate_membership(mysqli $conn, int $userId): ?string
    {
        try {
            $stmt = $conn->prepare(
                "SELECT id, membership_type FROM memberships
                 WHERE user_id = ? AND status = 'Pending'
                 ORDER BY created_at DESC LIMIT 1"
            );
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $mem = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$mem) {
                return null;
            }

            $startDate = date('Y-m-d');
            $endDate = $startDate;
            if ($mem['membership_type'] === 'Monthly') {
                $endDate = date('Y-m-d', strtotime('+30 days'));
            } elseif ($mem['membership_type'] === 'Yearly') {
                $endDate = date('Y-m-d', strtotime('+365 days'));
            }

            $membershipId = (int)$mem['id'];
            $upd = $conn->prepare("UPDATE memberships SET status = 'Active', start_date = ?, end_date = ? WHERE id = ?");
            if ($upd) {
                $upd->bind_param('ssi', $startDate, $endDate, $membershipId);
                $upd->execute();
                $upd->close();
            }

            $title = 'Membership Activated';
            $msg = "Your {$mem['membership_type']} membership is now active.";
            $notif = $conn->prepare(
                "INSERT INTO notifications (user_id, type, title, message, created_at)
                 VALUES (?, 'membership', ?, ?, NOW())"
            );
            if ($notif) {
                $notif->bind_param('iss', $userId, $title, $msg);
                $notif->execute();
                $notif->close();
            }

            return $endDate;
        } catch (Throwable $e) {
            error_log('[Xendit] membership activation failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('xendit_notify')) {
    function xendit_notify(mysqli $conn, int $userId, string $type, string $title, string $message): void
    {
        try {
            if (!in_array($type, ['membership', 'session', 'system', 'general'], true)) {
                $type = 'system';
            }
            $stmt = $conn->prepare(
                "INSERT INTO notifications (user_id, type, title, message, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param('isss', $userId, $type, $title, $message);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('[Xendit] notification failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('xendit_settle_by_transaction_id')) {
    /**
     * Settle a transaction given a verified Xendit outcome.
     *
     * @param array $outcome {
     *     xendit_status: string,          // raw Xendit status (SUCCEEDED, FAILED, ...)
     *     payment_request_id: ?string,
     *     payment_id: ?string,
     *     capture_id: ?string,
     *     amount: ?float,                 // captured/requested amount (pesos)
     *     currency: ?string,
     *     channel_code: ?string,
     * }
     * @return array{ok:bool, changed:bool, status:string, message:string}
     */
    function xendit_settle_by_transaction_id(mysqli $conn, int $txnId, array $outcome, string $source = 'unknown'): array
    {
        $result = ['ok' => false, 'changed' => false, 'status' => 'Pending', 'message' => ''];

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ? FOR UPDATE");
            if (!$stmt) {
                throw new RuntimeException('prepare failed');
            }
            $stmt->bind_param('i', $txnId);
            $stmt->execute();
            $txn = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$txn) {
                $conn->rollback();
                $result['message'] = 'Transaction not found.';
                return $result;
            }

            $userId    = (int)$txn['user_id'];
            $localStatus = xendit_local_status_for($conn, xendit_normalize_status((string)($outcome['xendit_status'] ?? '')));
            $result['status'] = $localStatus;

            // -------------------------------------------------
            // Amount + currency validation
            // -------------------------------------------------
            $expectedAmount   = round((float)$txn['amount'], 2);
            $expectedCurrency = xendit_currency();
            $paidAmount       = isset($outcome['amount']) && $outcome['amount'] !== null
                ? round((float)$outcome['amount'], 2) : null;
            $paidCurrency     = isset($outcome['currency']) && $outcome['currency']
                ? strtoupper((string)$outcome['currency']) : null;

            if ($paidCurrency !== null && $paidCurrency !== $expectedCurrency) {
                $conn->rollback();
                $result['message'] = 'Currency mismatch; not settled.';
                error_log("[Xendit][$source] currency mismatch txn=$txnId expected=$expectedCurrency got=$paidCurrency");
                return $result;
            }
            if ($localStatus === 'Paid' && $paidAmount !== null && abs($paidAmount - $expectedAmount) > 0.01) {
                $conn->rollback();
                $result['message'] = 'Amount mismatch; not settled.';
                error_log("[Xendit][$source] amount mismatch txn=$txnId expected=$expectedAmount got=$paidAmount");
                return $result;
            }

            // -------------------------------------------------
            // Idempotency: already Paid -> nothing to do
            // -------------------------------------------------
            if (strcasecmp((string)$txn['status'], 'Paid') === 0) {
                $conn->commit();
                $result['ok'] = true;
                $result['message'] = 'Already paid.';
                return $result;
            }

            $cols = xendit_txn_columns($conn);

            // -------------------------------------------------
            // Build the UPDATE
            // -------------------------------------------------
            $sets = [];
            $params = [];
            $types = '';

            $sets[] = 'status = ?';
            $params[] = $localStatus;
            $types .= 's';

            if ($localStatus === 'Paid') {
                $sets[] = 'paid_at = NOW()';

                $label = xendit_channel_label($outcome['channel_code'] ?? ($txn['xendit_channel_code'] ?? null));
                $sets[] = 'payment_method = ?';
                $params[] = $label;
                $types .= 's';

                $ref = $outcome['payment_id'] ?? $outcome['capture_id'] ?? $outcome['payment_request_id'] ?? null;
                if ($ref !== null) {
                    $sets[] = 'transaction_ref = ?';
                    $params[] = $ref;
                    $types .= 's';
                }
            }

            if (isset($cols['payment_provider'])) {
                $sets[] = 'payment_provider = ?';
                $params[] = 'Xendit';
                $types .= 's';
            }
            if (isset($cols['xendit_payment_request_id']) && !empty($outcome['payment_request_id'])) {
                $sets[] = 'xendit_payment_request_id = ?';
                $params[] = $outcome['payment_request_id'];
                $types .= 's';
            }
            if (isset($cols['xendit_payment_id']) && !empty($outcome['payment_id'])) {
                $sets[] = 'xendit_payment_id = ?';
                $params[] = $outcome['payment_id'];
                $types .= 's';
            }
            if (isset($cols['xendit_capture_id']) && !empty($outcome['capture_id'])) {
                $sets[] = 'xendit_capture_id = ?';
                $params[] = $outcome['capture_id'];
                $types .= 's';
            }
            if (isset($cols['xendit_channel_code']) && !empty($outcome['channel_code'])) {
                $sets[] = 'xendit_channel_code = ?';
                $params[] = $outcome['channel_code'];
                $types .= 's';
            }
            if (isset($cols['xendit_status']) && !empty($outcome['xendit_status'])) {
                $sets[] = 'xendit_status = ?';
                $params[] = strtoupper((string)$outcome['xendit_status']);
                $types .= 's';
            }

            $params[] = $txnId;
            $types .= 'i';

            $sql = 'UPDATE transactions SET ' . implode(', ', $sets) . ' WHERE id = ?';
            $upd = $conn->prepare($sql);
            if (!$upd) {
                throw new RuntimeException('prepare update failed');
            }
            $upd->bind_param($types, ...$params);
            $upd->execute();
            $upd->close();

            // -------------------------------------------------
            // Side effects
            // -------------------------------------------------
            if ($localStatus === 'Paid') {
                if (($txn['transaction_type'] ?? '') === 'Membership') {
                    xendit_activate_membership($conn, $userId);
                }
                xendit_notify(
                    $conn,
                    $userId,
                    'system',
                    'Payment Received',
                    'We received your payment of PHP ' . number_format($expectedAmount, 2) . '. Thank you!'
                );
            } elseif (in_array($localStatus, ['Failed', 'Expired', 'Cancelled'], true)) {
                xendit_notify(
                    $conn,
                    $userId,
                    'system',
                    'Payment ' . $localStatus,
                    'Your payment of PHP ' . number_format($expectedAmount, 2) . ' was ' . strtolower($localStatus) . '. You can try again from Payments & Billing.'
                );
            }

            $conn->commit();
            $result['ok'] = true;
            $result['changed'] = true;
            $result['message'] = 'Transaction updated to ' . $localStatus . '.';
            if (xendit_debug()) {
                error_log("[Xendit][$source] txn=$txnId settled -> $localStatus");
            }
            return $result;
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $e2) {}
            error_log('[Xendit] settle failed: ' . $e->getMessage());
            $result['message'] = 'Internal error while updating transaction.';
            return $result;
        }
    }
}

if (!function_exists('xendit_settle_by_payment_request_id')) {
    /**
     * Resolve a transaction by its Xendit payment_request_id and settle it.
     */
    function xendit_settle_by_payment_request_id(mysqli $conn, string $paymentRequestId, array $outcome, string $source = 'webhook'): array
    {
        $result = ['ok' => false, 'changed' => false, 'status' => 'Pending', 'message' => ''];

        if ($paymentRequestId === '') {
            $result['message'] = 'Missing payment request id.';
            return $result;
        }

        $cols = xendit_txn_columns($conn);
        if (!isset($cols['xendit_payment_request_id'])) {
            $result['message'] = 'Payment reference columns are not available yet (run the migration).';
            return $result;
        }

        try {
            $stmt = $conn->prepare("SELECT id FROM transactions WHERE xendit_payment_request_id = ? LIMIT 1");
            if (!$stmt) {
                $result['message'] = 'Lookup failed.';
                return $result;
            }
            $stmt->bind_param('s', $paymentRequestId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                // Also accept a match on reference_id (the merchant reference).
                $stmt = $conn->prepare("SELECT id FROM transactions WHERE reference_no = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $paymentRequestId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }
            }

            if (!$row) {
                $result['message'] = 'No matching transaction for payment request.';
                return $result;
            }

            return xendit_settle_by_transaction_id($conn, (int)$row['id'], $outcome, $source);
        } catch (Throwable $e) {
            error_log('[Xendit] settle by PR failed: ' . $e->getMessage());
            $result['message'] = 'Internal error.';
            return $result;
        }
    }
}
