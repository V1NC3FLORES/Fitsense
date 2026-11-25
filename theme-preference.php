<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['theme' => 'auto']);
    exit;
}

require_once __DIR__ . '/conn.php';

try {
    $role = (string)($_SESSION['role'] ?? '');
    if (in_array($role, ['admin', 'super_admin'], true)) {
        $tableResult = $conn->query("SHOW TABLES LIKE 'system_settings'");
        if ($tableResult === false) {
            throw new RuntimeException('System settings table could not be checked.');
        }
        if ($tableResult->num_rows === 0) {
            echo json_encode(['theme' => 'auto']);
            exit;
        }

        $settingKey = 'admin_theme_' . $userId;
        $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Theme preference query could not be prepared.');
        }
        $stmt->bind_param('s', $settingKey);
    } else {
        $tableResult = $conn->query("SHOW TABLES LIKE 'user_settings'");
        if ($tableResult === false) {
            throw new RuntimeException('User settings table could not be checked.');
        }
        if ($tableResult->num_rows === 0) {
            echo json_encode(['theme' => 'auto']);
            exit;
        }

        $stmt = $conn->prepare('SELECT theme FROM user_settings WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Theme preference query could not be prepared.');
        }
        $stmt->bind_param('i', $userId);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Theme preference query failed.');
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    $theme = (string)($row['setting_value'] ?? $row['theme'] ?? 'auto');
} catch (Throwable $error) {
    error_log('Theme preference error: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Theme preference could not be loaded.']);
    exit;
}

if (!in_array($theme, ['light', 'dark', 'auto'], true)) {
    $theme = 'auto';
}

echo json_encode(['theme' => $theme]);
