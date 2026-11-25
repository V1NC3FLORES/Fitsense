<?php
// Admin/admin-settings.php
session_start();
include __DIR__ . '/../conn.php';
require_once __DIR__ . '/../config/session_security.php';

// ============================================
// AUTHENTICATION CHECK
// ============================================
$userSession = app_require_session_user($conn, ['admin', 'super_admin'], 'admin-login.php');
$role = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

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

function executeQuery($conn, $sql, $types = '', $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) return false;
        if (!empty($types)) $stmt->bind_param($types, ...$params);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    } catch (mysqli_sql_exception $e) {
        error_log("ExecuteQuery Error: " . $e->getMessage());
        return false;
    }
}

function tableExists($conn, $tableName) {
    $tableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    return $result && $result->num_rows > 0;
}

// ============================================
// ENSURE SYSTEM SETTINGS TABLE EXISTS
// ============================================
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS `system_settings` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `setting_key` VARCHAR(100) NOT NULL,
            `setting_value` TEXT NULL,
            `setting_type` VARCHAR(50) DEFAULT 'text',
            `category` VARCHAR(50) DEFAULT 'general',
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log("System settings table error: " . $e->getMessage());
}

// ============================================
// FETCH ADMIN DATA
// ============================================
$adminData = null;
$stmtAdmin = $conn->prepare("SELECT id, fullname, username, profile_image, role FROM users WHERE id = ? LIMIT 1");
if ($stmtAdmin) {
    $stmtAdmin->bind_param("i", $userId);
    $stmtAdmin->execute();
    $adminData = $stmtAdmin->get_result()->fetch_assoc();
    $stmtAdmin->close();
}

$adminName = $adminData['fullname'] ?? 'Administrator';
$adminFirstName = explode(' ', trim($adminName))[0];
$adminInitial = strtoupper(substr($adminName, 0, 1));
$adminAvatar = $adminData['profile_image'] ?? '';

// ============================================
// LOAD SYSTEM SETTINGS
// ============================================
$systemSettings = [];
$r = safeQuery($conn, "SELECT setting_key, setting_value FROM system_settings");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $systemSettings[$row['setting_key']] = $row['setting_value'];
    }
}

// Default values
$defaults = [
    'gym_name' => 'FitSense - UMak Gym',
    'gym_email' => 'support@umakgym.com',
    'gym_phone' => '+63 912 345 6789',
    'gym_address' => '11th Floor, Health & Physical Science Building, University of Makati',
    'gym_hours_weekday' => '6:00 AM – 9:00 PM',
    'gym_hours_saturday' => '7:00 AM – 6:00 PM',
    'gym_hours_sunday' => 'Closed',
    'max_gym_capacity' => '20',
    'one_day_price' => '150',
    'monthly_price' => '1500',
    'yearly_price' => '15000',
    'yoga_price' => '250',
    'hiit_price' => '300',
    'boxing_price' => '350',
    'pilates_price' => '300',
    'zumba_price' => '250',
    'crossfit_price' => '400',
    'enable_registration' => '1',
    'enable_email_verification' => '1',
    'enable_2fa' => '0',
    'maintenance_mode' => '0',
    'maintenance_message' => 'We are currently performing scheduled maintenance. Please check back soon.',
    'default_currency' => 'PHP',
    'timezone' => 'Asia/Manila',
];

foreach ($defaults as $key => $val) {
    if (!isset($systemSettings[$key])) {
        $systemSettings[$key] = $val;
    }
}

$adminThemeKey = 'admin_theme_' . $userId;
$adminTheme = $systemSettings[$adminThemeKey] ?? 'auto';
if (!in_array($adminTheme, ['light', 'dark', 'auto'], true)) {
    $adminTheme = 'auto';
}
$_SESSION['admin_theme'] = $adminTheme;

