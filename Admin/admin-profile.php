<?php
// Admin/admin-profile.php
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

function getTableColumns($conn, $tableName) {
    static $cache = [];
    if (isset($cache[$tableName])) return $cache[$tableName];
    $columns = [];
    $tableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW COLUMNS FROM `$tableName`");
    if ($result) {
        while ($row = $result->fetch_assoc()) $columns[] = $row['Field'];
    }
    $cache[$tableName] = $columns;
    return $columns;
}

function columnExists($conn, $tableName, $columnName) {
    return in_array($columnName, getTableColumns($conn, $tableName));
}

// ============================================
// HANDLE PROFILE UPDATE
// ============================================
$flashMessage = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $flashMessage = 'This form has expired or is invalid. Please refresh the page and try again.';
        $flashType = 'error';
    } else {
    $action = $_POST['action'];

    // ============================================
    // UPDATE PERSONAL INFO
    // ============================================
    if ($action === 'update_info') {
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $errors = [];

        if (empty($fullname) || strlen($fullname) < 2) {
            $errors[] = 'Please enter your full name.';
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        // Check email uniqueness
        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param("si", $email, $userId);
            $stmt->execute();
            $r = $stmt->get_result();
            if ($r && $r->num_rows > 0) {
                $errors[] = 'This email is already in use by another account.';
            }
            $stmt->close();
        }

        if (empty($errors)) {
            // Build dynamic update
            $updates = ['fullname = ?', 'email = ?'];
            $params = [$fullname, $email];
            $types = 'ss';

            if (columnExists($conn, 'users', 'phone')) {
                $updates[] = 'phone = ?';
                $params[] = $phone;
                $types .= 's';
            }

            $params[] = $userId;
            $types .= 'i';

            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    $_SESSION['fullname'] = $fullname;
                    $_SESSION['email'] = $email;
                    $flashMessage = 'Profile updated successfully!';
                    $flashType = 'success';
                } else {
                    $flashMessage = 'Failed to update profile.';
                    $flashType = 'error';
                }
                $stmt->close();
            }
        } else {
            $flashMessage = implode('<br>', $errors);
            $flashType = 'error';
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
            $r = safeQuery($conn, "SELECT password FROM users WHERE id = ?", "i", [$userId]);
            if ($r && $row = $r->fetch_assoc()) {
                if (!password_verify($current, $row['password'])) {
                    $errors[] = 'Current password is incorrect.';
                }
            } else {
                $errors[] = 'User not found.';
            }
        }

        if (empty($errors)) {
            $newHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);

            $updates = ['password = ?'];
            $params = [$newHash];
            $types = 's';

            if (columnExists($conn, 'users', 'password_last_changed')) {
                $updates[] = 'password_last_changed = NOW()';
            }
            if (columnExists($conn, 'users', 'must_change_password')) {
                $updates[] = 'must_change_password = 0';
            }

            $params[] = $userId;
            $types .= 'i';

            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    $flashMessage = 'Password changed successfully!';
                    $flashType = 'success';
                } else {
                    $flashMessage = 'Failed to change password.';
                    $flashType = 'error';
                }
                $stmt->close();
            }
        } else {
            $flashMessage = implode('<br>', $errors);
            $flashType = 'error';
        }
    }

    // ============================================
    // UPLOAD PROFILE PICTURE
    // ============================================
    elseif ($action === 'upload_avatar') {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $maxSize = 3 * 1024 * 1024;

            if (!in_array($file['type'], $allowed)) {
                $flashMessage = 'Only JPG, PNG, WEBP, or GIF files allowed.';
                $flashType = 'error';
            } elseif ($file['size'] > $maxSize) {
                $flashMessage = 'Image must be smaller than 3MB.';
                $flashType = 'error';
            } else {
                $uploadDir = __DIR__ . '/../uploads/admins/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $filename = 'admin_' . $userId . '_' . time() . '.' . $ext;
                $filepath = $uploadDir . $filename;
                $dbPath = 'uploads/admins/' . $filename;

                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Delete old avatar
                    $r = safeQuery($conn, "SELECT profile_image FROM users WHERE id = ?", "i", [$userId]);
                    if ($r && $row = $r->fetch_assoc()) {
                        if (!empty($row['profile_image']) && file_exists(__DIR__ . '/../' . $row['profile_image'])) {
                            @unlink(__DIR__ . '/../' . $row['profile_image']);
                        }
                    }

                    if (executeQuery($conn, "UPDATE users SET profile_image = ? WHERE id = ?", "si", [$dbPath, $userId])) {
                        $_SESSION['profile_image'] = $dbPath;
                        $flashMessage = 'Profile picture updated!';
                        $flashType = 'success';
                    }
                } else {
                    $flashMessage = 'Failed to upload image.';
                    $flashType = 'error';
                }
            }
        }
    }

    // ============================================
    // REMOVE PROFILE PICTURE
    // ============================================
    elseif ($action === 'remove_avatar') {
        $r = safeQuery($conn, "SELECT profile_image FROM users WHERE id = ?", "i", [$userId]);
        if ($r && $row = $r->fetch_assoc()) {
            if (!empty($row['profile_image']) && file_exists(__DIR__ . '/../' . $row['profile_image'])) {
                @unlink(__DIR__ . '/../' . $row['profile_image']);
            }
        }
        if (executeQuery($conn, "UPDATE users SET profile_image = NULL WHERE id = ?", "i", [$userId])) {
            $_SESSION['profile_image'] = null;
            $flashMessage = 'Profile picture removed.';
            $flashType = 'success';
        }
    }

    }

    // Redirect after POST
    if (!empty($flashMessage)) {
        $_SESSION['flash_message'] = $flashMessage;
        $_SESSION['flash_type'] = $flashType;
        header("Location: admin-profile.php");
        exit;
    }
}

