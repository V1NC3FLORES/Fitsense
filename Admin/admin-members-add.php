<?php
// Admin/admin-members-add.php
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
$adminInitial = strtoupper(substr($adminName, 0, 1));
$adminAvatar = $adminData['profile_image'] ?? '';

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
$flashMessage = '';
$flashType = '';
$newMemberId = 0;
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_member') {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $flashMessage = 'This form has expired or is invalid. Please refresh the page and try again.';
        $flashType = 'error';
    } else {

    // Capture form data (for re-fill on error)
    $formData = [
        'fullname' => trim($_POST['fullname'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'username' => trim($_POST['username'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? '',
        'date_of_birth' => $_POST['date_of_birth'] ?? '',
        'gender' => $_POST['gender'] ?? '',
        'address' => trim($_POST['address'] ?? ''),
        'emergency_contact_name' => trim($_POST['emergency_contact_name'] ?? ''),
        'emergency_contact_phone' => trim($_POST['emergency_contact_phone'] ?? ''),
        'height' => $_POST['height'] ?? '',
        'weight' => $_POST['weight'] ?? '',
        'fitness_goal' => trim($_POST['fitness_goal'] ?? ''),
        'activity_level' => $_POST['activity_level'] ?? '',
        'account_status' => $_POST['account_status'] ?? 'Active',
        'is_verified' => isset($_POST['is_verified']) ? 1 : 0,
        'send_welcome_email' => isset($_POST['send_welcome_email']) ? 1 : 0,
        'create_membership' => isset($_POST['create_membership']) ? 1 : 0,
        'membership_type' => $_POST['membership_type'] ?? '',
        'membership_amount' => $_POST['membership_amount'] ?? 0,
    ];

    $errors = [];

    // ============================================
    // VALIDATION
    // ============================================
    if (empty($formData['fullname']) || strlen($formData['fullname']) < 2) {
        $errors[] = 'Please enter the full name (at least 2 characters).';
    }

    if (empty($formData['email']) || !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($formData['username']) || strlen($formData['username']) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $formData['username'])) {
        $errors[] = 'Username can only contain letters, numbers, and underscores.';
    }

    if (!preg_match('/[A-Za-z]/', $formData['username'])) {
        $errors[] = 'Username must contain at least one letter.';
    }

    if (empty($formData['password'])) {
        $errors[] = 'Password is required.';
    } elseif (strlen($formData['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $formData['password']) ||
              !preg_match('/[a-z]/', $formData['password']) ||
              !preg_match('/[0-9]/', $formData['password'])) {
        $errors[] = 'Password must contain uppercase, lowercase, and numbers.';
    }

    if ($formData['password'] !== $formData['confirm_password']) {
        $errors[] = 'Passwords do not match.';
    }

    // Check duplicates
    if (empty($errors)) {
        $r = safeQuery($conn, "SELECT id FROM users WHERE email = ? LIMIT 1", "s", [$formData['email']]);
        if ($r && $r->num_rows > 0) {
            $errors[] = 'This email is already registered.';
        }

        $r = safeQuery($conn, "SELECT id FROM users WHERE username = ? LIMIT 1", "s", [$formData['username']]);
        if ($r && $r->num_rows > 0) {
            $errors[] = 'This username is already taken.';
        }
    }

    // Validate membership
    if ($formData['create_membership']) {
        if (empty($formData['membership_type'])) {
            $errors[] = 'Please select a membership type.';
        }
        if ((float)$formData['membership_amount'] < 0) {
            $errors[] = 'Membership amount cannot be negative.';
        }
    }

    // ============================================
    // INSERT NEW MEMBER
    // ============================================
    if (empty($errors)) {
        $hashed = password_hash($formData['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $userCols = getTableColumns($conn, 'users');

        // Build INSERT dynamically
        $insertCols = ['fullname', 'email', 'username', 'password', 'role', 'account_status'];
        $placeholders = ['?', '?', '?', '?', "'user'", '?'];
        $values = [
            $formData['fullname'],
            $formData['email'],
            $formData['username'],
            $hashed,
            $formData['account_status'],
        ];
        $types = 'sssss';

        // Optional columns
        $optionalFields = [
            'phone' => ['value' => $formData['phone'], 'type' => 's'],
            'date_of_birth' => ['value' => $formData['date_of_birth'] ?: null, 'type' => 's'],
            'gender' => ['value' => $formData['gender'] ?: null, 'type' => 's'],
            'address' => ['value' => $formData['address'], 'type' => 's'],
            'emergency_contact_name' => ['value' => $formData['emergency_contact_name'], 'type' => 's'],
            'emergency_contact_phone' => ['value' => $formData['emergency_contact_phone'], 'type' => 's'],
            'height' => ['value' => $formData['height'] ?: null, 'type' => 'd'],
            'weight' => ['value' => $formData['weight'] ?: null, 'type' => 'd'],
            'fitness_goal' => ['value' => $formData['fitness_goal'], 'type' => 's'],
            'activity_level' => ['value' => $formData['activity_level'] ?: null, 'type' => 's'],
        ];

        foreach ($optionalFields as $col => $info) {
            if (in_array($col, $userCols)) {
                $insertCols[] = $col;
                $placeholders[] = '?';
                $values[] = $info['value'];
                $types .= $info['type'];
            }
        }

        // is_verified
        if (in_array('is_verified', $userCols)) {
            $insertCols[] = 'is_verified';
            $placeholders[] = '?';
            $values[] = $formData['is_verified'];
            $types .= 'i';
        }

        // created_by (to track who created this member)
        if (in_array('created_by', $userCols)) {
            $insertCols[] = 'created_by';
            $placeholders[] = '?';
            $values[] = $userId;
            $types .= 'i';
        }

        // branch_id (set to 0 for single gym)
        if (in_array('branch_id', $userCols)) {
            $insertCols[] = 'branch_id';
            $placeholders[] = '?';
            $values[] = 0;
            $types .= 'i';
        }

        $sql = "INSERT INTO users (" . implode(', ', $insertCols) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$values);

            if ($stmt->execute()) {
                $newMemberId = $stmt->insert_id;

                // ============================================
                // CREATE MEMBERSHIP (if requested)
                // ============================================
                if ($formData['create_membership'] && tableExists($conn, 'memberships')) {
                    $startDate = date('Y-m-d');
                    $endDate = null;
                    $memType = $formData['membership_type'];
                    $amount = (float)$formData['membership_amount'];

                    if ($memType === 'One Day') $endDate = date('Y-m-d');
                    elseif ($memType === 'Monthly') $endDate = date('Y-m-d', strtotime('+30 days'));
                    elseif ($memType === 'Yearly') $endDate = date('Y-m-d', strtotime('+365 days'));

                    $memCols = getTableColumns($conn, 'memberships');
                    $memInsertCols = ['user_id', 'membership_type', 'amount', 'status'];
                    $memPlaceholders = ['?', '?', '?', "'Active'"];
                    $memValues = [$newMemberId, $memType, $amount];
                    $memTypes = 'isd';

                    if (in_array('start_date', $memCols)) {
                        $memInsertCols[] = 'start_date';
                        $memPlaceholders[] = '?';
                        $memValues[] = $startDate;
                        $memTypes .= 's';
                    }
                    if (in_array('end_date', $memCols)) {
                        $memInsertCols[] = 'end_date';
                        $memPlaceholders[] = '?';
                        $memValues[] = $endDate;
                        $memTypes .= 's';
                    }
                    if (in_array('agreement_accepted', $memCols)) {
                        $memInsertCols[] = 'agreement_accepted';
                        $memPlaceholders[] = '1';
                    }

                    $memSQL = "INSERT INTO memberships (" . implode(', ', $memInsertCols) . ") 
                               VALUES (" . implode(', ', $memPlaceholders) . ")";
                    $memStmt = $conn->prepare($memSQL);
                    if ($memStmt) {
                        $memStmt->bind_param($memTypes, ...$memValues);
                        $memStmt->execute();
                        $memStmt->close();

                        // Also insert transaction record
                        if (tableExists($conn, 'transactions')) {
                            $refNo = 'MEM-' . time() . '-' . rand(1000, 9999);
                            $desc = $memType . ' Membership';
                            $txnCols = getTableColumns($conn, 'transactions');
                            $txnInsertCols = ['user_id', 'reference_no', 'transaction_type', 'description', 'amount', 'status'];
                            $txnPlaceholders = ['?', '?', "'Membership'", '?', '?', "'Paid'"];
                            $txnValues = [$newMemberId, $refNo, $desc, $amount];
                            $txnTypes = 'issd';

                            $txnSQL = "INSERT INTO transactions (" . implode(', ', $txnInsertCols) . ") 
                                       VALUES (" . implode(', ', $txnPlaceholders) . ")";
                            $txnStmt = $conn->prepare($txnSQL);
                            if ($txnStmt) {
                                $txnStmt->bind_param($txnTypes, ...$txnValues);
                                $txnStmt->execute();
                                $txnStmt->close();
                            }
                        }
                    }
                }

                // ============================================
                // SEND WELCOME NOTIFICATION
                // ============================================
                if (tableExists($conn, 'notifications')) {
                    $notifTitle = "Welcome to FitSense!";
                    $notifMsg = "Your account has been created by the admin. " . 
                                ($formData['create_membership'] ? "Your {$formData['membership_type']} membership is now active." : "You can now browse our membership plans.");
                    executeQuery($conn,
                        "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'general', ?, ?, NOW())",
                        "iss", [$newMemberId, $notifTitle, $notifMsg]
                    );
                }

                $flashMessage = "Member <strong>" . htmlspecialchars($formData['fullname']) . "</strong> created successfully!";
                $flashType = 'success';

                // Clear form on success
                $formData = [];

                // Redirect to member view
                if ($newMemberId > 0) {
                    $_SESSION['flash_message'] = $flashMessage;
                    $_SESSION['flash_type'] = 'success';
                    header("Location: admin-member-view.php?id=" . $newMemberId);
                    exit;
                }

            } else {
                $errors[] = 'Failed to create member: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            $errors[] = 'Database error: ' . $conn->error;
        }
    }

    if (!empty($errors)) {
        $flashMessage = implode('<br>', $errors);
        $flashType = 'error';
    }
    }
}

// ============================================
// STATS
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

// Membership prices for the dropdown
$prices = [
    'One Day' => 150,
    'Monthly' => 1500,
    'Yearly' => 15000,
];

// Try to load from system_settings
if (tableExists($conn, 'system_settings')) {
    $r = safeQuery($conn, "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('one_day_price', 'monthly_price', 'yearly_price')");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            if ($row['setting_key'] === 'one_day_price') $prices['One Day'] = (float)$row['setting_value'];
            if ($row['setting_key'] === 'monthly_price') $prices['Monthly'] = (float)$row['setting_value'];
            if ($row['setting_key'] === 'yearly_price') $prices['Yearly'] = (float)$row['setting_value'];
        }
    }
}

// Helper for old value
function old($key, $default = '') {
    global $formData;
    return htmlspecialchars($formData[$key] ?? $default);
}
function isChecked($key) {
    global $formData;
    return !empty($formData[$key]) ? 'checked' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Member - FitSense Admin</title>
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
            cursor: pointer; font-size: 18px;
            color: var(--umak-blue); transition: all 0.2s;
            text-decoration: none;
        }
        .header-btn:hover { background: #e5eef5; }

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

        .breadcrumb {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: var(--text-muted);
            margin-bottom: 15px; font-weight: 600;
        }
        .breadcrumb a {
            color: var(--umak-blue); text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
            transition: 0.2s;
        }
        .breadcrumb a:hover { color: var(--umak-blue-dark); }

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
        .page-subtitle { color: var(--text-muted); font-size: 14px; margin: 0 0 25px; }

        /* Alerts */
        .alert {
            padding: 14px 20px; border-radius: 12px; font-size: 14px;
            font-weight: 600; margin-bottom: 20px; display: flex;
            align-items: center; gap: 12px; line-height: 1.6;
        }
        .alert-success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border-left: 4px solid var(--umak-green); }
        .alert-error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border-left: 4px solid var(--umak-red); }
        .alert i { font-size: 20px; flex-shrink: 0; }

        /* ============================================
           FORM LAYOUT
           ============================================ */
        .form-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 22px;
            align-items: start;
        }

        .panel {
            background: white; border-radius: 20px;
            padding: 28px 30px; border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 22px;
        }

        .panel-header {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 22px; padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
        }

        .panel-header-icon {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; display: flex; align-items: center; justify-content: center;
            font-size: 20px; flex-shrink: 0;
        }

        .panel-header-text h3 {
            margin: 0 0 4px; font-size: 17px; font-weight: 800;
            color: #111827;
        }
        .panel-header-text p {
            margin: 0; font-size: 12.5px;
            color: var(--text-muted); font-weight: 500;
        }

        /* ============================================
           FORM CONTROLS
           ============================================ */
        .form-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 18px;
        }
        .form-grid-3 {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px;
        }

        .form-group {
            display: flex; flex-direction: column; gap: 6px;
        }
        .form-group.full { grid-column: 1 / -1; }

        .form-label {
            font-size: 12px; font-weight: 800;
            color: #374151; text-transform: uppercase;
            letter-spacing: 0.8px; display: flex;
            align-items: center; gap: 6px;
        }
        .form-label i { color: var(--umak-blue); font-size: 13px; }
        .form-label .required { color: var(--umak-red); }
        .form-label .optional {
            color: var(--text-muted);
            font-size: 10.5px; font-weight: 600;
            text-transform: none; letter-spacing: 0;
        }

        .form-control {
            padding: 12px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px; font-size: 14px;
            font-weight: 500; transition: all 0.2s;
            font-family: inherit; width: 100%;
            color: var(--text); background: white;
            outline: none;
        }
        .form-control:focus {
            border-color: var(--umak-blue);
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }
        textarea.form-control {
            resize: vertical; min-height: 80px; line-height: 1.6;
        }
        select.form-control { cursor: pointer; }

        /* Password wrapper */
        .password-wrapper { position: relative; }
        .password-wrapper .form-control { padding-right: 50px; }
        .toggle-pass {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--text-muted); cursor: pointer;
            font-size: 18px; padding: 4px;
            transition: color 0.2s;
        }
        .toggle-pass:hover { color: var(--umak-blue); }

        /* Password strength */
        .password-strength {
            height: 5px; margin-top: 6px;
            border-radius: 3px; background: #f3f4f6;
            overflow: hidden;
        }
        .password-strength-fill {
            height: 100%; width: 0%;
            transition: all 0.3s;
            background: var(--umak-red);
        }
        .password-hint {
            font-size: 11.5px; color: var(--text-muted);
            font-weight: 600; margin-top: 4px;
            line-height: 1.6;
        }

        /* Toggle switch (small) */
        .setting-toggle-item {
            display: flex; align-items: center;
            justify-content: space-between; gap: 14px;
            padding: 14px 16px;
            background: #f9fafb;
            border-radius: 12px;
            border: 1px solid var(--border);
            margin-bottom: 10px;
            transition: 0.2s;
        }
        .setting-toggle-item:hover {
            background: white;
            border-color: rgba(0,91,159,0.15);
        }
        .setting-toggle-info { flex: 1; min-width: 0; }
        .setting-toggle-label {
            font-size: 13.5px; font-weight: 800;
            color: #111827; margin-bottom: 2px;
            display: flex; align-items: center; gap: 8px;
        }
        .setting-toggle-label i { color: var(--umak-blue); font-size: 15px; }
        .setting-toggle-desc {
            font-size: 12px; color: var(--text-muted);
            line-height: 1.5;
        }

        .toggle-switch {
            position: relative; display: inline-block;
            width: 48px; height: 26px; flex-shrink: 0;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #d1d5db;
            border-radius: 30px; transition: 0.3s;
        }
        .toggle-slider:before {
            position: absolute; content: "";
            height: 20px; width: 20px;
            left: 3px; bottom: 3px;
            background-color: white; border-radius: 50%;
            transition: 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }
        .toggle-switch input:checked + .toggle-slider {
            background: linear-gradient(135deg, #005b9f, #0084d1);
        }
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(22px);
        }

        /* Membership selection */
        .membership-options {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            margin-bottom: 14px;
        }
        .membership-option {
            padding: 14px 12px;
            border: 2px solid var(--border);
            border-radius: 12px;
            background: #f9fafb;
            cursor: pointer; text-align: center;
            transition: 0.2s;
        }
        .membership-option:hover {
            border-color: rgba(0,91,159,0.3);
            background: white;
        }
        .membership-option.selected {
            border-color: var(--umak-blue);
            background: linear-gradient(135deg, #f0f7fc, #ffffff);
            box-shadow: 0 4px 12px rgba(0,91,159,0.1);
        }
        .membership-option-title {
            font-size: 13px; font-weight: 800;
            color: #111827; margin-bottom: 4px;
        }
        .membership-option-price {
            font-size: 12px; color: var(--umak-blue);
            font-weight: 800;
        }

        /* ============================================
           BUTTONS
           ============================================ */
        .btn {
            padding: 13px 24px; border-radius: 10px;
            font-size: 14px; font-weight: 800;
            cursor: pointer; border: none;
            display: inline-flex; align-items: center;
            justify-content: center; gap: 8px;
            transition: all 0.25s; text-decoration: none;
            font-family: inherit; letter-spacing: 0.3px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 12px rgba(0,91,159,0.25);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,91,159,0.35);
            color: white;
        }
        .btn-outline {
            background: white; color: var(--umak-blue);
            border: 2px solid var(--umak-blue);
        }
        .btn-outline:hover { background: var(--umak-blue); color: white; }
        .btn i { font-size: 15px; }

        .form-actions {
            display: flex; gap: 10px;
            justify-content: flex-end;
            margin-top: 25px; padding-top: 20px;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        /* Info sidebar */
        .info-sidebar {
            position: sticky; top: 100px;
        }

        .tip-card {
            padding: 16px;
            border-radius: 12px;
            background: #f0f7fc;
            border-left: 4px solid var(--umak-blue);
            margin-bottom: 12px;
        }
        .tip-card:last-child { margin-bottom: 0; }
        .tip-card-title {
            font-size: 12px; font-weight: 800;
            color: var(--umak-blue); text-transform: uppercase;
            letter-spacing: 1px; margin-bottom: 6px;
            display: flex; align-items: center; gap: 6px;
        }
        .tip-card-text {
            font-size: 12.5px; color: #1e40af;
            line-height: 1.6; font-weight: 500;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
            .form-layout { grid-template-columns: 1fr; }
            .info-sidebar { position: static; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .form-grid, .form-grid-3 { grid-template-columns: 1fr; }
            .membership-options { grid-template-columns: 1fr; }
            .form-actions .btn { flex: 1; }
            .panel { padding: 22px 18px; }
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
        <a href="admin-members.php" class="nav-item active">
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
                <h2>Add New Member</h2>
                <p class="subtitle">Create a new member account</p>
            </div>
        </div>

        <div class="header-right">
            <a href="admin-dashboard.php" class="header-btn" title="Dashboard">
                <i class="bi bi-speedometer2"></i>
            </a>
            <a href="admin-members.php" class="header-btn" title="Members">
                <i class="bi bi-people-fill"></i>
            </a>
            <div class="avatar">
                <?php if (!empty($adminAvatar) && file_exists(__DIR__ . '/../' . $adminAvatar)): ?>
                    <img src="../<?= htmlspecialchars($adminAvatar) ?>" alt="">
                <?php else: ?>
                    <?= htmlspecialchars($adminInitial) ?>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="container">

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="admin-members.php">
                <i class="bi bi-people-fill"></i> Members
            </a>
            <i class="bi bi-chevron-right" style="font-size: 11px; opacity: 0.5;"></i>
            <span>Add New Member</span>
        </div>

        <!-- Page Header -->
        <h1 class="page-title">
            <i class="bi bi-person-plus-fill"></i>
            Add New Member
        </h1>
        <p class="page-subtitle">Create a new member account. All fields marked with * are required.</p>

        <!-- Flash Message -->
        <?php if (!empty($flashMessage)): ?>
            <div class="alert alert-<?= $flashType ?>">
                <i class="bi bi-<?= $flashType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
                <div><?= $flashMessage ?></div>
            </div>
        <?php endif; ?>

        <!-- ============================================
             FORM LAYOUT
             ============================================ -->
        <form method="POST" action="" autocomplete="off" id="addMemberForm">
            <input type="hidden" name="action" value="add_member">
            <?php echo app_csrf_field(); ?>

            <div class="form-layout">

                <!-- LEFT: Main Form -->
                <div>

                    <!-- ============================================
                         SECTION 1: ACCOUNT CREDENTIALS
                         ============================================ -->
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-header-icon">
                                <i class="bi bi-shield-lock-fill"></i>
                            </div>
                            <div class="panel-header-text">
                                <h3>Account Credentials</h3>
                                <p>Login information for the new member</p>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-person-fill"></i>
                                    Username <span class="required">*</span>
                                </label>
                                <input type="text" name="username" class="form-control"
                                       value="<?= old('username') ?>"
                                       placeholder="e.g., juan_dc"
                                       pattern="[A-Za-z0-9_]+"
                                       minlength="3" maxlength="30" required>
                                <div class="password-hint">Letters, numbers, underscores only</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-envelope-fill"></i>
                                    Email Address <span class="required">*</span>
                                </label>
                                <input type="email" name="email" class="form-control"
                                       value="<?= old('email') ?>"
                                       placeholder="member@umak.edu.ph" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-lock-fill"></i>
                                    Password <span class="required">*</span>
                                </label>
                                <div class="password-wrapper">
                                    <input type="password" name="password" id="passwordInput" class="form-control"
                                           placeholder="Min 8 chars, A-Z, a-z, 0-9" required>
                                    <button type="button" class="toggle-pass" onclick="togglePass('passwordInput', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="password-strength">
                                    <div class="password-strength-fill" id="pwStrengthFill"></div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-lock-fill"></i>
                                    Confirm Password <span class="required">*</span>
                                </label>
                                <div class="password-wrapper">
                                    <input type="password" name="confirm_password" id="confirmInput" class="form-control"
                                           placeholder="Re-enter password" required>
                                    <button type="button" class="toggle-pass" onclick="togglePass('confirmInput', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ============================================
                         SECTION 2: PERSONAL INFORMATION
                         ============================================ -->
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-header-icon">
                                <i class="bi bi-person-vcard"></i>
                            </div>
                            <div class="panel-header-text">
                                <h3>Personal Information</h3>
                                <p>Member's basic details</p>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group full">
                                <label class="form-label">
                                    <i class="bi bi-person-badge-fill"></i>
                                    Full Name <span class="required">*</span>
                                </label>
                                <input type="text" name="fullname" class="form-control"
                                       value="<?= old('fullname') ?>"
                                       placeholder="e.g., Juan Dela Cruz" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-telephone-fill"></i>
                                    Phone Number <span class="optional">(Optional)</span>
                                </label>
                                <input type="tel" name="phone" class="form-control"
                                       value="<?= old('phone') ?>"
                                       placeholder="+63 9XX XXX XXXX">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-calendar-heart-fill"></i>
                                    Date of Birth <span class="optional">(Optional)</span>
                                </label>
                                <input type="date" name="date_of_birth" class="form-control"
                                       value="<?= old('date_of_birth') ?>"
                                       max="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-gender-ambiguous"></i>
                                    Gender <span class="optional">(Optional)</span>
                                </label>
                                <select name="gender" class="form-control">
                                    <option value="">Prefer not to say</option>
                                    <option value="Male" <?= old('gender') === 'Male' ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= old('gender') === 'Female' ? 'selected' : '' ?>>Female</option>
                                    <option value="Other" <?= old('gender') === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-geo-alt-fill"></i>
                                    Address <span class="optional">(Optional)</span>
                                </label>
                                <input type="text" name="address" class="form-control"
                                       value="<?= old('address') ?>"
                                       placeholder="Street, Barangay, City">
                            </div>
                        </div>
                    </div>

                    <!-- ============================================
                         SECTION 3: FITNESS PROFILE
                         ============================================ -->
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-header-icon" style="background: linear-gradient(135deg, #10b981, #4ade80);">
                                <i class="bi bi-heart-pulse-fill"></i>
                            </div>
                            <div class="panel-header-text">
                                <h3>Fitness Profile</h3>
                                <p>Physical information and goals</p>
                            </div>
                        </div>

                        <div class="form-grid-3">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-rulers"></i>
                                    Height (cm)
                                </label>
                                <input type="number" name="height" class="form-control"
                                       value="<?= old('height') ?>"
                                       placeholder="e.g., 170"
                                       step="0.01" min="50" max="250">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-speedometer2"></i>
                                    Weight (kg)
                                </label>
                                <input type="number" name="weight" class="form-control"
                                       value="<?= old('weight') ?>"
                                       placeholder="e.g., 65"
                                       step="0.01" min="20" max="300">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-activity"></i>
                                    Activity Level
                                </label>
                                <select name="activity_level" class="form-control">
                                    <option value="">Select level</option>
                                    <option value="Sedentary" <?= old('activity_level') === 'Sedentary' ? 'selected' : '' ?>>Sedentary</option>
                                    <option value="Light" <?= old('activity_level') === 'Light' ? 'selected' : '' ?>>Light</option>
                                    <option value="Moderate" <?= old('activity_level') === 'Moderate' ? 'selected' : '' ?>>Moderate</option>
                                    <option value="Active" <?= old('activity_level') === 'Active' ? 'selected' : '' ?>>Active</option>
                                    <option value="Very Active" <?= old('activity_level') === 'Very Active' ? 'selected' : '' ?>>Very Active</option>
                                </select>
                            </div>

                            <div class="form-group full">
                                <label class="form-label">
                                    <i class="bi bi-bullseye"></i>
                                    Fitness Goal
                                </label>
                                <input type="text" name="fitness_goal" class="form-control"
                                       value="<?= old('fitness_goal') ?>"
                                       placeholder="e.g., Build muscle, lose weight, improve endurance">
                            </div>
                        </div>
                    </div>

                    <!-- ============================================
                         SECTION 4: EMERGENCY CONTACT
                         ============================================ -->
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-header-icon" style="background: linear-gradient(135deg, #d32f2f, #ef4444);">
                                <i class="bi bi-telephone-plus-fill"></i>
                            </div>
                            <div class="panel-header-text">
                                <h3>Emergency Contact</h3>
                                <p>Person to contact in case of emergency</p>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-person-fill"></i>
                                    Contact Name
                                </label>
                                <input type="text" name="emergency_contact_name" class="form-control"
                                       value="<?= old('emergency_contact_name') ?>"
                                       placeholder="Full name">
                            </div>

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-telephone-fill"></i>
                                    Contact Phone
                                </label>
                                <input type="tel" name="emergency_contact_phone" class="form-control"
                                       value="<?= old('emergency_contact_phone') ?>"
                                       placeholder="+63 9XX XXX XXXX">
                            </div>
                        </div>
                    </div>

                    <!-- ============================================
                         SECTION 5: MEMBERSHIP (OPTIONAL)
                         ============================================ -->
                    <div class="panel">
                        <div class="panel-header">
                            <div class="panel-header-icon" style="background: linear-gradient(135deg, #ffce00, #ffb800); color: #333;">
                                <i class="bi bi-ticket-perforated-fill"></i>
                            </div>
                            <div class="panel-header-text">
                                <h3>Membership (Optional)</h3>
                                <p>Create a membership plan for this member</p>
                            </div>
                        </div>

                        <div class="setting-toggle-item">
                            <div class="setting-toggle-info">
                                <div class="setting-toggle-label">
                                    <i class="bi bi-plus-circle-fill"></i>
                                    Create Membership
                                </div>
                                <div class="setting-toggle-desc">
                                    Automatically activate a membership for this member. A transaction record will also be created.
                                </div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="create_membership" id="createMembership"
                                       <?= isChecked('create_membership') ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div id="membershipOptions" style="display: <?= !empty($formData['create_membership']) ? 'block' : 'none' ?>;">
                            <label class="form-label" style="margin-top: 12px;">
                                <i class="bi bi-card-checklist"></i>
                                Select Plan <span class="required">*</span>
                            </label>

                            <div class="membership-options">
                                <?php
                                $planIcons = [
                                    'One Day' => ['icon' => 'sun-fill', 'label' => 'One Day'],
                                    'Monthly' => ['icon' => 'calendar-month-fill', 'label' => 'Monthly'],
                                    'Yearly' => ['icon' => 'calendar-heart-fill', 'label' => 'Yearly'],
                                ];
                                foreach ($planIcons as $planKey => $planInfo):
                                ?>
                                    <div class="membership-option"
                                         data-plan="<?= $planKey ?>"
                                         data-price="<?= $prices[$planKey] ?>"
                                         onclick="selectPlan(this, '<?= $planKey ?>', <?= $prices[$planKey] ?>)">
                                        <div style="font-size: 22px; color: var(--umak-blue); margin-bottom: 8px;">
                                            <i class="bi bi-<?= $planInfo['icon'] ?>"></i>
                                        </div>
                                        <div class="membership-option-title"><?= $planInfo['label'] ?></div>
                                        <div class="membership-option-price">₱<?= number_format($prices[$planKey], 0) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <input type="hidden" name="membership_type" id="membershipType" value="<?= old('membership_type') ?>">

                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-cash-stack"></i>
                                    Amount
                                </label>
                                <input type="number" name="membership_amount" id="membershipAmount" class="form-control"
                                       value="<?= old('membership_amount') ?>"
                                       step="0.01" min="0">
                            </div>
                        </div>
                    </div>

                    <!-- ============================================
                         FORM ACTIONS
                         ============================================ -->
                    <div class="panel" style="padding: 20px 24px;">
                        <div class="form-actions" style="margin-top: 0; padding-top: 0; border-top: none;">
                            <a href="admin-members.php" class="btn btn-outline">
                                <i class="bi bi-x-lg"></i> Cancel
                            </a>
                            <button type="reset" class="btn btn-outline">
                                <i class="bi bi-arrow-clockwise"></i> Reset
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-person-plus-fill"></i> Create Member
                            </button>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Info Sidebar -->
                <aside class="info-sidebar">

                    <!-- Account Settings -->
                    <div class="panel" style="padding: 20px 22px;">
                        <div class="panel-header" style="margin-bottom: 16px;">
                            <div class="panel-header-icon" style="width: 36px; height: 36px; font-size: 16px;">
                                <i class="bi bi-gear-fill"></i>
                            </div>
                            <div class="panel-header-text">
                                <h3 style="font-size: 15px;">Account Options</h3>
                            </div>
                        </div>

                        <div class="setting-toggle-item">
                            <div class="setting-toggle-info">
                                <div class="setting-toggle-label">
                                    <i class="bi bi-check-circle-fill"></i>
                                    Mark as Verified
                                </div>
                                <div class="setting-toggle-desc" style="font-size: 11.5px;">
                                    Skip email verification
                                </div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="is_verified" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="setting-toggle-item">
                            <div class="setting-toggle-info">
                                <div class="setting-toggle-label">
                                    <i class="bi bi-envelope-heart-fill"></i>
                                    Welcome Email
                                </div>
                                <div class="setting-toggle-desc" style="font-size: 11.5px;">
                                    Send credentials to member
                                </div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="send_welcome_email" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="form-group" style="margin-top: 14px;">
                            <label class="form-label">
                                <i class="bi bi-circle-fill"></i>
                                Account Status
                            </label>
                            <select name="account_status" class="form-control">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <!-- Tips -->
                    <div class="panel" style="padding: 20px 22px;">
                        <div class="panel-header" style="margin-bottom: 16px;">
                            <div class="panel-header-icon" style="width: 36px; height: 36px; font-size: 16px; background: linear-gradient(135deg, #f59e0b, #fbbf24);">
                                <i class="bi bi-lightbulb-fill"></i>
                            </div>
                            <div class="panel-header-text">
                                <h3 style="font-size: 15px;">Tips</h3>
                            </div>
                        </div>

                        <div class="tip-card">
                            <div class="tip-card-title">
                                <i class="bi bi-shield-check"></i> Password
                            </div>
                            <div class="tip-card-text">
                                Use a strong temporary password. The member can change it later from their settings.
                            </div>
                        </div>

                        <div class="tip-card">
                            <div class="tip-card-title">
                                <i class="bi bi-person-vcard"></i> Complete Info
                            </div>
                            <div class="tip-card-text">
                                Filling in more details now reduces follow-up work later. Emergency contacts are important for safety.
                            </div>
                        </div>

                        <div class="tip-card">
                            <div class="tip-card-title">
                                <i class="bi bi-ticket-perforated-fill"></i> Membership
                            </div>
                            <div class="tip-card-text">
                                Enabling membership will auto-generate a transaction record and activate the plan immediately.
                            </div>
                        </div>
                    </div>

                </aside>

            </div>
        </form>

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
    // PASSWORD STRENGTH METER
    // ============================================
    const pwInput = document.getElementById('passwordInput');
    const pwFill = document.getElementById('pwStrengthFill');
    if (pwInput && pwFill) {
        pwInput.addEventListener('input', function() {
            const pw = this.value;
            let score = 0;
            if (pw.length >= 8) score++;
            if (/[A-Z]/.test(pw)) score++;
            if (/[a-z]/.test(pw)) score++;
            if (/[0-9]/.test(pw)) score++;
            if (/[\W_]/.test(pw)) score++;

            const colors = ['#ef4444', '#f59e0b', '#f59e0b', '#10b981', '#059669'];
            const widths = ['20%', '40%', '60%', '80%', '100%'];
            pwFill.style.width = widths[Math.max(0, score - 1)] || '0%';
            pwFill.style.background = colors[Math.max(0, score - 1)] || '#ef4444';
        });
    }

    // ============================================
    // MEMBERSHIP TOGGLE
    // ============================================
    const createMembership = document.getElementById('createMembership');
    const membershipOptions = document.getElementById('membershipOptions');
    if (createMembership && membershipOptions) {
        createMembership.addEventListener('change', function() {
            membershipOptions.style.display = this.checked ? 'block' : 'none';
        });
    }

    // ============================================
    // SELECT MEMBERSHIP PLAN
    // ============================================
    function selectPlan(el, plan, price) {
        document.querySelectorAll('.membership-option').forEach(function(o) {
            o.classList.remove('selected');
        });
        el.classList.add('selected');
        document.getElementById('membershipType').value = plan;
        document.getElementById('membershipAmount').value = price;
    }

    // Restore selection on load (if error)
    document.addEventListener('DOMContentLoaded', function() {
        const savedType = document.getElementById('membershipType')?.value;
        if (savedType) {
            document.querySelectorAll('.membership-option').forEach(function(o) {
                if (o.dataset.plan === savedType) {
                    o.classList.add('selected');
                }
            });
        }
    });

    // ============================================
    // FORM VALIDATION
    // ============================================
    document.getElementById('addMemberForm').addEventListener('submit', function(e) {
        const pw = document.getElementById('passwordInput').value;
        const confirm = document.getElementById('confirmInput').value;

        if (pw !== confirm) {
            e.preventDefault();
            alert('Passwords do not match. Please check and try again.');
            document.getElementById('confirmInput').focus();
            return false;
        }

        if (pw.length < 8 || !/[A-Z]/.test(pw) || !/[a-z]/.test(pw) || !/[0-9]/.test(pw)) {
            e.preventDefault();
            alert('Password must be at least 8 characters and include uppercase, lowercase, and numbers.');
            document.getElementById('passwordInput').focus();
            return false;
        }

        const createMem = document.getElementById('createMembership');
        if (createMem && createMem.checked) {
            const memType = document.getElementById('membershipType').value;
            if (!memType) {
                e.preventDefault();
                alert('Please select a membership plan.');
                return false;
            }
        }
    });
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>