// ============================================
// HANDLE ACTIONS
// ============================================
$flashMessage = '';
$flashType = '';
if (isset($_SESSION['admin_settings_flash']) && is_array($_SESSION['admin_settings_flash'])) {
    $flashMessage = (string)($_SESSION['admin_settings_flash']['message'] ?? '');
    $flashType = (string)($_SESSION['admin_settings_flash']['type'] ?? '');
    unset($_SESSION['admin_settings_flash']);
}
$activeTab = $_GET['tab'] ?? 'gym';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $flashMessage = 'This form has expired or is invalid. Please refresh the page and try again.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'];

        // ============================================
        // UPDATE GYM INFO
        // ============================================
        if ($action === 'update_gym') {
        $fields = [
            'gym_name' => 'text',
            'gym_email' => 'text',
            'gym_phone' => 'text',
            'gym_address' => 'text',
            'gym_hours_weekday' => 'text',
            'gym_hours_saturday' => 'text',
            'gym_hours_sunday' => 'text',
            'max_gym_capacity' => 'number',
        ];

        $success = true;
        foreach ($fields as $key => $type) {
            $val = trim($_POST[$key] ?? '');
            if (upsertSetting($conn, $key, $val, $type, 'gym', $userId) === false) {
                $success = false;
            }
        }

        if ($success) {
            $flashMessage = 'Gym information updated successfully!';
            $flashType = 'success';
            foreach ($fields as $key => $type) {
                $systemSettings[$key] = trim($_POST[$key] ?? '');
            }
        } else {
            $flashMessage = 'Failed to save some settings.';
            $flashType = 'error';
        }
    }

    // ============================================
    // UPDATE MEMBERSHIP PRICES
    // ============================================
    elseif ($action === 'update_membership_prices') {
        $prices = [
            'one_day_price' => $_POST['one_day_price'] ?? 0,
            'monthly_price' => $_POST['monthly_price'] ?? 0,
            'yearly_price' => $_POST['yearly_price'] ?? 0,
        ];

        $success = true;
        foreach ($prices as $key => $val) {
            if (upsertSetting($conn, $key, (float)$val, 'number', 'pricing', $userId) === false) {
                $success = false;
            }
            $systemSettings[$key] = (float)$val;
        }

        // Also update membership_prices table if exists
        if (tableExists($conn, 'membership_prices')) {
            $typeMap = ['one_day_price' => 'One Day', 'monthly_price' => 'Monthly', 'yearly_price' => 'Yearly'];
            foreach ($typeMap as $key => $memType) {
                $price = (float)($prices[$key] ?? 0);
                // Check if row exists
                $r = safeQuery($conn,
                    "SELECT id FROM membership_prices WHERE membership_type = ? LIMIT 1",
                    "s", [$memType]
                );
                if ($r && $r->num_rows > 0) {
                    executeQuery($conn,
                        "UPDATE membership_prices SET price = ?, updated_at = NOW() WHERE membership_type = ?",
                        "ds", [$price, $memType]
                    );
                } else {
                    executeQuery($conn,
                        "INSERT INTO membership_prices (branch_id, membership_type, price, is_active) VALUES (0, ?, ?, 1)",
                        "sd", [$memType, $price]
                    );
                }
            }
        }

        $flashMessage = 'Membership prices updated successfully!';
        $flashType = 'success';
    }

    // ============================================
    // UPDATE SESSION PRICES
    // ============================================
    elseif ($action === 'update_session_prices') {
        $sessionTypes = ['yoga', 'hiit', 'boxing', 'pilates', 'zumba', 'crossfit'];

        $success = true;
        foreach ($sessionTypes as $type) {
            $key = $type . '_price';
            $val = (float)($_POST[$key] ?? 0);
            if (upsertSetting($conn, $key, $val, 'number', 'pricing', $userId) === false) {
                $success = false;
            }
            $systemSettings[$key] = $val;

            // Also update session_offers table
            if (tableExists($conn, 'session_offers')) {
                $sTypeName = ucfirst($type);
                $r = safeQuery($conn,
                    "SELECT id FROM session_offers WHERE session_type = ? LIMIT 1",
                    "s", [$sTypeName]
                );
                if ($r && $r->num_rows > 0) {
                    executeQuery($conn,
                        "UPDATE session_offers SET price = ?, updated_at = NOW() WHERE session_type = ?",
                        "ds", [$val, $sTypeName]
                    );
                } else {
                    executeQuery($conn,
                        "INSERT INTO session_offers (branch_id, session_type, price, is_active) VALUES (0, ?, ?, 1)",
                        "sd", [$sTypeName, $val]
                    );
                }
            }
        }

        $flashMessage = 'Session prices updated successfully!';
        $flashType = 'success';
    }

    // ============================================
    // UPDATE SYSTEM PREFERENCES
    // ============================================
    elseif ($action === 'update_system') {
        $fields = [
            'enable_registration' => 'toggle',
            'enable_email_verification' => 'toggle',
            'enable_2fa' => 'toggle',
            'maintenance_mode' => 'toggle',
            'maintenance_message' => 'text',
            'default_currency' => 'text',
            'timezone' => 'text',
        ];

        $success = true;
        foreach ($fields as $key => $type) {
            if ($type === 'toggle') {
                $val = isset($_POST[$key]) ? '1' : '0';
            } else {
                $val = trim($_POST[$key] ?? '');
            }
            if (upsertSetting($conn, $key, $val, $type, 'system', $userId) === false) {
                $success = false;
            }
            $systemSettings[$key] = $val;
        }

        $flashMessage = 'System preferences updated!';
        $flashType = 'success';
    }

    elseif ($action === 'update_admin_theme') {
        $selectedTheme = $_POST['admin_theme'] ?? '';
        if (!in_array($selectedTheme, ['light', 'dark', 'auto'], true)) {
            $flashMessage = 'Please choose a valid theme.';
            $flashType = 'error';
        } elseif (upsertSetting($conn, $adminThemeKey, $selectedTheme, 'text', 'appearance', $userId)) {
            $adminTheme = $selectedTheme;
            $_SESSION['admin_theme'] = $selectedTheme; // <-- ADD THIS LINE
            $flashMessage = 'Appearance settings updated!';
            $flashType = 'success';
        } else {
            $flashMessage = 'Appearance settings could not be saved.';
            $flashType = 'error';
        }
    }

        if ($flashMessage !== '') {
            $_SESSION['admin_settings_flash'] = [
                'message' => $flashMessage,
                'type' => $flashType,
            ];
        }

        // Redirect to prevent resubmission
        header("Location: admin-settings.php?tab=" . urlencode($activeTab));
        exit;
    }
}

// ============================================
// UPSERT HELPER
// ============================================
function upsertSetting($conn, $key, $value, $type, $category, $userId) {
    try {
        // Check if exists
        $r = safeQuery($conn, "SELECT id FROM system_settings WHERE setting_key = ? LIMIT 1", "s", [$key]);
        if ($r && $r->num_rows > 0) {
            return executeQuery($conn,
                "UPDATE system_settings SET setting_value = ?, setting_type = ?, category = ?, updated_by = ? WHERE setting_key = ?",
                "sssis", [$value, $type, $category, $userId, $key]
            );
        } else {
            return executeQuery($conn,
                "INSERT INTO system_settings (setting_key, setting_value, setting_type, category, updated_by) VALUES (?, ?, ?, ?, ?)",
                "ssssi", [$key, $value, $type, $category, $userId]
            );
        }
    } catch (Exception $e) {
        error_log("upsertSetting error: " . $e->getMessage());
        return false;
    }
}

