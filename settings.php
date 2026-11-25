<?php
session_start();
include 'conn.php';
require_once __DIR__ . '/config/session_security.php';

// ============================================
// AUTHENTICATION CHECK
// ============================================
$userSession = app_require_session_user($conn, ['user', 'admin', 'super_admin'], 'login.php');
$userId = (int)$_SESSION['user_id'];

// ============================================
// SAFE QUERY HELPERS
// ============================================
function safeQuery($conn, $sql, $types = '', $params = []) {
    try {
        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            if ($stmt === false) return null;
            if (!empty($types)) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            return $stmt->get_result();
        }
        return $conn->query($sql);
    } catch (mysqli_sql_exception $e) {
        error_log("SafeQuery Error: " . $e->getMessage());
        return null;
    }
}

function tableExists($conn, $tableName) {
    $tableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    return $result && $result->num_rows > 0;
}

function getTableColumns($conn, $tableName) {
    $columns = [];
    $tableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW COLUMNS FROM `$tableName`");
    if ($result) {
        while ($row = $result->fetch_assoc()) $columns[] = $row['Field'];
    }
    return $columns;
}

// ============================================
// ENSURE USER SETTINGS TABLE EXISTS
// ============================================
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS `user_settings` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `email_notifications` TINYINT(1) DEFAULT 1,
            `push_notifications` TINYINT(1) DEFAULT 1,
            `sms_notifications` TINYINT(1) DEFAULT 0,
            `class_reminders` TINYINT(1) DEFAULT 1,
            `payment_reminders` TINYINT(1) DEFAULT 1,
            `workout_reminders` TINYINT(1) DEFAULT 1,
            `marketing_emails` TINYINT(1) DEFAULT 0,
            `profile_visibility` ENUM('Public','Friends','Private') DEFAULT 'Friends',
            `show_in_directory` TINYINT(1) DEFAULT 1,
            `allow_messages` TINYINT(1) DEFAULT 1,
            `language` VARCHAR(10) DEFAULT 'en',
            `timezone` VARCHAR(50) DEFAULT 'Asia/Manila',
            `theme` ENUM('light','dark','auto') DEFAULT 'auto',
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log("Settings table creation error: " . $e->getMessage());
}

$userColumns = getTableColumns($conn, 'users');
$hasColumn = function($col) use ($userColumns) {
    return in_array($col, $userColumns);
};

// ============================================
// HANDLE SETTINGS UPDATE
// ============================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $message = 'This form has expired or is invalid. Please refresh the page and try again.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'];

        // ============================================
        // UPDATE NOTIFICATION SETTINGS
        // ============================================
        if ($action === 'update_notifications') {
        $email = isset($_POST['email_notifications']) ? 1 : 0;
        $push = isset($_POST['push_notifications']) ? 1 : 0;
        $sms = isset($_POST['sms_notifications']) ? 1 : 0;
        $classRem = isset($_POST['class_reminders']) ? 1 : 0;
        $payRem = isset($_POST['payment_reminders']) ? 1 : 0;
        $workRem = isset($_POST['workout_reminders']) ? 1 : 0;
        $marketing = isset($_POST['marketing_emails']) ? 1 : 0;

        // Check if settings row exists
        $result = safeQuery($conn,
            "SELECT id FROM user_settings WHERE user_id = ? LIMIT 1",
            "i", [$userId]
        );

        if ($result && $result->num_rows > 0) {
            $stmt = $conn->prepare(
                "UPDATE user_settings SET 
                    email_notifications = ?, push_notifications = ?, sms_notifications = ?,
                    class_reminders = ?, payment_reminders = ?, workout_reminders = ?,
                    marketing_emails = ?
                 WHERE user_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("iiiiiiii",
                    $email, $push, $sms, $classRem, $payRem, $workRem, $marketing, $userId
                );
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO user_settings 
                 (user_id, email_notifications, push_notifications, sms_notifications,
                  class_reminders, payment_reminders, workout_reminders, marketing_emails) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                $stmt->bind_param("iiiiiiii",
                    $userId, $email, $push, $sms, $classRem, $payRem, $workRem, $marketing
                );
                $stmt->execute();
                $stmt->close();
            }
        }
        $message = 'Notification preferences updated!';
        $messageType = 'success';
    }

    // ============================================
    // UPDATE PRIVACY SETTINGS
    // ============================================
    elseif ($action === 'update_privacy') {
        $visibility = $_POST['profile_visibility'] ?? 'Friends';
        $directory = isset($_POST['show_in_directory']) ? 1 : 0;
        $allowMsgs = isset($_POST['allow_messages']) ? 1 : 0;

        $stmt = $conn->prepare(
            "UPDATE user_settings SET 
                profile_visibility = ?, show_in_directory = ?, allow_messages = ?
             WHERE user_id = ?"
        );
        if ($stmt) {
            $stmt->bind_param("siii", $visibility, $directory, $allowMsgs, $userId);
            $stmt->execute();
            $stmt->close();
        }
        $message = 'Privacy settings updated!';
        $messageType = 'success';
    }

    // ============================================
    // UPDATE APPEARANCE
    // ============================================
    elseif ($action === 'update_appearance') {
        $theme = $_POST['theme'] ?? '';
        $language = $_POST['language'] ?? 'en';
        $timezone = $_POST['timezone'] ?? 'Asia/Manila';

        if (!in_array($theme, ['light', 'dark', 'auto'], true)) {
            $message = 'Please choose a valid theme.';
            $messageType = 'error';
        } else {
            $stmt = $conn->prepare(
                "UPDATE user_settings SET theme = ?, language = ?, timezone = ? WHERE user_id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("sssi", $theme, $language, $timezone, $userId);
                if ($stmt->execute()) {
                    $settings['theme'] = $theme;
                    $message = 'Appearance settings updated!';
                    $messageType = 'success';
                } else {
                    $message = 'Appearance settings could not be saved.';
                    $messageType = 'error';
                }
                $stmt->close();
            } else {
                $message = 'Appearance settings could not be saved.';
                $messageType = 'error';
            }
        }
    }

    // ============================================
    // CHANGE PASSWORD
    // ============================================
    elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $errors = [];

        if (empty($current) || empty($new) || empty($confirm)) {
            $errors[] = 'All password fields are required.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            $errors[] = 'Password must contain uppercase, lowercase, and numbers.';
        }

        if (empty($errors)) {
            $result = safeQuery($conn, "SELECT password FROM users WHERE id = ?", "i", [$userId]);
            if ($result && $row = $result->fetch_assoc()) {
                if (!password_verify($current, $row['password'])) {
                    $errors[] = 'Current password is incorrect.';
                }
            } else {
                $errors[] = 'User not found.';
            }
        }

        if (empty($errors)) {
            $newHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $newHash, $userId);
                if ($stmt->execute()) {
                    $message = 'Password changed successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to change password.';
                    $messageType = 'error';
                }
                $stmt->close();
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
    }

    // ============================================
    // LOGOUT ALL SESSIONS
    // ============================================
        elseif ($action === 'logout_sessions') {
            // In a real app, you'd destroy session tokens for this user
            $message = 'All other sessions have been logged out.';
            $messageType = 'success';
        }
    }
}

