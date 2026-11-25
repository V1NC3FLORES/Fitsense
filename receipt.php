<?php
session_start();
require_once 'conn.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = (int)$_SESSION['user_id'];
$txnId = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT * FROM transactions WHERE id = ? AND user_id = ? LIMIT 1"
);
$stmt->bind_param("ii", $txnId, $userId);
$stmt->execute();
$txn = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$txn) {
    die("Receipt not found");
}

// A receipt is only valid for a settled (Paid) transaction.
$isPaid = strcasecmp((string)$txn['status'], 'Paid') === 0;

$userStmt = $conn->prepare("SELECT fullname, email FROM users WHERE id = ?");
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

$reference = $txn['reference_no'] ?? ('TXN-' . $txn['id']);
$dateSource = $txn['paid_at'] ?: ($txn['created_at'] ?? null);
$methodLabel = $txn['payment_method'] ?? 'N/A';
if (!empty($txn['xendit_channel_code'])) {
    $methodLabel .= ' (Xendit)';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt <?= htmlspecialchars($reference) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f6f9; padding: 40px; }
        .receipt { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { text-align: center; border-bottom: 2px dashed #e5e7eb; padding-bottom: 20px; margin-bottom: 20px; }
        .header h1 { margin: 0; color: #005b9f; }
        .header p { color: #6b7280; margin: 6px 0 0; font-size: 13px; }
        .row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
        .row strong { color: #1f2937; }
        .total { font-size: 24px; color: #005b9f; font-weight: 800; text-align: right; padding: 20px 0; border-top: 2px solid #e5e7eb; margin-top: 20px; }
        .status-paid { color: #10b981; }
        .status-paid i { margin-left: 5px; }
        .status-other { color: #b45309; }
        .notice { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; padding: 14px 16px; border-radius: 10px; font-weight: 600; margin-bottom: 20px; }
        .print-btn { display: block; width: 100%; padding: 14px; background: #005b9f; color: white; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; margin-top: 24px; }
        .print-btn i { margin-right: 8px; }
        @media print { body { background: white; padding: 0; } .print-btn, .notice { display: none; } }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css"></head>
<body>
    <div class="receipt">
        <div class="header">
            <h1>UMak Gym: Fitsense</h1>
            <p>Payment Receipt</p>
        </div>

        <?php if (!$isPaid): ?>
            <div class="notice">
                This transaction is not settled yet (status: <?= htmlspecialchars($txn['status']) ?>). No paid receipt is available.
            </div>
        <?php endif; ?>

        <div class="row"><span>Reference</span><strong><?= htmlspecialchars($reference) ?></strong></div>
        <div class="row"><span>Date</span><strong><?= $dateSource ? date('F j, Y', strtotime($dateSource)) : '—' ?></strong></div>
        <div class="row"><span>Customer</span><strong><?= htmlspecialchars($user['fullname'] ?? '') ?></strong></div>
        <div class="row"><span>Email</span><strong><?= htmlspecialchars($user['email'] ?? '') ?></strong></div>
        <div class="row"><span>Description</span><strong><?= htmlspecialchars($txn['description']) ?></strong></div>
        <div class="row"><span>Type</span><strong><?= htmlspecialchars($txn['transaction_type'] ?? '—') ?></strong></div>
        <div class="row"><span>Payment Method</span><strong><?= htmlspecialchars($methodLabel) ?></strong></div>
        <div class="row">
            <span>Status</span>
            <strong class="<?= $isPaid ? 'status-paid' : 'status-other' ?>">
                <?php if ($isPaid): ?>
                    PAID <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                <?php else: ?>
                    <?= strtoupper(htmlspecialchars($txn['status'])) ?>
                <?php endif; ?>
            </strong>
        </div>

        <div class="total">₱<?= number_format($txn['amount'], 2) ?></div>

        <button class="print-btn" onclick="window.print()"><i class="bi bi-printer-fill" aria-hidden="true"></i>Print Receipt</button>
    </div>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>