if (!empty($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// ============================================
// FETCH ADMIN DATA
// ============================================
$admin = null;
$stmtAdmin = $conn->prepare("
    SELECT id, fullname, username, email, phone, profile_image, role, 
           account_status, created_at, last_login, permissions,
           password_last_changed, must_change_password
    FROM users 
    WHERE id = ? LIMIT 1
");
if ($stmtAdmin) {
    $stmtAdmin->bind_param("i", $userId);
    $stmtAdmin->execute();
    $admin = $stmtAdmin->get_result()->fetch_assoc();
    $stmtAdmin->close();
}

$adminName = $admin['fullname'] ?? 'Administrator';
$adminFirstName = explode(' ', trim($adminName))[0];
$adminInitial = strtoupper(substr($adminName, 0, 1));
$adminAvatar = $admin['profile_image'] ?? '';
$adminEmail = $admin['email'] ?? '';
$adminUsername = $admin['username'] ?? '';
$adminPhone = $admin['phone'] ?? '';

// Parse permissions
$permissions = [];
if (!empty($admin['permissions'])) {
    $permissions = array_map('trim', explode(',', $admin['permissions']));
}

// Permission labels
$permissionLabels = [
    'manage_admins' => ['label' => 'Manage Admins', 'icon' => 'person-badge-fill', 'color' => '#8b5cf6'],
    'manage_branches' => ['label' => 'Manage Branches', 'icon' => 'geo-alt-fill', 'color' => '#0d849c'],
    'manage_permissions' => ['label' => 'Manage Permissions', 'icon' => 'shield-fill-check', 'color' => '#ec4899'],
    'approve_franchise' => ['label' => 'Approve Franchise', 'icon' => 'shop', 'color' => '#f59e0b'],
    'view_reports' => ['label' => 'View Reports', 'icon' => 'graph-up-arrow', 'color' => '#10b981'],
    'view_records' => ['label' => 'View Records', 'icon' => 'folder-fill', 'color' => '#005b9f'],
    'manage_applications' => ['label' => 'Manage Applications', 'icon' => 'clipboard-check-fill', 'color' => '#f59e0b'],
    'manage_prices' => ['label' => 'Manage Prices', 'icon' => 'currency-exchange', 'color' => '#6366f1'],
    'view_notifications' => ['label' => 'View Notifications', 'icon' => 'bell-fill', 'color' => '#d32f2f'],
    'manage_profile' => ['label' => 'Manage Profile', 'icon' => 'person-circle', 'color' => '#0d849c'],
];

// ============================================
// STATS / ACTIVITY
// ============================================
$stats = [
    'days_with_us' => 0,
    'total_members' => 0,
    'total_revenue' => 0,
    'total_sessions' => 0,
];

if (!empty($admin['created_at'])) {
    $stats['days_with_us'] = (int)((time() - strtotime($admin['created_at'])) / 86400);
}

// Global stats (for super admin) or branch-scoped
$r = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'user'");
if ($r && $row = $r->fetch_assoc()) $stats['total_members'] = (int)$row['total'];

if (tableExists($conn, 'transactions')) {
    $r = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM transactions WHERE status = 'Paid'");
    if ($r && $row = $r->fetch_assoc()) $stats['total_revenue'] = (float)$row['total'];
}

if (tableExists($conn, 'sessions')) {
    $r = $conn->query("SELECT COUNT(*) AS total FROM sessions");
    if ($r && $row = $r->fetch_assoc()) $stats['total_sessions'] = (int)$row['total'];
}

// ============================================
// RECENT ACTIVITY LOG
// ============================================
$activityLog = [];

if (tableExists($conn, 'admin_logs')) {
    $r = safeQuery($conn, "
        SELECT action, details, created_at, ip_address
        FROM admin_logs 
        WHERE admin_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ", "i", [$userId]);
    if ($r) {
        while ($row = $r->fetch_assoc()) $activityLog[] = $row;
    }
}

// Generate fallback activity from recent actions
if (empty($activityLog)) {
    // Recent members added
    $r = safeQuery($conn, "
        SELECT 'Added new member' AS action, 
               CONCAT('Member: ', fullname) AS details,
               created_at, NULL AS ip_address
        FROM users 
        WHERE created_by = ? AND role = 'user'
        ORDER BY created_at DESC
        LIMIT 5
    ", "i", [$userId]);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $row['action'] = 'Created member account';
            $activityLog[] = $row;
        }
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function timeAgo($datetime) {
    if (empty($datetime)) return 'Never';
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) != 1 ? 's' : '') . ' ago';
    return date('M j, Y', $ts);
}

function formatMoney($amount) {
    return '₱' . number_format($amount, 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - FitSense Admin</title>
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

        .sidebar-brand .brand-info h3 {
            margin: 0; font-size: 16px; font-weight: 900;
            letter-spacing: 1.5px;
        }

        .sidebar-brand .brand-info p {
            margin: 2px 0 0; font-size: 10px; opacity: 0.65;
            text-transform: uppercase; letter-spacing: 1.5px;
        }

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
            font-weight: bold; font-size: 16px; transition: transform 0.2s;
            overflow: hidden;
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar:hover { transform: scale(1.05); }

        /* ============================================
           PAGE CONTENT
           ============================================ */
        .container { max-width: 1200px; margin: 30px auto; padding: 0 25px; }

        .page-header {
            margin-bottom: 25px;
        }

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
           PROFILE HERO
           ============================================ */
        .profile-hero {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 20px;
            padding: 35px;
            color: white;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0,91,159,0.25);
        }

        .profile-hero::before {
            content: '';
            position: absolute;
            top: -100px; right: -100px;
            width: 350px; height: 350px;
            background: radial-gradient(circle, rgba(255,206,0,0.18) 0%, transparent 70%);
            border-radius: 50%;
        }

        .profile-hero::after {
            content: '';
            position: absolute;
            bottom: -80px; left: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(74,222,128,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .profile-hero-content {
            display: flex;
            gap: 30px;
            align-items: center;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }

        .profile-avatar-wrapper {
            position: relative;
            flex-shrink: 0;
        }

        .profile-avatar-large {
            width: 130px;
            height: 130px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ffce00, #ffb800);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 52px;
            font-weight: 900;
            color: #333;
            border: 5px solid rgba(255,255,255,0.15);
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            overflow: hidden;
            position: relative;
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-upload-btn {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            color: var(--umak-blue);
            border: 3px solid var(--umak-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .avatar-upload-btn:hover {
            background: var(--umak-blue);
            color: white;
            transform: scale(1.1);
        }

        .profile-hero-info { flex: 1; min-width: 250px; }

        .profile-hero-name {
            font-size: 34px;
            font-weight: 800;
            margin: 0 0 8px;
            letter-spacing: -0.8px;
            line-height: 1.1;
        }

        .profile-hero-username {
            font-size: 15px;
            color: rgba(255,255,255,0.75);
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .profile-hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 13px;
        }

        .profile-hero-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255,255,255,0.85);
            font-weight: 600;
        }

        .profile-hero-meta-item i {
            color: var(--umak-yellow);
            font-size: 15px;
        }

        .profile-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .profile-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .profile-badge.super { background: rgba(255,206,0,0.2); color: var(--umak-yellow); border: 1px solid rgba(255,206,0,0.4); }
        .profile-badge.admin { background: rgba(74,222,128,0.2); color: #4ade80; border: 1px solid rgba(74,222,128,0.4); }
        .profile-badge.active { background: rgba(74,222,128,0.2); color: #4ade80; border: 1px solid rgba(74,222,128,0.4); }

        /* ============================================
           STATS ROW
           ============================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }

        .stat-box {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 20px 22px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.25s ease;
        }

        .stat-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }

        .stat-box-icon {
            width: 50px; height: 50px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
        }

        .stat-box-icon.blue { background: linear-gradient(135deg, rgba(0,91,159,0.12), rgba(0,132,209,0.18)); color: var(--umak-blue); }
        .stat-box-icon.green { background: linear-gradient(135deg, rgba(46,125,50,0.12), rgba(74,222,128,0.18)); color: var(--umak-green); }
        .stat-box-icon.yellow { background: linear-gradient(135deg, rgba(255,206,0,0.2), rgba(255,184,0,0.25)); color: #b8860b; }
        .stat-box-icon.purple { background: linear-gradient(135deg, rgba(139,92,246,0.12), rgba(167,139,250,0.18)); color: #7c3aed; }

        .stat-box-info { flex: 1; min-width: 0; }
        .stat-box-label { font-size: 11px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .stat-box-value { font-size: 24px; font-weight: 800; color: #111827; line-height: 1.1; letter-spacing: -0.5px; }
        .stat-box-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }

        /* ============================================
           MAIN GRID
           ============================================ */
        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 22px;
            margin-bottom: 22px;
        }

        .panel {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 28px 30px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }

        .panel.full-width { grid-column: 1 / -1; }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
            gap: 12px;
            flex-wrap: wrap;
        }

        .panel-title {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-title i { font-size: 20px; }

        /* ============================================
           FORM STYLING
           ============================================ */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
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

        .form-control:disabled {
            background: #f9fafb;
            color: var(--text-muted);
            cursor: not-allowed;
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

        .btn-yellow {
            background: linear-gradient(135deg, #ffce00, #ffb800);
            color: #333;
            box-shadow: 0 4px 12px rgba(255,206,0,0.3);
        }
        .btn-yellow:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(255,206,0,0.4); color: #333; }

        .btn-danger {
            background: linear-gradient(135deg, #d32f2f, #ef4444);
            color: white;
            box-shadow: 0 4px 12px rgba(211,47,47,0.25);
        }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(211,47,47,0.35); color: white; }

        .btn i { font-size: 15px; }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        /* ============================================
           INFO LIST
           ============================================ */
        .info-list {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 12px;
            border-bottom: 1px dashed var(--border);
            gap: 12px;
        }

        .info-item:last-child { border-bottom: none; }

        .info-label {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .info-label i {
            font-size: 14px;
            color: var(--umak-blue);
        }

        .info-value {
            font-size: 13.5px;
            font-weight: 700;
            color: #111827;
            text-align: right;
            word-break: break-word;
        }

        .info-value.status-active { color: var(--umak-green); }
        .info-value.status-super { color: #b45309; }

        /* ============================================
           PERMISSIONS GRID
           ============================================ */
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }

        .permission-card {
            padding: 14px 16px;
            border-radius: 12px;
            background: #f9fafb;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s ease;
        }

        .permission-card:hover {
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
            transform: translateY(-2px);
        }

        .permission-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            flex-shrink: 0;
        }

        .permission-info { flex: 1; min-width: 0; }

        .permission-name {
            font-size: 13.5px;
            font-weight: 800;
            color: #111827;
            line-height: 1.2;
        }

        .permission-status {
            font-size: 11px;
            font-weight: 700;
            color: var(--umak-green);
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* ============================================
           ACTIVITY LOG
           ============================================ */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .activity-item {
            display: flex;
            gap: 14px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #f9fafb;
            border: 1px solid var(--border);
            transition: all 0.2s;
        }

        .activity-item:hover {
            background: white;
            transform: translateX(3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .activity-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(0,91,159,0.1);
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .activity-content { flex: 1; min-width: 0; }

        .activity-action {
            font-size: 13.5px;
            font-weight: 800;
            color: #111827;
            line-height: 1.3;
        }

        .activity-detail {
            font-size: 12.5px;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 2px;
        }

        .activity-time {
            font-size: 11.5px;
            color: var(--text-muted);
            font-weight: 600;
            flex-shrink: 0;
            align-self: center;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* ============================================
           DANGER ZONE
           ============================================ */
        .danger-zone {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border-radius: 16px;
            padding: 22px 26px;
            border: 2px dashed rgba(211,47,47,0.3);
            margin-top: 22px;
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

        /* ============================================
           EMPTY STATE
           ============================================ */
        .empty-state {
            text-align: center;
            padding: 30px 15px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 40px;
            opacity: 0.3;
            display: block;
            margin-bottom: 10px;
        }

        .empty-state p {
            margin: 0;
            font-size: 13.5px;
            line-height: 1.6;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
            .main-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .profile-hero { padding: 26px 22px; }
            .profile-hero-content { gap: 20px; }
            .profile-avatar-large { width: 95px; height: 95px; font-size: 38px; }
            .profile-hero-name { font-size: 24px; }
            .profile-hero-meta { flex-direction: column; gap: 8px; }
            .panel { padding: 22px 18px; }
            .form-grid { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 576px) {
            .stats-row { grid-template-columns: 1fr; }
            .form-actions .btn { flex: 1; }
            .profile-hero { padding: 22px 18px; }
            .profile-avatar-large { width: 80px; height: 80px; font-size: 32px; }
            .profile-hero-name { font-size: 20px; }
            .permissions-grid { grid-template-columns: 1fr; }
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
        </a>
        <a href="admin-sessions.php" class="nav-item">
            <i class="bi bi-calendar-event-fill"></i><span>Sessions</span>
        </a>
         <a href="admin-classes.php" class="nav-item">
            <i class="bi bi-calendar-event-fill"></i><span>Classes</span>
        </a>
        <a href="admin-payments.php" class="nav-item">
            <i class="bi bi-credit-card-fill"></i><span>Payment Logs</span>
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
        <a href="admin-profile.php" class="nav-item active">
            <i class="bi bi-person-circle"></i><span>My Profile</span>
        </a>
        <a href="admin-settings.php" class="nav-item">
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
                <h2>My Profile</h2>
                <p class="subtitle">Manage your admin account</p>
            </div>
        </div>

        <div class="header-right">
            <a href="admin-dashboard.php" class="header-btn" title="Dashboard">
                <i class="bi bi-speedometer2"></i>
            </a>
            <button class="avatar-btn">
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
                <i class="bi bi-person-circle"></i>
                My Profile
            </h1>
            <p class="page-subtitle">View and update your personal information, security settings, and account preferences.</p>
        </div>

        <!-- Alerts -->
        <?php if (!empty($flashMessage)): ?>
            <div class="alert alert-<?= $flashType ?>">
                <i class="bi bi-<?= $flashType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
                <div><?= $flashMessage ?></div>
            </div>
        <?php endif; ?>

        <!-- ============================================
             PROFILE HERO
             ============================================ -->
        <div class="profile-hero">
            <div class="profile-hero-content">
                <div class="profile-avatar-wrapper">
                    <div class="profile-avatar-large">
                        <?php if (!empty($adminAvatar) && file_exists(__DIR__ . '/../' . $adminAvatar)): ?>
                            <img src="../<?= htmlspecialchars($adminAvatar) ?>" alt="">
                        <?php else: ?>
                            <?= htmlspecialchars($adminInitial) ?>
                        <?php endif; ?>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="display: contents;" id="avatarForm">
                        <input type="hidden" name="action" value="upload_avatar">
                        <?php echo app_csrf_field(); ?>
                        <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display: none;">
                        <label class="avatar-upload-btn" for="avatarInput" title="Change profile picture">
                            <i class="bi bi-camera-fill"></i>
                        </label>
                    </form>
                </div>

                <div class="profile-hero-info">
                    <h2 class="profile-hero-name"><?= htmlspecialchars($adminName) ?></h2>
                    <div class="profile-hero-username">
                        <i class="bi bi-at"></i>
                        <?= htmlspecialchars($adminUsername) ?>
                    </div>

                    <div class="profile-hero-meta">
                        <div class="profile-hero-meta-item">
                            <i class="bi bi-envelope-fill"></i>
                            <?= htmlspecialchars($adminEmail) ?>
                        </div>
                        <?php if (!empty($adminPhone)): ?>
                            <div class="profile-hero-meta-item">
                                <i class="bi bi-telephone-fill"></i>
                                <?= htmlspecialchars($adminPhone) ?>
                            </div>
                        <?php endif; ?>
                        <div class="profile-hero-meta-item">
                            <i class="bi bi-calendar-check-fill"></i>
                            Joined <?= !empty($admin['created_at']) ? date('M Y', strtotime($admin['created_at'])) : 'Recently' ?>
                        </div>
                    </div>

                    <div class="profile-badges">
                        <span class="profile-badge <?= $isSuperAdmin ? 'super' : 'admin' ?>">
                            <i class="bi bi-<?= $isSuperAdmin ? 'shield-fill-check' : 'shield-fill' ?>"></i>
                            <?= $isSuperAdmin ? 'Super Administrator' : 'Administrator' ?>
                        </span>
                        <span class="profile-badge active">
                            <i class="bi bi-check-circle-fill"></i>
                            <?= ucfirst($admin['account_status'] ?? 'Active') ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================
             STATS ROW
             ============================================ -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-box-icon blue">
                    <i class="bi bi-calendar-check-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Days With Us</div>
                    <div class="stat-box-value"><?= number_format($stats['days_with_us']) ?></div>
                    <div class="stat-box-sub">since joining</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon green">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Members Managed</div>
                    <div class="stat-box-value"><?= number_format($stats['total_members']) ?></div>
                    <div class="stat-box-sub">active accounts</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon yellow">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Total Revenue</div>
                    <div class="stat-box-value"><?= formatMoney($stats['total_revenue']) ?></div>
                    <div class="stat-box-sub">all time</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon purple">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Last Login</div>
                    <div class="stat-box-value" style="font-size: 15px;">
                        <?= !empty($admin['last_login']) ? date('M j, g:i A', strtotime($admin['last_login'])) : 'First login' ?>
                    </div>
                    <div class="stat-box-sub"><?= timeAgo($admin['last_login']) ?></div>
                </div>
            </div>
        </div>

        <!-- ============================================
             MAIN GRID
             ============================================ -->
        <div class="main-grid">

            <!-- LEFT: Personal Info Form -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-person-vcard"></i>
                        Personal Information
                    </h3>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_info">
                    <?php echo app_csrf_field(); ?>

                    <div class="form-grid">
                        <div class="form-group full">
                            <label class="form-label">
                                <i class="bi bi-person-fill"></i>
                                Full Name <span class="required">*</span>
                            </label>
                            <input type="text" name="fullname" class="form-control"
                                   value="<?= htmlspecialchars($adminName) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-at"></i>
                                Username
                            </label>
                            <input type="text" class="form-control"
                                   value="<?= htmlspecialchars($adminUsername) ?>" disabled>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-shield-fill"></i>
                                Role
                            </label>
                            <input type="text" class="form-control"
                                   value="<?= $isSuperAdmin ? 'Super Administrator' : 'Administrator' ?>" disabled>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-envelope-fill"></i>
                                Email <span class="required">*</span>
                            </label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($adminEmail) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-telephone-fill"></i>
                                Phone Number
                            </label>
                            <input type="tel" name="phone" class="form-control"
                                   value="<?= htmlspecialchars($adminPhone) ?>"
                                   placeholder="+63 9XX XXX XXXX">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-outline">
                            <i class="bi bi-arrow-clockwise"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle-fill"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- RIGHT: Account Info -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-info-circle-fill"></i>
                        Account Info
                    </h3>
                </div>

                <div class="info-list">
                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-person-badge-fill"></i>
                            Admin ID
                        </span>
                        <span class="info-value">#<?= str_pad($userId, 5, '0', STR_PAD_LEFT) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-shield-fill"></i>
                            Role
                        </span>
                        <span class="info-value <?= $isSuperAdmin ? 'status-super' : '' ?>">
                            <?= $isSuperAdmin ? 'Super Admin' : 'Admin' ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-circle-fill"></i>
                            Status
                        </span>
                        <span class="info-value status-active">
                            <?= ucfirst($admin['account_status'] ?? 'Active') ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-calendar-plus-fill"></i>
                            Member Since
                        </span>
                        <span class="info-value">
                            <?= !empty($admin['created_at']) ? date('M j, Y', strtotime($admin['created_at'])) : 'N/A' ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-clock-history"></i>
                            Last Login
                        </span>
                        <span class="info-value">
                            <?= !empty($admin['last_login']) ? date('M j, g:i A', strtotime($admin['last_login'])) : 'First session' ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-shield-lock-fill"></i>
                            Password Age
                        </span>
                        <span class="info-value">
                            <?php if (!empty($admin['password_last_changed'])): ?>
                                <?= timeAgo($admin['password_last_changed']) ?>
                            <?php else: ?>
                                Never changed
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div style="margin-top: 22px; padding-top: 20px; border-top: 2px solid var(--border);">
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="admin-dashboard.php" class="btn btn-outline" style="justify-content: flex-start;">
                            <i class="bi bi-speedometer2"></i> Go to Dashboard
                        </a>
                        <a href="admin-reports.php" class="btn btn-outline" style="justify-content: flex-start;">
                            <i class="bi bi-graph-up-arrow"></i> View Reports
                        </a>
                        <a href="admin-settings.php" class="btn btn-outline" style="justify-content: flex-start;">
                            <i class="bi bi-gear-fill"></i> System Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================
             PERMISSIONS
             ============================================ -->
        <?php if (!empty($permissions)): ?>
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <i class="bi bi-shield-fill-check"></i>
                    My Permissions
                    <span style="background:#e0f2fe; color:#0369a1; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:800;">
                        <?= count($permissions) ?>
                    </span>
                </h3>
            </div>

            <div class="permissions-grid">
                <?php foreach ($permissions as $permKey):
                    if (isset($permissionLabels[$permKey])) {
                        $p = $permissionLabels[$permKey];
                    } else {
                        $p = ['label' => ucwords(str_replace('_', ' ', $permKey)), 'icon' => 'check-circle', 'color' => '#6b7280'];
                    }
                ?>
                    <div class="permission-card">
                        <div class="permission-icon" style="background: <?= $p['color'] ?>;">
                            <i class="bi bi-<?= $p['icon'] ?>"></i>
                        </div>
                        <div class="permission-info">
                            <div class="permission-name"><?= htmlspecialchars($p['label']) ?></div>
                            <div class="permission-status">
                                <i class="bi bi-check-circle-fill"></i>
                                Granted
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================
             SECURITY / CHANGE PASSWORD
             ============================================ -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <i class="bi bi-shield-lock-fill"></i>
                    Security Settings
                </h3>
                <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;">
                    <i class="bi bi-lock-fill"></i> Keep your account secure
                </span>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="change_password">
                <?php echo app_csrf_field(); ?>

                <div class="form-grid">
                    <!-- Current Password -->
                    <div class="form-group full">
                        <label class="form-label">
                            <i class="bi bi-key-fill"></i>
                            Current Password <span class="required">*</span>
                        </label>
                        <div class="input-with-icon has-toggle">
                            <i class="bi bi-lock-fill input-icon"></i>
                            <input type="password" name="current_password" id="currentPassword"
                                   class="form-control" required autocomplete="current-password">
                            <button type="button" class="password-toggle" onclick="togglePass('currentPassword', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- New Password -->
                    <div class="form-group">
                        <label class="form-label">
                            <i class="bi bi-lock-fill"></i>
                            New Password <span class="required">*</span>
                        </label>
                        <div class="input-with-icon has-toggle">
                            <i class="bi bi-shield-lock-fill input-icon"></i>
                            <input type="password" name="new_password" id="newPassword"
                                   class="form-control" required autocomplete="new-password">
                            <button type="button" class="password-toggle" onclick="togglePass('newPassword', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Confirm New Password -->
                    <div class="form-group">
                        <label class="form-label">
                            <i class="bi bi-check-circle-fill"></i>
                            Confirm New Password <span class="required">*</span>
                        </label>
                        <div class="input-with-icon has-toggle">
                            <i class="bi bi-check2-circle input-icon"></i>
                            <input type="password" name="confirm_password" id="confirmPassword"
                                   class="form-control" required autocomplete="new-password">
                            <button type="button" class="password-toggle" onclick="togglePass('confirmPassword', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Password Requirements -->
                    <div class="form-group full">
                        <div style="background: #f9fafb; border-radius: 10px; padding: 14px 16px; border-left: 4px solid var(--umak-yellow);">
                            <div style="font-size: 12px; font-weight: 800; color: #92400e; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                                <i class="bi bi-info-circle-fill"></i>
                                Password Requirements
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 6px; font-size: 12px; color: #78350f; font-weight: 600;">
                                <div>✓ At least 8 characters</div>
                                <div>✓ One uppercase letter</div>
                                <div>✓ One lowercase letter</div>
                                <div>✓ One number</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-yellow">
                        <i class="bi bi-shield-check"></i> Update Password
                    </button>
                </div>
            </form>

            <!-- Remove Avatar -->
            <?php if (!empty($adminAvatar)): ?>
            <form method="POST" style="margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--border);">
                <input type="hidden" name="action" value="remove_avatar">
                <?php echo app_csrf_field(); ?>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <div>
                        <div style="font-size: 13.5px; font-weight: 800; color: #111827;">
                            <i class="bi bi-image-fill"></i> Profile Picture
                        </div>
                        <div style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">
                            Remove your current profile picture.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline"
                            onclick="return confirm('Remove your profile picture?');">
                        <i class="bi bi-trash3-fill"></i> Remove Picture
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <!-- ============================================
             RECENT ACTIVITY LOG
             ============================================ -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <i class="bi bi-clock-history"></i>
                    Recent Activity
                </h3>
                <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;">
                    Last 10 actions
                </span>
            </div>

            <?php if (empty($activityLog)): ?>
                <div class="empty-state">
                    <i class="bi bi-clock-history"></i>
                    <p>No recorded activity yet.</p>
                </div>
            <?php else: ?>
                <div class="activity-list">
                    <?php foreach ($activityLog as $log): ?>
                        <div class="activity-item">
                            <div class="activity-icon">
                                <i class="bi bi-activity"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-action">
                                    <?= htmlspecialchars($log['action'] ?? 'Action') ?>
                                </div>
                                <?php if (!empty($log['details'])): ?>
                                    <div class="activity-detail">
                                        <?= htmlspecialchars($log['details']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="activity-time">
                                <i class="bi bi-clock"></i>
                                <?= timeAgo($log['created_at']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================
             DANGER ZONE
             ============================================ -->
        <div class="danger-zone">
            <div class="danger-zone-header">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Danger Zone
            </div>
            <p>
                Need to deactivate your admin account? Contact the super administrator.
                <?php if (!$isSuperAdmin): ?>
                    Only a super admin can deactivate other admin accounts.
                <?php endif; ?>
            </p>
            <button type="button" class="btn btn-danger"
                    onclick="alert('Please contact the super administrator to deactivate your account.');">
                <i class="bi bi-power"></i> Request Deactivation
            </button>
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
    // PASSWORD VISIBILITY TOGGLE
    // ============================================
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

    // ============================================
    // AUTO-SUBMIT AVATAR UPLOAD
    // ============================================
    document.getElementById('avatarInput')?.addEventListener('change', function() {
        if (this.files.length > 0) {
            document.getElementById('avatarForm').submit();
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
    });
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>