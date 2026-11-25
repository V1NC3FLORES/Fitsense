<?php
/**
 * Normalize the users table to one canonical account status and keep legacy compatibility.
 *
 * This cleanup is intentionally safe and idempotent:
 * - keeps the existing `status` column for any legacy reads/writes
 * - syncs it to `account_status` so both values stay aligned
 * - expands the account status enum to include Suspended
 * - adds a unique email index only when there are no duplicate emails
 *
 * Usage:
 *   php migrations/2026_11_normalize_user_account_status.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration can only be run from the command line.\n");
}

require_once __DIR__ . '/../conn.php';

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function index_exists(mysqli $conn, string $table, string $indexName): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $indexName);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function has_duplicate_values(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT 1 FROM `{$table}` WHERE `{$column}` IS NOT NULL AND TRIM(`{$column}`) <> ''
         GROUP BY `{$column}` HAVING COUNT(*) > 1 LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool)$row;
}

$usersTable = 'users';

if (!column_exists($conn, $usersTable, 'id')) {
    echo "users table not found; nothing to do.\n";
    exit(0);
}

if (column_exists($conn, $usersTable, 'account_status')) {
    $enum = $conn->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'account_status' LIMIT 1"
    );
    $type = $enum ? $enum->fetch_assoc()['COLUMN_TYPE'] ?? '' : '';
    if ($type !== '' && stripos($type, "'Suspended'") === false) {
        $conn->query("ALTER TABLE `users` MODIFY COLUMN `account_status` ENUM('Active','Inactive','Suspended') NOT NULL DEFAULT 'Active'");
        echo "Expanded users.account_status to include Suspended.\n";
    }

    $conn->query("UPDATE `users` SET `account_status` = CASE WHEN TRIM(COALESCE(`account_status`, '')) = '' THEN COALESCE(`status`, 'Active') ELSE `account_status` END WHERE TRIM(COALESCE(`account_status`, '')) = ''");
    echo "Normalized empty user account statuses.\n";
}

if (column_exists($conn, $usersTable, 'status')) {
    $conn->query("UPDATE `users` SET `status` = `account_status` WHERE TRIM(COALESCE(`account_status`, '')) <> '' AND `status` <> `account_status`");
    echo "Synced legacy users.status to account_status.\n";
}

if (column_exists($conn, $usersTable, 'email')) {
    if (!index_exists($conn, $usersTable, 'email')) {
        if (!has_duplicate_values($conn, $usersTable, 'email')) {
            $sql = "ALTER TABLE `users` ADD UNIQUE KEY `email` (`email`)";
            if ($conn->query($sql)) {
                echo "Added unique email constraint to users.\n";
            } else {
                echo "Failed to add unique email constraint: " . $conn->error . "\n";
            }
        } else {
            echo "Skipped unique email constraint because duplicate email values exist.\n";
        }
    } else {
        echo "Unique email constraint already exists.\n";
    }
}

if (column_exists($conn, $usersTable, 'username')) {
    if (!index_exists($conn, $usersTable, 'username')) {
        if (!has_duplicate_values($conn, $usersTable, 'username')) {
            $sql = "ALTER TABLE `users` ADD UNIQUE KEY `username` (`username`)";
            if ($conn->query($sql)) {
                echo "Added unique username constraint to users.\n";
            } else {
                echo "Failed to add unique username constraint: " . $conn->error . "\n";
            }
        } else {
            echo "Skipped unique username constraint because duplicate usernames exist.\n";
        }
    } else {
        echo "Unique username constraint already exists.\n";
    }
}

echo "User account cleanup complete.\n";