// ============================================
// STATS FOR SIDEBAR
// ============================================
$pendingMemberships = tableExists($conn, 'memberships')
    ? (int)(safeQuery($conn, "SELECT COUNT(*) AS total FROM memberships WHERE status = 'Pending'")->fetch_assoc()['total'] ?? 0)
    : 0;

$pendingTransactions = tableExists($conn, 'transactions')
    ? (int)(safeQuery($conn, "SELECT COUNT(*) AS total FROM transactions WHERE status = 'Pending'")->fetch_assoc()['total'] ?? 0)
    : 0;

$pendingSessions = tableExists($conn, 'sessions')
    ? (int)(safeQuery($conn, "SELECT COUNT(*) AS total FROM sessions WHERE status = 'Pending'")->fetch_assoc()['total'] ?? 0)
    : 0;

// ============================================
// HELPER FUNCTIONS
// ============================================
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function isToggleOn($val) {
    return !empty($val) && $val !== '0';
}

function formatPrice($val) {
    return number_format((float)$val, 2);
}
?>
<!DOCTYPE html>
<html lang="en"<?= $adminTheme !== 'auto' ? ' data-theme="' . e($adminTheme) . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - FitSense Admin</title>
    <link rel="icon" type="image/png" href="../uploads/umak-gym-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --umak-blue: #005b9f;
            --umak-blue-dark: #00467a;
            --umak-yellow: #ffce00;
            --umak-red: #d32f2f;
            --umak-green: #2e7d32;
            --umak-orange: #f59e0b;
            --sidebar-width: 260px;
            --bg: #f4f6f9;
            --card-bg: #ffffff;
            --text: #1f2937;
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
            background: linear-gradient(180deg, #005b9f 0%, #003a6b 100%);
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
            width: 45px; height: 45px; border-radius: 10px;
            background: rgba(255,255,255,0.1); padding: 3px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; overflow: hidden;
            border: 1px solid rgba(255,255,255,0.15);
        }

        .sidebar-brand .logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }

        .sidebar-brand .brand-info h3 { margin: 0; font-size: 16px; font-weight: 900; letter-spacing: 1.5px; }
        .sidebar-brand .brand-info p { margin: 2px 0 0; font-size: 10px; opacity: 0.65; text-transform: uppercase; letter-spacing: 1.5px; }

        .admin-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 800;
            letter-spacing: 0.5px; text-transform: uppercase;
            margin-top: 6px;
        }
        .admin-badge.super { background: rgba(255,206,0,0.2); color: var(--umak-yellow); border: 1px solid rgba(255,206,0,0.3); }
        .admin-badge.regular { background: rgba(74,222,128,0.2); color: #4ade80; border: 1px solid rgba(74,222,128,0.3); }

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
           MAIN WRAPPER
           ============================================ */
        .main-wrapper { margin-left: var(--sidebar-width); min-height: 100vh; padding-bottom: 40px; }

        .top-header {
            background: white; padding: 15px 30px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04); position: sticky; top: 0;
            z-index: 100; border-bottom: 3px solid var(--umak-yellow);
        }

        .header-left { display: flex; align-items: center; gap: 15px; }
        .sidebar-toggle {
            background: none; border: none; font-size: 22px;
            color: var(--umak-blue); cursor: pointer; display: none; padding: 5px;
        }
        .header-left h2 { margin: 0; font-size: 20px; color: var(--umak-blue); font-weight: 800; }
        .header-left .subtitle { font-size: 12px; color: var(--text-muted); margin: 0; }
        .header-right { display: flex; align-items: center; gap: 12px; }

        .header-btn {
            background: #f4f6f9; border: none; width: 42px; height: 42px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; position: relative; font-size: 18px;
            color: var(--umak-blue); transition: all 0.2s;
            text-decoration: none;
        }
        .header-btn:hover { background: #e5eef5; }

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
        .container { max-width: 1200px; margin: 30px auto; padding: 0 25px; }

        .page-header { margin-bottom: 25px; }

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

        .page-subtitle { color: var(--text-muted); font-size: 14px; margin: 0; }

        /* Alerts */
        .alert {
            padding: 14px 20px; border-radius: 12px; font-size: 14px;
            font-weight: 600; margin-bottom: 20px; display: flex;
            align-items: center; gap: 12px; animation: slideDown 0.4s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border-left: 4px solid var(--umak-green); }
        .alert-error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border-left: 4px solid var(--umak-red); }

        .alert i { font-size: 20px; }

        /* ============================================
           SETTINGS LAYOUT
           ============================================ */
        .settings-layout {
            display: grid;
            grid-template-columns: 260px 1fr;
            gap: 25px;
            align-items: start;
        }

        /* Settings Sidebar Nav */
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
            padding: 13px 16px;
            border-radius: 10px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
            margin-bottom: 4px;
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
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border);
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
           FORM CONTROLS
           ============================================ */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group.full { grid-column: 1 / -1; }

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

        .form-label .required { color: var(--umak-red); }
        .form-label i { color: var(--umak-blue); font-size: 13px; }

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
            outline: none;
        }

        .form-control:focus {
            border-color: var(--umak-blue);
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
            line-height: 1.6;
        }

        select.form-control { cursor: pointer; }

        /* Input with prefix */
        .input-prefix {
            display: flex;
            align-items: center;
        }

        .input-prefix .prefix {
            padding: 12px 14px;
            background: #f3f4f6;
            border: 1.5px solid var(--border);
            border-right: none;
            border-radius: 10px 0 0 10px;
            font-size: 14px;
            font-weight: 800;
            color: var(--umak-blue);
            flex-shrink: 0;
        }

        .input-prefix .form-control {
            border-radius: 0 10px 10px 0;
            flex: 1;
        }

        /* ============================================
           TOGGLE SWITCH
           ============================================ */
        .setting-toggle-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 18px;
            border-radius: 12px;
            background: #f9fafb;
            border: 1px solid var(--border);
            gap: 20px;
            transition: all 0.2s ease;
            margin-bottom: 12px;
        }

        .setting-toggle-item:hover {
            background: white;
            border-color: rgba(0,91,159,0.15);
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }

        .setting-toggle-info { flex: 1; min-width: 0; }

        .setting-toggle-label {
            font-size: 14.5px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .setting-toggle-label i { color: var(--umak-blue); font-size: 16px; }

        .setting-toggle-desc {
            font-size: 12.5px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 54px;
            height: 30px;
            flex-shrink: 0;
        }

        .toggle-switch input { opacity: 0; width: 0; height: 0; }

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
            letter-spacing: 0.3px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 12px rgba(0,91,159,0.25);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,91,159,0.35); color: white; }

        .btn-outline {
            background: white;
            color: var(--umak-blue);
            border: 2px solid var(--umak-blue);
        }
        .btn-outline:hover { background: var(--umak-blue); color: white; }

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
           PRICE CARDS
           ============================================ */
        .price-card {
            padding: 20px;
            border-radius: 14px;
            background: linear-gradient(135deg, #f9fafb, #ffffff);
            border: 2px solid var(--border);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .price-card:hover {
            border-color: rgba(0,91,159,0.3);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,91,159,0.08);
        }

        .price-card.featured {
            border-color: var(--umak-blue);
            background: linear-gradient(135deg, #f0f7fc, #ffffff);
        }

        .price-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }

        .price-card-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }

        .price-card-icon.blue { background: linear-gradient(135deg, #005b9f, #0084d1); }
        .price-card-icon.green { background: linear-gradient(135deg, #2e7d32, #4ade80); }
        .price-card-icon.yellow { background: linear-gradient(135deg, #ffce00, #ffb800); color: #333; }
        .price-card-icon.purple { background: linear-gradient(135deg, #8b5cf6, #a78bfa); }
        .price-card-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .price-card-icon.pink { background: linear-gradient(135deg, #ec4899, #f472b6); }
        .price-card-icon.teal { background: linear-gradient(135deg, #0d849c, #06b6d4); }

        .price-card-title {
            font-size: 15px;
            font-weight: 800;
            color: #111827;
            letter-spacing: 0.3px;
        }

        .price-card-sub {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 2px;
            font-weight: 600;
        }

        /* ============================================
           INFO BOX
           ============================================ */
        .info-box {
            background: linear-gradient(135deg, #f0f7fc, #e0f2fe);
            border-radius: 14px;
            padding: 18px 22px;
            border-left: 5px solid var(--umak-blue);
            margin-bottom: 20px;
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }

        .info-box i {
            font-size: 24px;
            color: var(--umak-blue);
            flex-shrink: 0;
            margin-top: 2px;
        }

        .info-box-content { flex: 1; }

        .info-box-title {
            font-size: 14px;
            font-weight: 800;
            color: var(--umak-blue);
            margin-bottom: 4px;
        }

        .info-box-text {
            font-size: 13px;
            color: #1e40af;
            line-height: 1.6;
            font-weight: 500;
        }

        .backup-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
        }

        .backup-card {
            padding: 22px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
        }

        .backup-card h4 {
            margin: 0 0 8px;
            color: var(--umak-blue);
            font-size: 16px;
        }

        .backup-card p {
            margin: 0 0 14px;
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .backup-card form { margin-top: 14px; }
        .backup-card input[type="file"] { display: block; width: 100%; margin: 8px 0 14px; }
        .backup-card .btn { width: 100%; }
        .backup-card .btn-danger { background: #b42318; color: #fff; }
        .backup-card .btn-danger:hover { background: #8f1c13; }

        .backup-warning {
            margin-bottom: 12px;
            padding: 11px 12px;
            border-left: 4px solid var(--umak-red);
            border-radius: 8px;
            background: #fff1f0;
            color: #991b1b;
            font-size: 12px;
            line-height: 1.5;
        }

        .backup-confirmation {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin: 12px 0;
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .backup-confirmation input { margin-top: 2px; }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
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
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .settings-panel { padding: 20px 18px; }
            .panel-header h3 { font-size: 17px; }
            .form-grid, .form-grid-3 { grid-template-columns: 1fr; }
            .setting-toggle-item { flex-direction: column; align-items: flex-start; gap: 12px; }
            .form-actions .btn { flex: 1; }
        }

        @media (max-width: 576px) {
            .settings-nav-item span { font-size: 12px; }
            .price-card { padding: 16px; }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css">
<link rel="stylesheet" href="/PBL/css/admin-content-icons.css"></head>
<body>

<!-- ============================================
     SIDEBAR
     ============================================ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="logo">
            <img src="../uploads/umak-gym-logo.png" alt="UMak Gym">
        </div>
        <div class="brand-info">
            <h3>FITSENSE</h3>
            <p>Admin Panel</p>
            <span class="admin-badge <?= $isSuperAdmin ? 'super' : 'regular' ?>">
                <i class="bi bi-<?= $isSuperAdmin ? 'shield-fill-check' : 'shield-fill' ?>"></i>
                <?= $isSuperAdmin ? 'Super Admin' : 'Admin' ?>
            </span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Main</div>
        <a href="admin-dashboard.php" class="nav-item">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>

        <div class="nav-section-title">Management</div>
        <a href="admin-members.php" class="nav-item">
            <i class="bi bi-people-fill"></i><span>Members</span>
        </a>
        <a href="admin-memberships.php" class="nav-item">
            <i class="bi bi-ticket-perforated-fill"></i><span>Memberships</span>
            <?php if ($pendingMemberships > 0): ?>
                <span class="badge-count"><?= $pendingMemberships ?></span>
            <?php endif; ?>
        </a>
        <a href="admin-sessions.php" class="nav-item">
            <i class="bi bi-calendar-event-fill"></i><span>Sessions</span>
            <?php if ($pendingSessions > 0): ?>
                <span class="badge-count"><?= $pendingSessions ?></span>
            <?php endif; ?>
        </a>
         <a href="admin-classes.php" class="nav-item">
            <i class="bi bi-calendar-event-fill"></i><span>Classes</span>
        </a>
        <a href="admin-payments.php" class="nav-item">
            <i class="bi bi-credit-card-fill"></i><span>Payment Logs</span>
            <?php if ($pendingTransactions > 0): ?>
                <span class="badge-count"><?= $pendingTransactions ?></span>
            <?php endif; ?>
        </a>

        <?php if ($isSuperAdmin): ?>
            <div class="nav-section-title">Super Admin</div>
            <a href="admin-franchise.php" class="nav-item">
                <i class="bi bi-shop"></i><span>Franchise Apps</span>
            </a>
            <a href="admin-admins.php" class="nav-item">
                <i class="bi bi-person-badge-fill"></i><span>Manage Admins</span>
            </a>
            <a href="admin-branches.php" class="nav-item">
                <i class="bi bi-geo-alt-fill"></i><span>Branches</span>
            </a>
        <?php endif; ?>

        <div class="nav-section-title">Insights</div>
        <a href="admin-reports.php" class="nav-item">
            <i class="bi bi-graph-up-arrow"></i><span>Reports</span>
        </a>

        <div class="nav-section-title">Account</div>
        <a href="admin-profile.php" class="nav-item">
            <i class="bi bi-person-circle"></i><span>My Profile</span>
        </a>
        <a href="admin-settings.php" class="nav-item active">
            <i class="bi bi-gear-fill"></i><span>Settings</span>
        </a>
        <div class="nav-divider"></div>
        <a href="admin-logout.php" class="nav-item nav-logout">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
    </nav>
</aside>

<!-- ============================================
     MAIN WRAPPER
     ============================================ -->
<div class="main-wrapper">

    <header class="top-header">
        <div class="header-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h2>Settings</h2>
                <p class="subtitle">System configuration and preferences</p>
            </div>
        </div>

        <div class="header-right">
            <a href="admin-dashboard.php" class="header-btn" title="Dashboard">
                <i class="bi bi-speedometer2"></i>
            </a>
            <button class="avatar-btn" onclick="window.location.href='admin-profile.php'">
                <div class="avatar">
                    <?php if (!empty($adminAvatar) && file_exists(__DIR__ . '/../' . $adminAvatar)): ?>
                        <img src="../<?= htmlspecialchars($adminAvatar) ?>" alt="">
                    <?php else: ?>
                        <?= htmlspecialchars($adminInitial) ?>
                    <?php endif; ?>
                </div>
            </button>
        </div>
    </header>

    <main class="container">

        <!-- ============================================
             PAGE HEADER
             ============================================ -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="bi bi-gear-fill"></i>
                Settings
            </h1>
            <p class="page-subtitle">Manage gym information, pricing, and system preferences.</p>
        </div>

        <!-- Alerts -->
        <?php if (!empty($flashMessage)): ?>
            <div class="alert alert-<?= $flashType ?>">
                <i class="bi bi-<?= $flashType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
                <div><?= $flashMessage ?></div>
            </div>
        <?php endif; ?>

        <!-- ============================================
             SETTINGS LAYOUT
             ============================================ -->
        <div class="settings-layout">

            <!-- Settings Navigation -->
            <nav class="settings-nav">
                <a href="?tab=gym" class="settings-nav-item <?= $activeTab === 'gym' ? 'active' : '' ?>">
                    <i class="bi bi-building"></i> <span>Gym Information</span>
                </a>
                <a href="?tab=membership" class="settings-nav-item <?= $activeTab === 'membership' ? 'active' : '' ?>">
                    <i class="bi bi-ticket-perforated-fill"></i> <span>Membership Prices</span>
                </a>
                <a href="?tab=sessions" class="settings-nav-item <?= $activeTab === 'sessions' ? 'active' : '' ?>">
                    <i class="bi bi-calendar-event-fill"></i> <span>Session Prices</span>
                </a>
                <a href="?tab=appearance" class="settings-nav-item <?= $activeTab === 'appearance' ? 'active' : '' ?>">
                    <i class="bi bi-palette-fill"></i> <span>Appearance</span>
                </a>
                <a href="?tab=system" class="settings-nav-item <?= $activeTab === 'system' ? 'active' : '' ?>">
                    <i class="bi bi-sliders2"></i> <span>System Preferences</span>
                </a>
                <a href="?tab=backup" class="settings-nav-item <?= $activeTab === 'backup' ? 'active' : '' ?>">
                    <i class="bi bi-database-check"></i> <span>Backup &amp; Restore</span>
                </a>
            </nav>

            <!-- ============================================
                 GYM INFORMATION TAB
                 ============================================ -->
            <?php if ($activeTab === 'gym'): ?>
            <div class="settings-panel">
                <div class="panel-header">
                    <h3><i class="bi bi-building"></i> Gym Information</h3>
                    <p>Basic information about your gym that appears across the system.</p>
                </div>

                <div class="info-box">
                    <i class="bi bi-info-circle-fill"></i>
                    <div class="info-box-content">
                        <div class="info-box-title">Public Information</div>
                        <div class="info-box-text">
                            This information is displayed to members on the login page, footer, and notification emails.
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_gym">
                    <?php echo app_csrf_field(); ?>

                    <div class="form-grid">
                        <div class="form-group full">
                            <label class="form-label">
                                <i class="bi bi-shop"></i>
                                Gym Name <span class="required">*</span>
                            </label>
                            <input type="text" name="gym_name" class="form-control"
                                   value="<?= e($systemSettings['gym_name']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-envelope-fill"></i>
                                Contact Email
                            </label>
                            <input type="email" name="gym_email" class="form-control"
                                   value="<?= e($systemSettings['gym_email']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-telephone-fill"></i>
                                Contact Phone
                            </label>
                            <input type="tel" name="gym_phone" class="form-control"
                                   value="<?= e($systemSettings['gym_phone']) ?>">
                        </div>

                        <div class="form-group full">
                            <label class="form-label">
                                <i class="bi bi-geo-alt-fill"></i>
                                Gym Address
                            </label>
                            <textarea name="gym_address" class="form-control" rows="2"><?= e($systemSettings['gym_address']) ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-sun-fill"></i>
                                Weekday Hours
                            </label>
                            <input type="text" name="gym_hours_weekday" class="form-control"
                                   value="<?= e($systemSettings['gym_hours_weekday']) ?>"
                                   placeholder="6:00 AM – 9:00 PM">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-sunset-fill"></i>
                                Saturday Hours
                            </label>
                            <input type="text" name="gym_hours_saturday" class="form-control"
                                   value="<?= e($systemSettings['gym_hours_saturday']) ?>"
                                   placeholder="7:00 AM – 6:00 PM">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-moon-stars-fill"></i>
                                Sunday Hours
                            </label>
                            <input type="text" name="gym_hours_sunday" class="form-control"
                                   value="<?= e($systemSettings['gym_hours_sunday']) ?>"
                                   placeholder="Closed">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-people-fill"></i>
                                Max Capacity
                            </label>
                            <input type="number" name="max_gym_capacity" class="form-control"
                                   value="<?= e($systemSettings['max_gym_capacity']) ?>"
                                   min="1" max="500">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle-fill"></i> Save Gym Information
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- ============================================
                 MEMBERSHIP PRICES TAB
                 ============================================ -->
            <?php if ($activeTab === 'membership'): ?>
            <div class="settings-panel">
                <div class="panel-header">
                    <h3><i class="bi bi-ticket-perforated-fill"></i> Membership Prices</h3>
                    <p>Set the price for each membership plan. These prices are shown to members during purchase.</p>
                </div>

                <div class="info-box">
                    <i class="bi bi-info-circle-fill"></i>
                    <div class="info-box-content">
                        <div class="info-box-title">Instant Updates</div>
                        <div class="info-box-text">
                            Changes take effect immediately and will be reflected in the member registration and membership renewal flow.
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_membership_prices">
                    <?php echo app_csrf_field(); ?>

                    <div class="form-grid-3">
                        <div class="price-card">
                            <div class="price-card-header">
                                <div class="price-card-icon purple">
                                    <i class="bi bi-sun-fill"></i>
                                </div>
                                <div>
                                    <div class="price-card-title">One Day Pass</div>
                                    <div class="price-card-sub">Single use</div>
                                </div>
                            </div>
                            <div class="input-prefix">
                                <div class="prefix">₱</div>
                                <input type="number" name="one_day_price" class="form-control"
                                       value="<?= e($systemSettings['one_day_price']) ?>"
                                       step="0.01" min="0" required>
                            </div>
                        </div>

                        <div class="price-card featured">
                            <div class="price-card-header">
                                <div class="price-card-icon blue">
                                    <i class="bi bi-calendar-month-fill"></i>
                                </div>
                                <div>
                                    <div class="price-card-title">Monthly Plan</div>
                                    <div class="price-card-sub">30 days · Most popular</div>
                                </div>
                            </div>
                            <div class="input-prefix">
                                <div class="prefix">₱</div>
                                <input type="number" name="monthly_price" class="form-control"
                                       value="<?= e($systemSettings['monthly_price']) ?>"
                                       step="0.01" min="0" required>
                            </div>
                        </div>

                        <div class="price-card">
                            <div class="price-card-header">
                                <div class="price-card-icon yellow">
                                    <i class="bi bi-calendar-heart-fill"></i>
                                </div>
                                <div>
                                    <div class="price-card-title">Yearly Plan</div>
                                    <div class="price-card-sub">365 days · Best value</div>
                                </div>
                            </div>
                            <div class="input-prefix">
                                <div class="prefix">₱</div>
                                <input type="number" name="yearly_price" class="form-control"
                                       value="<?= e($systemSettings['yearly_price']) ?>"
                                       step="0.01" min="0" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle-fill"></i> Update Prices
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- ============================================
                 SESSION PRICES TAB
                 ============================================ -->
            <?php if ($activeTab === 'sessions'): ?>
            <div class="settings-panel">
                <div class="panel-header">
                    <h3><i class="bi bi-calendar-event-fill"></i> Session Prices</h3>
                    <p>Configure the price for each class session type offered at your gym.</p>
                </div>

                <div class="info-box">
                    <i class="bi bi-info-circle-fill"></i>
                    <div class="info-box-content">
                        <div class="info-box-title">Session Booking</div>
                        <div class="info-box-text">
                            These prices are used when members book individual sessions. Set to <strong>₱0.00</strong> for free classes included in memberships.
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_session_prices">
                    <?php echo app_csrf_field(); ?>

                    <div class="form-grid-3">
                        <?php
                        $sessionConfigs = [
                            'yoga' => ['label' => 'Yoga', 'icon' => 'flower1', 'color' => 'purple', 'sub' => 'Relaxation & flexibility'],
                            'hiit' => ['label' => 'HIIT', 'icon' => 'lightning-charge-fill', 'color' => 'orange', 'sub' => 'High intensity'],
                            'boxing' => ['label' => 'Boxing', 'icon' => 'shield-fill', 'color' => 'blue', 'sub' => 'Combat training'],
                            'pilates' => ['label' => 'Pilates', 'icon' => 'activity', 'color' => 'green', 'sub' => 'Core strength'],
                            'zumba' => ['label' => 'Zumba', 'icon' => 'music-note-beamed', 'color' => 'pink', 'sub' => 'Dance workout'],
                            'crossfit' => ['label' => 'CrossFit', 'icon' => 'trophy-fill', 'color' => 'teal', 'sub' => 'Functional training'],
                        ];
                        foreach ($sessionConfigs as $key => $cfg):
                        ?>
                            <div class="price-card">
                                <div class="price-card-header">
                                    <div class="price-card-icon <?= $cfg['color'] ?>">
                                        <i class="bi bi-<?= $cfg['icon'] ?>"></i>
                                    </div>
                                    <div>
                                        <div class="price-card-title"><?= $cfg['label'] ?></div>
                                        <div class="price-card-sub"><?= $cfg['sub'] ?></div>
                                    </div>
                                </div>
                                <div class="input-prefix">
                                    <div class="prefix">₱</div>
                                    <input type="number" name="<?= $key ?>_price" class="form-control"
                                           value="<?= e($systemSettings[$key . '_price']) ?>"
                                           step="0.01" min="0" required>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle-fill"></i> Update Session Prices
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- ============================================
                 SYSTEM PREFERENCES TAB
                 ============================================ -->
            <?php if ($activeTab === 'system'): ?>
            <div class="settings-panel">
                <div class="panel-header">
                    <h3><i class="bi bi-sliders2"></i> System Preferences</h3>
                    <p>Control system behavior, security settings, and features.</p>
                </div>

                <div class="info-box">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div class="info-box-content">
                        <div class="info-box-title">Handle With Care</div>
                        <div class="info-box-text">
                            These settings affect the entire system. Change them only when you understand the impact.
                            <?php if (!$isSuperAdmin): ?>
                                <br><strong>Note:</strong> Some critical settings may require super admin privileges.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_system">
                    <?php echo app_csrf_field(); ?>

                    <h4 style="font-size: 13px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin: 0 0 14px;">
                        <i class="bi bi-shield-lock-fill"></i> Account & Security
                    </h4>

                    <div class="setting-toggle-item">
                        <div class="setting-toggle-info">
                            <div class="setting-toggle-label">
                                <i class="bi bi-person-plus-fill"></i> Enable New Registrations
                            </div>
                            <div class="setting-toggle-desc">Allow new members to create accounts on the registration page.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_registration"
                                   <?= isToggleOn($systemSettings['enable_registration']) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="setting-toggle-item">
                        <div class="setting-toggle-info">
                            <div class="setting-toggle-label">
                                <i class="bi bi-envelope-check-fill"></i> Email Verification Required
                            </div>
                            <div class="setting-toggle-desc">New accounts must verify their email via OTP code before login.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_email_verification"
                                   <?= isToggleOn($systemSettings['enable_email_verification']) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="setting-toggle-item">
                        <div class="setting-toggle-info">
                            <div class="setting-toggle-label">
                                <i class="bi bi-shield-fill-check"></i> Two-Factor Authentication (2FA)
                            </div>
                            <div class="setting-toggle-desc">Require OTP code on every login for additional security.</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_2fa"
                                   <?= isToggleOn($systemSettings['enable_2fa']) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <h4 style="font-size: 13px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin: 24px 0 14px;">
                        <i class="bi bi-globe"></i> Localization
                    </h4>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-currency-exchange"></i>
                                Default Currency
                            </label>
                            <select name="default_currency" class="form-control">
                                <option value="PHP" <?= $systemSettings['default_currency'] === 'PHP' ? 'selected' : '' ?>>PHP - Philippine Peso (₱)</option>
                                <option value="USD" <?= $systemSettings['default_currency'] === 'USD' ? 'selected' : '' ?>>USD - US Dollar ($)</option>
                                <option value="EUR" <?= $systemSettings['default_currency'] === 'EUR' ? 'selected' : '' ?>>EUR - Euro (€)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-clock-fill"></i>
                                Timezone
                            </label>
                            <select name="timezone" class="form-control">
                                <option value="Asia/Manila" <?= $systemSettings['timezone'] === 'Asia/Manila' ? 'selected' : '' ?>>Asia/Manila (PHT)</option>
                                <option value="Asia/Singapore" <?= $systemSettings['timezone'] === 'Asia/Singapore' ? 'selected' : '' ?>>Asia/Singapore</option>
                                <option value="Asia/Tokyo" <?= $systemSettings['timezone'] === 'Asia/Tokyo' ? 'selected' : '' ?>>Asia/Tokyo</option>
                                <option value="UTC" <?= $systemSettings['timezone'] === 'UTC' ? 'selected' : '' ?>>UTC</option>
                            </select>
                        </div>
                    </div>

                    <h4 style="font-size: 13px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin: 24px 0 14px;">
                        <i class="bi bi-tools"></i> Maintenance
                    </h4>

                    <div class="setting-toggle-item" style="border-left: 4px solid var(--umak-red);">
                        <div class="setting-toggle-info">
                            <div class="setting-toggle-label">
                                <i class="bi bi-exclamation-triangle-fill" style="color: var(--umak-red);"></i> Maintenance Mode
                            </div>
                            <div class="setting-toggle-desc">
                                When enabled, members cannot access the system. Only admins can log in.
                                <strong style="color: var(--umak-red);">Use with caution.</strong>
                            </div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="maintenance_mode"
                                   <?= isToggleOn($systemSettings['maintenance_mode']) ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="bi bi-chat-square-text-fill"></i>
                            Maintenance Message
                        </label>
                        <textarea name="maintenance_message" class="form-control" rows="3"><?= e($systemSettings['maintenance_message']) ?></textarea>
                        <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 4px;">
                            This message will be shown to members during maintenance.
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle-fill"></i> Save System Preferences
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($activeTab === 'appearance'): ?>
            <div class="settings-panel">
                <div class="panel-header">
                    <h3>Appearance</h3>
                    <p>Choose how FitSense looks on all pages for your admin account.</p>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_admin_theme">
                    <?= app_csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label">Theme</label>
                        <div class="theme-picker">
                            <input type="hidden" name="admin_theme" data-theme-input
                                   value="<?= htmlspecialchars($adminTheme, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="button" class="theme-choice" data-theme-choice="light"
                                    aria-pressed="<?= $adminTheme === 'light' ? 'true' : 'false' ?>">Light mode</button>
                            <button type="button" class="theme-choice" data-theme-choice="dark"
                                    aria-pressed="<?= $adminTheme === 'dark' ? 'true' : 'false' ?>">Dark mode</button>
                            <button type="button" class="theme-choice" data-theme-choice="auto"
                                    aria-pressed="<?= $adminTheme === 'auto' ? 'true' : 'false' ?>">Default</button>
                        </div>
                        <p class="form-hint">Default follows your device’s light or dark preference.</p>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Appearance</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($activeTab === 'backup'): ?>
            <div class="settings-panel">
                <div class="panel-header">
                    <h3>Backup &amp; Restore</h3>
                    <p>Create database or full-system backups, or restore from a trusted backup file.</p>
                </div>

                <div class="backup-grid">
                    <section class="backup-card">
                        <h4>Database Backup</h4>
                        <p>Download a SQL backup containing the gym database, including its tables and data.</p>
                        <form method="POST" action="admin-backups.php">
                            <?= app_csrf_field() ?>
                            <input type="hidden" name="action" value="create_backup">
                            <button type="submit" class="btn btn-primary">Download SQL Backup</button>
                        </form>
                    </section>

                    <section class="backup-card">
                        <h4>Full System Backup</h4>
                        <p>Download a ZIP containing the database and uploaded profile, avatar, and logo files.</p>
                        <form method="POST" action="admin-backups.php">
                            <?= app_csrf_field() ?>
                            <input type="hidden" name="action" value="create_full_backup">
                            <button type="submit" class="btn btn-primary">Download Full Backup</button>
                        </form>
                    </section>

                    <section class="backup-card">
                        <h4>Restore Database</h4>
                        <div class="backup-warning">This replaces the current database data. Restore only a trusted SQL backup.</div>
                        <form method="POST" action="admin-backups.php" enctype="multipart/form-data"
                              onsubmit="return confirm('Restoring this SQL backup will replace the current database data. Continue?');">
                            <?= app_csrf_field() ?>
                            <input type="hidden" name="action" value="restore_backup">
                            <label for="settings_backup_file">SQL backup file</label>
                            <input id="settings_backup_file" type="file" name="backup_file"
                                   accept=".sql,application/sql,text/plain" required>
                            <label class="backup-confirmation">
                                <input type="checkbox" name="restore_confirmation" value="1" required>
                                <span>I understand that the current database data will be replaced.</span>
                            </label>
                            <button type="submit" class="btn btn-danger">Restore Database</button>
                        </form>
                    </section>

                    <section class="backup-card">
                        <h4>Restore Full System</h4>
                        <div class="backup-warning">This replaces database data and may overwrite uploaded files with matching names.</div>
                        <form method="POST" action="admin-backups.php" enctype="multipart/form-data"
                              onsubmit="return confirm('Restoring a full system backup will replace database data and may overwrite uploaded files. Continue?');">
                            <?= app_csrf_field() ?>
                            <input type="hidden" name="action" value="restore_full_backup">
                            <label for="settings_full_backup_file">Full system ZIP file</label>
                            <input id="settings_full_backup_file" type="file" name="full_backup_file"
                                   accept=".zip,application/zip" required>
                            <label class="backup-confirmation">
                                <input type="checkbox" name="full_restore_confirmation" value="1" required>
                                <span>I understand that database data will be replaced and uploaded files may be overwritten.</span>
                            </label>
                            <button type="submit" class="btn btn-danger">Restore Full System</button>
                        </form>
                    </section>
                </div>
            </div>
            <?php endif; ?>

        </div>

    </main>
</div>

<script>
    // ============================================
    // SIDEBAR TOGGLE
    // ============================================
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

    // ============================================
    // AUTO-DISMISS ALERTS
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.alert-success').forEach(function(alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s, transform 0.5s';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function() { alert.style.display = 'none'; }, 500);
            }, 5000);
        });

        // Warn before enabling maintenance mode
        const maintenanceToggle = document.querySelector('input[name="maintenance_mode"]');
        if (maintenanceToggle) {
            maintenanceToggle.addEventListener('change', function() {
                if (this.checked) {
                    if (!confirm('⚠️ WARNING: Enabling maintenance mode will block all members from accessing the system.\n\nAre you sure you want to proceed?')) {
                        this.checked = false;
                    }
                }
            });
        }
    });
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>