// ============================================
// FETCH USER DATA
// ============================================
$selectFields = ['id', 'username'];
foreach (['fullname', 'email', 'profile_image', 'status'] as $f) {
    if ($hasColumn($f)) $selectFields[] = $f;
}

$userData = null;
$sql = "SELECT " . implode(', ', $selectFields) . " FROM users WHERE id = ? LIMIT 1";
$stmtUser = $conn->prepare($sql);
if ($stmtUser) {
    $stmtUser->bind_param("i", $userId);
    $stmtUser->execute();
    $userData = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();
}

$displayName = $userData['fullname'] ?? $_SESSION['fullname'] ?? $userData['username'] ?? 'User';
$firstName = explode(' ', trim($displayName))[0];
$userEmail = $userData['email'] ?? $_SESSION['email'] ?? '';
$userInitial = strtoupper(substr($displayName, 0, 1));
$userAvatar = $userData['profile_image'] ?? '';

// ============================================
// FETCH USER SETTINGS
// ============================================
$settings = [
    'email_notifications' => 1,
    'push_notifications' => 1,
    'sms_notifications' => 0,
    'class_reminders' => 1,
    'payment_reminders' => 1,
    'workout_reminders' => 1,
    'marketing_emails' => 0,
    'profile_visibility' => 'Friends',
    'show_in_directory' => 1,
    'allow_messages' => 1,
    'language' => 'en',
    'timezone' => 'Asia/Manila',
    'theme' => 'auto',
];

$result = safeQuery($conn,
    "SELECT * FROM user_settings WHERE user_id = ? LIMIT 1",
    "i", [$userId]
);
if ($result && $row = $result->fetch_assoc()) {
    foreach ($row as $key => $val) {
        if (array_key_exists($key, $settings)) {
            $settings[$key] = $val;
        }
    }
} else {
    // Create default row
    safeQuery($conn,
        "INSERT INTO user_settings (user_id, theme) VALUES (?, 'auto')",
        "i", [$userId]
    );
}

// ============================================
// FETCH UNREAD NOTIFICATIONS
// ============================================
$unreadNotifCount = 0;
if (tableExists($conn, 'notifications')) {
    $result = safeQuery($conn,
        "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $unreadNotifCount = (int)$row['total'];
    }
}

