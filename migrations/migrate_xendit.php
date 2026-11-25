<?php
/**
 * ============================================================
 *  migrate_xendit.php  —  idempotent DB migration runner
 * ============================================================
 *  CLI only. Adds the Xendit reference columns, extends the
 *  transactions.status enum and adds lookup indexes. It checks
 *  information_schema first, so it is safe to run more than once
 *  and never drops, truncates or renames anything.
 *
 *  Usage (from the project root):
 *      php migrations/migrate_xendit.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../conn.php';

/** @var mysqli $conn */

function col_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function index_exists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1"
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function status_enum(mysqli $conn): array
{
    $stmt = $conn->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'status'"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $values = [];
    if ($row && preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", (string)$row['COLUMN_TYPE'], $m)) {
        $values = $m[1];
    }
    return $values;
}

echo "Xendit migration starting on database: " . $conn->query("SELECT DATABASE()")->fetch_row()[0] . "\n";

// ------------------------------------------------------------
// 1. Columns
// ------------------------------------------------------------
$columns = [
    'payment_provider'          => "varchar(30) DEFAULT NULL AFTER `payment_method`",
    'xendit_payment_request_id' => "varchar(100) DEFAULT NULL AFTER `payment_provider`",
    'xendit_payment_id'         => "varchar(100) DEFAULT NULL AFTER `xendit_payment_request_id`",
    'xendit_capture_id'         => "varchar(100) DEFAULT NULL AFTER `xendit_payment_id`",
    'xendit_channel_code'       => "varchar(40) DEFAULT NULL AFTER `xendit_capture_id`",
    'xendit_status'             => "varchar(30) DEFAULT NULL AFTER `xendit_channel_code`",
    'checkout_url'              => "varchar(500) DEFAULT NULL AFTER `xendit_status`",
    'expires_at'                => "datetime DEFAULT NULL AFTER `checkout_url`",
];

foreach ($columns as $name => $definition) {
    if (col_exists($conn, 'transactions', $name)) {
        echo "  = column {$name} already exists\n";
        continue;
    }
    if ($conn->query("ALTER TABLE `transactions` ADD COLUMN `{$name}` {$definition}")) {
        echo "  + added column {$name}\n";
    } else {
        echo "  ! FAILED to add column {$name}: " . $conn->error . "\n";
    }
}

// ------------------------------------------------------------
// 2. Status enum
// ------------------------------------------------------------
$enum = status_enum($conn);
$needed = ['Failed', 'Expired'];
$missing = array_diff($needed, $enum);
if (empty($missing)) {
    echo "  = status enum already supports Failed/Expired\n";
} else {
    $sql = "ALTER TABLE `transactions`
            MODIFY COLUMN `status` enum('Paid','Pending','Cancelled','Failed','Expired') DEFAULT 'Pending'";
    if ($conn->query($sql)) {
        echo "  + extended status enum with: " . implode(', ', $missing) . "\n";
    } else {
        echo "  ! FAILED to extend status enum: " . $conn->error . "\n";
    }
}

// ------------------------------------------------------------
// 3. Indexes
// ------------------------------------------------------------
$indexes = [
    'idx_xendit_pr'      => '`xendit_payment_request_id`',
    'idx_xendit_payment' => '`xendit_payment_id`',
];
foreach ($indexes as $name => $col) {
    if (index_exists($conn, 'transactions', $name)) {
        echo "  = index {$name} already exists\n";
        continue;
    }
    if ($conn->query("ALTER TABLE `transactions` ADD KEY `{$name}` ({$col})")) {
        echo "  + added index {$name}\n";
    } else {
        echo "  ! FAILED to add index {$name}: " . $conn->error . "\n";
    }
}

echo "Done.\n";