// ============================================
// ACTIVE TAB
// ============================================
$activeTab = $_GET['tab'] ?? 'account';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - UMak Gym: Fitsense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --umak-blue: #005b9f;
            --umak-blue-dark: #00467a;
            --umak-yellow: #ffce00;
            --umak-green: #2e7d32;
            --umak-red: #d32f2f;
            --umak-orange: #f59e0b;
            --sidebar-width: 260px;
            --bg: #f4f6f9;
            --card-bg: #ffffff;
            --text: #333;
            --text-muted: #6b7280;
            --border: #e5e7eb;
        }

        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { margin: 0; background-color: var(--bg); color: var(--text); }

        /* ============================================
           SIDEBAR
           ============================================ */
        .sidebar {
            position: fixed; left: 0; top: 0;
            width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, #005b9f 0%, #00467a 100%);
            color: white; overflow-y: auto; z-index: 1000;
            display: flex; flex-direction: column;
            box-shadow: 4px 0 15px rgba(0,0,0,0.08);
        }

        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }

        .sidebar-brand {
            padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex; align-items: center; gap: 12px; flex-shrink: 0;
        }

        .sidebar-brand .logo {
            width: 40px; height: 40px; background: var(--umak-yellow);
            border-radius: 10px; display: flex; align-items: center;
            justify-content: center; font-weight: 900; color: var(--umak-blue);
            font-size: 18px; flex-shrink: 0;
        }

        .sidebar-brand .brand-info h3 { margin: 0; font-size: 16px; font-weight: 800; letter-spacing: 1px; }
        .sidebar-brand .brand-info p { margin: 2px 0 0; font-size: 11px; opacity: 0.7; text-transform: uppercase; letter-spacing: 1px; }

        .sidebar-nav { flex: 1; padding: 15px 10px; overflow-y: auto; }

        .nav-section-title {
            font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px;
            color: rgba(255,255,255,0.4); padding: 15px 15px 8px; font-weight: 700;
        }

        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 15px; color: rgba(255,255,255,0.85);
            text-decoration: none; border-radius: 8px; margin-bottom: 2px;
            font-size: 14px; font-weight: 500; transition: all 0.2s ease;
        }

        .nav-item:hover { background: rgba(255,255,255,0.1); color: white; transform: translateX(3px); }

        .nav-item.active {
            background: rgba(255,206,0,0.15); color: var(--umak-yellow);
            font-weight: 600; border-left: 3px solid var(--umak-yellow);
            padding-left: 12px;
        }

        .nav-item i { font-size: 18px; width: 22px; text-align: center; flex-shrink: 0; }

        .nav-item .badge-count {
            margin-left: auto; background: var(--umak-red); color: white;
            font-size: 10px; font-weight: 700; padding: 2px 7px;
            border-radius: 10px; min-width: 18px; text-align: center;
        }

        .nav-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 12px 15px; }
        .nav-logout { color: #ff8a80 !important; }
        .nav-logout:hover { background: rgba(211,47,47,0.2) !important; color: #ff5252 !important; }

        /* ============================================
           MAIN WRAPPER & HEADER
           ============================================ */
        .main-wrapper { margin-left: var(--sidebar-width); min-height: 100vh; padding-bottom: 40px; }

        .top-header {
            background: white; padding: 15px 30px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04); position: sticky; top: 0;
            z-index: 100; border-bottom: 3px solid var(--umak-yellow);
        }

        .header-left { display: flex; align-items: center; gap: 15px; }
        .sidebar-toggle { background: none; border: none; font-size: 22px; color: var(--umak-blue); cursor: pointer; display: none; padding: 5px; }
        .header-left h2 { margin: 0; font-size: 20px; color: var(--umak-blue); font-weight: 700; }
        .header-left .subtitle { font-size: 12px; color: var(--text-muted); margin: 0; }
        .header-right { display: flex; align-items: center; gap: 12px; }

        .btn-chat-open {
            background: linear-gradient(135deg, #005b9f 0%, #0084d1 100%);
            color: white; border: none; padding: 10px 18px; border-radius: 25px;
            font-size: 14px; font-weight: 600; cursor: pointer;
            display: flex; align-items: center; gap: 8px;
            transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(0,91,159,0.3);
        }

        .btn-chat-open:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,91,159,0.4); }

        .btn-chat-open .chat-dot { width: 8px; height: 8px; background: #4ade80; border-radius: 50%; animation: pulse 2s infinite; }

        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }

        .notif-btn {
            background: #f4f6f9; border: none; width: 42px; height: 42px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; position: relative; font-size: 18px;
            color: var(--umak-blue); transition: all 0.2s;
        }

        .notif-btn:hover { background: #e5eef5; }

        .notif-badge {
            position: absolute; top: 0; right: 0; background: var(--umak-red);
            color: white; font-size: 10px; font-weight: bold; border-radius: 50%;
            width: 18px; height: 18px; display: flex; align-items: center;
            justify-content: center; border: 2px solid white;
        }

        .avatar-btn { background: none; border: none; padding: 0; cursor: pointer; }

        .avatar {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 16px; overflow: hidden;
        }

        .avatar img { width: 100%; height: 100%; object-fit: cover; }

        /* ============================================
           PAGE CONTENT
           ============================================ */
        .container { max-width: 1100px; margin: 30px auto; padding: 0 25px; }

        .page-title {
            font-size: 26px; font-weight: 800; color: var(--umak-blue);
            margin: 0 0 6px; display: flex; align-items: center; gap: 12px;
        }

        .page-title i {
            font-size: 28px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle { color: var(--text-muted); font-size: 14px; margin-bottom: 28px; }

        /* ============================================
           ALERTS
           ============================================ */
        .alert {
            padding: 14px 20px; border-radius: 12px; font-size: 14px;
            font-weight: 500; margin-bottom: 20px; display: flex;
            align-items: center; gap: 12px; animation: slideDown 0.4s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border-left: 4px solid var(--umak-green); }
        .alert-error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border-left: 4px solid var(--umak-red); }

        /* ============================================
           SETTINGS LAYOUT
           ============================================ */
        .settings-layout {
            display: grid;
            grid-template-columns: 260px 1fr;
            gap: 25px;
            align-items: start;
        }

        /* Sidebar Tabs */
        .settings-nav {
            background: white;
            border-radius: 20px;
            padding: 15px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            position: sticky;
            top: 100px;
        }

        .settings-nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 10px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
            margin-bottom: 2px;
        }

        .settings-nav-item:hover {
            background: #f3f4f6;
            color: var(--umak-blue);
            transform: translateX(3px);
        }

        .settings-nav-item.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 12px rgba(0,91,159,0.25);
        }

        .settings-nav-item i {
            font-size: 18px;
            width: 22px;
            text-align: center;
        }

        /* Settings Panels */
        .settings-panel {
            background: white;
            border-radius: 20px;
            padding: 30px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border);
            gap: 12px;
            flex-wrap: wrap;
        }

        .panel-header h3 {
            margin: 0 0 6px;
            font-size: 20px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-header p {
            margin: 0;
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* ============================================
           SETTING ROWS
           ============================================ */
        .setting-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 24px;
        }

        .setting-group:last-child { margin-bottom: 0; }

        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 18px;
            border-radius: 12px;
            background: #f9fafb;
            border: 1px solid var(--border);
            gap: 20px;
            transition: all 0.2s ease;
        }

        .setting-item:hover {
            background: #ffffff;
            border-color: rgba(0,91,159,0.15);
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }

        .setting-info { flex: 1; min-width: 0; }

        .setting-label {
            font-size: 14.5px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .setting-label i {
            color: var(--umak-blue);
            font-size: 16px;
        }

        .setting-description {
            font-size: 12.5px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* ============================================
           TOGGLE SWITCH
           ============================================ */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 54px;
            height: 30px;
            flex-shrink: 0;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #d1d5db;
            border-radius: 30px;
            transition: 0.3s;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            border-radius: 50%;
            transition: 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }

        .toggle-switch input:checked + .toggle-slider {
            background: linear-gradient(135deg, #005b9f, #0084d1);
        }

        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        .toggle-switch input:focus + .toggle-slider {
            box-shadow: 0 0 0 4px rgba(0,91,159,0.15);
        }

        /* ============================================
           FORM CONTROLS
           ============================================ */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 18px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-control {
            padding: 12px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            font-family: inherit;
            width: 100%;
            color: var(--text);
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--umak-blue);
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        select.form-control { cursor: pointer; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 15px;
            pointer-events: none;
        }

        .input-with-icon .form-control { padding-left: 42px; }
        .input-with-icon.has-toggle .form-control { padding-right: 42px; }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 16px;
            padding: 4px;
            transition: color 0.2s;
        }

        .password-toggle:hover { color: var(--umak-blue); }

        /* ============================================
           BUTTONS
           ============================================ */
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s ease;
            text-decoration: none;
            font-family: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 12px rgba(0,91,159,0.25);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,91,159,0.35);
        }

        .btn-outline {
            background: white;
            color: var(--umak-blue);
            border: 2px solid var(--umak-blue);
        }

        .btn-outline:hover {
            background: var(--umak-blue);
            color: white;
        }

        .btn-yellow {
            background: linear-gradient(135deg, #ffce00, #ffb800);
            color: #333;
            box-shadow: 0 4px 12px rgba(255,206,0,0.3);
        }

        .btn-yellow:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255,206,0,0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, #d32f2f, #ef4444);
            color: white;
            box-shadow: 0 4px 12px rgba(211,47,47,0.25);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(211,47,47,0.35);
        }

        .btn i { font-size: 15px; }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        /* ============================================
           DANGER ZONE
           ============================================ */
        .danger-zone {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border-radius: 16px;
            padding: 22px 26px;
            border: 2px dashed rgba(211,47,47,0.3);
            margin-top: 24px;
        }

        .danger-zone-header {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 800;
            color: #991b1b;
            margin-bottom: 10px;
        }

        .danger-zone-header i { font-size: 20px; }

        .danger-zone p {
            margin: 0 0 16px;
            font-size: 13px;
            color: #b91c1c;
            line-height: 1.6;
        }

        .danger-zone .btn { margin-top: 4px; }

        /* ============================================
           RADIO CARDS (Profile Visibility)
           ============================================ */
        .radio-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .radio-card {
            padding: 18px 16px;
            border-radius: 12px;
            background: #f9fafb;
            border: 2px solid var(--border);
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }

        .radio-card:hover {
            border-color: rgba(0,91,159,0.3);
            background: #f0f7fc;
        }

        .radio-card input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .radio-card.selected,
        .radio-card:has(input:checked) {
            border-color: var(--umak-blue);
            background: linear-gradient(135deg, rgba(0,91,159,0.06), rgba(0,132,209,0.08));
            box-shadow: 0 4px 12px rgba(0,91,159,0.15);
        }

        .radio-card-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(0,91,159,0.1);
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 10px;
        }

        .radio-card.selected .radio-card-icon {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
        }

        .radio-card-title {
            font-size: 14px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 4px;
        }

        .radio-card-desc {
            font-size: 12px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
            .settings-layout { grid-template-columns: 1fr; }
            .settings-nav {
                position: static;
                display: flex;
                gap: 6px;
                overflow-x: auto;
                padding: 10px;
            }
            .settings-nav::-webkit-scrollbar { height: 6px; }
            .settings-nav::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
            .settings-nav-item {
                flex-shrink: 0;
                margin-bottom: 0;
                white-space: nowrap;
            }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .btn-chat-open span.label { display: none; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .settings-panel { padding: 20px 18px; }
            .panel-header h3 { font-size: 17px; }
            .setting-item { flex-direction: column; align-items: flex-start; gap: 12px; }
            .form-row { grid-template-columns: 1fr; }
            .form-actions .btn { flex: 1; }
        }

        @media (max-width: 576px) {
            .radio-cards { grid-template-columns: 1fr; }
            .settings-nav-item span { font-size: 12px; }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css"><link rel="stylesheet" href="/PBL/css/user-content-icons.css"></head>
<body>

<!-- ============================================
     SIDEBAR
     ============================================ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="logo">FS</div>
        <div class="brand-info">
            <h3>FITSENSE</h3>
            <p>Student Portal</p>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Main</div>
        <a href="user-dashboard.php" class="nav-item"><i class="bi bi-house-door-fill"></i><span>Dashboard</span></a>
        <a href="my-membership.php" class="nav-item"><i class="bi bi-ticket-perforated-fill"></i><span>My Membership</span></a>
        <a href="attendance.php" class="nav-item"><i class="bi bi-bar-chart-fill"></i><span>Attendance</span></a>

        <div class="nav-section-title">Fitness</div>
        <a href="workout-plan.php" class="nav-item"><i class="bi bi-heart-pulse-fill"></i><span>Workout Plan</span></a>
        <a href="nutrition-plan.php" class="nav-item"><i class="bi bi-egg-fried"></i><span>Nutrition Plan</span></a>
        <a href="my-progress.php" class="nav-item"><i class="bi bi-graph-up-arrow"></i><span>My Progress</span></a>

        <div class="nav-section-title">Services</div>
        <a href="classes.php" class="nav-item"><i class="bi bi-calendar-event-fill"></i><span>Classes & Booking</span></a>
        <a href="payments.php" class="nav-item"><i class="bi bi-credit-card-fill"></i><span>Payments & Billing</span></a>
        <a href="notifications.php" class="nav-item">
            <i class="bi bi-bell-fill"></i><span>Notifications</span>
            <?php if ($unreadNotifCount > 0): ?>
                <span class="badge-count"><?= $unreadNotifCount ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section-title">Account</div>
        <a href="my-profile.php" class="nav-item"><i class="bi bi-person-circle"></i><span>My Profile</span></a>
        <a href="help.php" class="nav-item"><i class="bi bi-life-preserver"></i><span>Help & Support</span></a>
        <a href="settings.php" class="nav-item active"><i class="bi bi-gear-fill"></i><span>Settings</span></a>
        <div class="nav-divider"></div>
        <a href="logout.php" class="nav-item nav-logout"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </nav>
</aside>

<!-- ============================================
     MAIN WRAPPER
     ============================================ -->
<div class="main-wrapper">

    <header class="top-header">
        <div class="header-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
            <div>
                <h2>Settings</h2>
                <p class="subtitle">Manage your account preferences</p>
            </div>
        </div>

        <div class="header-right">
            <button class="btn-chat-open" onclick="openChatPanel()">
        <span class="chat-dot"></span>
        <i class="bi bi-chat-dots-fill"></i>
        <span class="label">Chat</span>
    </button>
            <button class="notif-btn" onclick="window.location.href='notifications.php'">
                <i class="bi bi-bell-fill"></i>
                <?php if ($unreadNotifCount > 0): ?>
                    <span class="notif-badge"><?= $unreadNotifCount ?></span>
                <?php endif; ?>
            </button>
            <button class="avatar-btn" onclick="window.location.href='my-profile.php'">
                <div class="avatar">
                    <?php if (!empty($userAvatar)): ?>
                        <img src="<?= htmlspecialchars($userAvatar) ?>" alt="">
                    <?php else: ?>
                        <?= htmlspecialchars($userInitial) ?>
                    <?php endif; ?>
                </div>
            </button>
        </div>
    </header>

    <main class="container">

        <h1 class="page-title"><i class="bi bi-gear-fill"></i> Settings</h1>
        <p class="page-subtitle">Customize your FitSense experience. Changes are saved automatically.</p>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>" style="font-size: 20px;"></i>
                <div><?= $message ?></div>
            </div>
        <?php endif; ?>

        <div class="settings-layout">

            <!-- ============================================
                 SETTINGS SIDEBAR NAV
                 ============================================ -->
            <nav class="settings-nav">
                <a href="?tab=account" class="settings-nav-item <?= $activeTab === 'account' ? 'active' : '' ?>">
                    <i class="bi bi-person-fill"></i> <span>Account</span>
                </a>
                <a href="?tab=notifications" class="settings-nav-item <?= $activeTab === 'notifications' ? 'active' : '' ?>">
                    <i class="bi bi-bell-fill"></i> <span>Notifications</span>
                </a>
                <a href="?tab=privacy" class="settings-nav-item <?= $activeTab === 'privacy' ? 'active' : '' ?>">
                    <i class="bi bi-shield-lock-fill"></i> <span>Privacy</span>
                </a>
                <a href="?tab=appearance" class="settings-nav-item <?= $activeTab === 'appearance' ? 'active' : '' ?>">
                    <i class="bi bi-palette-fill"></i> <span>Appearance</span>
                </a>
                <a href="?tab=security" class="settings-nav-item <?= $activeTab === 'security' ? 'active' : '' ?>">
                    <i class="bi bi-shield-check"></i> <span>Security</span>
                </a>
            </nav>

            <!-- ============================================
                 ACCOUNT TAB
                 ============================================ -->
            <?php if ($activeTab === 'account'): ?>
            <div class="settings-panel">
                <div class="panel-header">
                    <div>
                        <h3><i class="bi bi-person-fill"></i> Account Settings</h3>
                        <p>View your account information and manage related preferences.</p>
                    </div>
                </div>

                <div class="setting-group">
                    <div class="setting-item">
                        <div class="setting-info">
                            <div class="setting-label"><i class="bi bi-person-badge-fill"></i> Username</div>
                            <div class="setting-description">Your unique login identifier. Contact support to change.</div>
                        </div>
                        <div style="font-weight: 700; color: var(--umak-blue); font-size: 14px;">
                            @<?= htmlspecialchars($userData['username'] ?? 'user') ?>
                        </div>
                    </div>

                    <div class="setting-item">
                        <div class="setting-info">
                            <div class="setting-label"><i class="bi bi-envelope-fill"></i> Email Address</div>
                            <div class="setting-description"><?= htmlspecialchars($userEmail ?: 'Not set') ?></div>
                        </div>
                        <a href="my-profile.php" class="btn btn-outline" style="padding: 8px 16px; font-size: 12px;">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                    </div>

                    <div class="setting-item">
                        <div class="setting-info">
                            <div class="setting-label"><i class="bi bi-person-circle"></i> Profile Information</div>
                            <div class="setting-description">Name, phone, address, emergency contacts, and bio.</div>
                        </div>
                        <a href="my-profile.php" class="btn btn-outline" style="padding: 8px 16px; font-size: 12px;">
                            <i class="bi bi-arrow-right"></i> Manage
                        </a>
                    </div>

                    <div class="setting-item">
                        <div class="setting-info">
                            <div class="setting-label"><i class="bi bi-ticket-perforated-fill"></i> Membership</div>
                            <div class="setting-description">View plan, expiry, and renewal options.</div>
                        </div>
                        <a href="my-membership.php" class="btn btn-outline" style="padding: 8px 16px; font-size: 12px;">
                            <i class="bi bi-arrow-right"></i> View
                        </a>
                    </div>
                </div>

                <div class="danger-zone">
                    <div class="danger-zone-header">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        Deactivate Account
                    </div>
                    <p>
                        Deactivating your account will hide your profile and prevent login. Your data is preserved and you can reactivate by contacting the gym office.
                    </p>
                    <button type="button" class="btn btn-danger" onclick="alert('Please contact the gym office to deactivate your account.');">
                        <i class="bi bi-power"></i> Deactivate Account
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============================================
                 NOTIFICATIONS TAB
                 ============================================ -->
            <?php if ($activeTab === 'notifications'): ?>
            <div class="settings-panel">
                <div class="panel-header">
                    <div>
                        <h3><i class="bi bi-bell-fill"></i> Notification Preferences</h3>
                        <p>Choose how you want to be notified about gym activities.</p>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_notifications">
                    <?php echo app_csrf_field(); ?>

                    <div class="setting-group">
                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="setting-label"><i class="bi bi-envelope-fill"></i> Email Notifications</div>
                                <div class="setting-description">Receive important updates via email.</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="email_notifications" <?= $settings['email_notifications'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="setting-label"><i class="bi bi-phone-fill"></i> Push Notifications</div>
                                <div class="setting-description">Get real-time alerts in your browser.</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="push_notifications" <?= $settings['push_notifications'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="setting-label"><i class="bi bi-chat-dots-fill"></i> SMS Notifications</div>
                                <div class="setting-description">Receive text messages for critical alerts.</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="sms_notifications" <?= $settings['sms_notifications'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <h4 style="font-size: 13px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin: 30px 0 14px;">
                        <i class="bi bi-bell-fill"></i> Notification Types
                    </h4>

                    <div class="setting-group">
                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="setting-label"><i class="bi bi-calendar-event-fill"></i> Class Reminders</div>
                                <div class="setting-description">Reminders before your booked classes.</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="class_reminders" <?= $settings['class_reminders'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="setting-label"><i class="bi bi-credit-card-fill"></i> Payment Reminders</div>
                                <div class="setting-description">Get alerted about upcoming payments.</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="payment_reminders" <?= $settings['payment_reminders'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="setting-label"><i class="bi bi-heart-pulse-fill"></i> Workout Reminders</div>
                                <div class="setting-description">Daily reminders to complete your workout.</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="workout_reminders" <?= $settings['workout_reminders'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="setting-label"><i class="bi bi-megaphone-fill"></i> Marketing & Promos</div>
                                <div class="setting-description">Occasional news, tips, and special offers.</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="marketing_emails" <?= $settings['marketing_emails'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle-fill"></i> Save Preferences
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- ============================================
                 PRIVACY TAB
                 ============================================ -->
            <?php if ($activeTab === 'privacy'): ?>
            <div class="settings-panel">
                <div class="panel-header">
                    <div>
                        <h3><i class="bi bi-shield-lock-fill"></i> Privacy Settings</h3>
                        <p>Control who can see your profile and interact with you.</p>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_privacy">
                    <?php echo app_csrf_field(); ?>

                    <div class="form-group">
                        <label class="form-label"><i class="bi bi-eye-fill"></i> Profile Visibility</label>
                        <div class="radio-cards">
                            <label class="radio-card <?= $settings['profile_visibility'] === 'Public' ? 'selected' : '' ?>">
                                <input type="radio" name="profile_visibility" value="Public" 
                                       <?= $settings['profile_visibility'] === 'Public' ? 'checked' : '' ?>>
                                <div class="radio-card-icon"><i class="bi bi-globe2"></i></div>
                                <div class="radio-card-title">Public</div>
                                <div class="radio-card-desc">Everyone can see your profile.</div>
                            </label>
                            <label class="radio-card <?= $settings['profile_visibility'] === 'Friends' ? 'selected' : '' ?>">
                                <input type="radio" name="profile_visibility" value="Friends"
                                       <?= $settings['profile_visibility'] === 'Friends' ? 'checked' : '' ?>>
                                <div class="radio-card-icon"><i class="bi bi-people-fill"></i></div>
                                <div class="radio-card-title">Friends Only</div>
                                <div class="radio-card-desc">Only your gym friends can see you.</div>
                            </label>
                            <label class="radio-card <?= $settings['profile_visibility'] === 'Private' ? 'selected' : '' ?>">
                                <input type="radio" name="profile_visibility" value="Private"
                                       <?= $settings['profile_visibility'] === 'Private' ? 'checked' : '' ?>>
                                <div class="radio-card-icon"><i class="bi bi-lock-fill"></i></div>
                                <div class="radio-card-title">Private</div>
                                <div class="radio-card-desc">No one can see your profile.</div>
                            </label>
                        </div>
                    </div>

                    <div class="setting-group" style="margin-top: 20px;">
                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="setting-label"><i class="bi bi-people-fill"></i> Show in Gym Directory</div>
                                <div class="setting-description">Appear in the member directory so others can find you.</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="show_in_directory" <?= $settings['show_in_directory'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="setting-item">
                            <div class="setting-info">
                                <div class="setting-label"><i class="bi bi-chat-dots-fill"></i> Allow Direct Messages</div>
                                <div class="setting-description">Let other members send you direct messages.</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="allow_messages" <?= $settings['allow_messages'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle-fill"></i> Save Privacy Settings
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- ============================================
                 APPEARANCE TAB
                 ============================================ -->
            <?php if ($activeTab === 'appearance'): ?>
            <div class="settings-panel">
                <div class="panel-header">
                    <div>
                        <h3><i class="bi bi-palette-fill"></i> Appearance & Preferences</h3>
                        <p>Customize how FitSense looks and behaves for you.</p>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_appearance">
                    <?php echo app_csrf_field(); ?>

                    <div class="form-group">
                        <label class="form-label">Theme</label>
                        <div class="theme-picker">
                            <input type="hidden" name="theme" data-theme-input
                                   value="<?= htmlspecialchars((string)$settings['theme'], ENT_QUOTES, 'UTF-8') ?>">
                            <button type="button" class="theme-choice" data-theme-choice="light"
                                    aria-pressed="<?= $settings['theme'] === 'light' ? 'true' : 'false' ?>">Light mode</button>
                            <button type="button" class="theme-choice" data-theme-choice="dark"
                                    aria-pressed="<?= $settings['theme'] === 'dark' ? 'true' : 'false' ?>">Dark mode</button>
                            <button type="button" class="theme-choice" data-theme-choice="auto"
                                    aria-pressed="<?= $settings['theme'] === 'auto' ? 'true' : 'false' ?>">Default</button>
                        </div>
                        <p class="form-hint">Default follows your device’s light or dark preference.</p>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-translate"></i> Language</label>
                            <select name="language" class="form-control">
                                <option value="en" <?= $settings['language'] === 'en' ? 'selected' : '' ?>>English</option>
                                <option value="fil" <?= $settings['language'] === 'fil' ? 'selected' : '' ?>>Filipino</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-clock-fill"></i> Timezone</label>
                            <select name="timezone" class="form-control">
                                <option value="Asia/Manila" <?= $settings['timezone'] === 'Asia/Manila' ? 'selected' : '' ?>>Asia/Manila (PHT)</option>
                                <option value="Asia/Singapore" <?= $settings['timezone'] === 'Asia/Singapore' ? 'selected' : '' ?>>Asia/Singapore</option>
                                <option value="Asia/Tokyo" <?= $settings['timezone'] === 'Asia/Tokyo' ? 'selected' : '' ?>>Asia/Tokyo</option>
                                <option value="UTC" <?= $settings['timezone'] === 'UTC' ? 'selected' : '' ?>>UTC</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle-fill"></i> Save Appearance
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- ============================================
                 SECURITY TAB
                 ============================================ -->
            <?php if ($activeTab === 'security'): ?>
            <div class="settings-panel">
                <div class="panel-header">
                    <div>
                        <h3><i class="bi bi-shield-check"></i> Security Settings</h3>
                        <p>Protect your account with a strong password and manage sessions.</p>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <?php echo app_csrf_field(); ?>

                    <div class="form-group">
                        <label class="form-label"><i class="bi bi-key-fill"></i> Current Password</label>
                        <div class="input-with-icon has-toggle">
                            <i class="bi bi-lock-fill input-icon"></i>
                            <input type="password" name="current_password" id="currentPassword" class="form-control" required>
                            <button type="button" class="password-toggle" onclick="togglePass('currentPassword', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-lock-fill"></i> New Password</label>
                            <div class="input-with-icon has-toggle">
                                <i class="bi bi-shield-lock-fill input-icon"></i>
                                <input type="password" name="new_password" id="newPassword" class="form-control" required>
                                <button type="button" class="password-toggle" onclick="togglePass('newPassword', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-check-circle-fill"></i> Confirm Password</label>
                            <div class="input-with-icon has-toggle">
                                <i class="bi bi-check2-circle input-icon"></i>
                                <input type="password" name="confirm_password" id="confirmPassword" class="form-control" required>
                                <button type="button" class="password-toggle" onclick="togglePass('confirmPassword', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div style="background: #f9fafb; border-radius: 10px; padding: 14px 16px; border-left: 4px solid var(--umak-yellow); margin-bottom: 20px;">
                        <div style="font-size: 12px; font-weight: 800; color: #92400e; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                            <i class="bi bi-info-circle-fill"></i> Password Requirements
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 6px; font-size: 12px; color: #78350f; font-weight: 600;">
                            <div>✓ At least 8 characters</div>
                            <div>✓ One uppercase letter</div>
                            <div>✓ One lowercase letter</div>
                            <div>✓ One number</div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-yellow">
                            <i class="bi bi-shield-check"></i> Update Password
                        </button>
                    </div>
                </form>

                <!-- Sessions -->
                <h4 style="font-size: 13px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin: 30px 0 14px;">
                    <i class="bi bi-laptop"></i> Active Sessions
                </h4>

                <div class="setting-group">
                    <div class="setting-item">
                        <div class="setting-info">
                            <div class="setting-label">
                                <i class="bi bi-laptop"></i> 
                                This Device
                                <span style="background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; margin-left: 6px;">Current</span>
                            </div>
                            <div class="setting-description">
                                <?= htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="POST" onsubmit="return confirm('Log out all other devices?');">
                    <input type="hidden" name="action" value="logout_sessions">
                    <?php echo app_csrf_field(); ?>
                    <button type="submit" class="btn btn-outline" style="margin-top: 10px;">
                        <i class="bi bi-box-arrow-right"></i> Log Out All Other Sessions
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>

    </main>
</div>

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('mobile-open');
    }

    document.addEventListener('click', function(e) {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        if (window.innerWidth <= 992 &&
            !sidebar.contains(e.target) &&
            toggle && !toggle.contains(e.target)) {
            sidebar.classList.remove('mobile-open');
        }
    });

    // Password toggle
    function togglePass(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    }

    // Radio card selection
    document.querySelectorAll('.radio-card input[type="radio"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            const name = this.name;
            document.querySelectorAll(`input[name="${name}"]`).forEach(function(r) {
                r.closest('.radio-card').classList.toggle('selected', r.checked);
            });
        });
    });

    // Auto-dismiss success alerts
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.alert-success').forEach(function(alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() { alert.style.display = 'none'; }, 500);
            }, 4000);
        });
    });
</script>
<?php include 'chat_widget.php'; ?>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>