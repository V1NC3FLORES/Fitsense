<?php
// Admin/admin-members.php
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
// HANDLE ACTIONS (POST)
// ============================================
$flashMessage = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_message'] = 'This form has expired or is invalid. Please refresh the page and try again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: admin-members.php');
        exit;
    }

    $targetId = (int)($_POST['member_id'] ?? 0);
    $action = $_POST['action'];

    if ($targetId > 0 && $targetId !== $userId) {

        // Verify target is actually a user (not admin)
        $checkStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $checkStmt->bind_param("i", $targetId);
        $checkStmt->execute();
        $checkRes = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if ($checkRes && $checkRes['role'] === 'user') {

            switch ($action) {

                // -----------------------------------------
                // TOGGLE ACCOUNT STATUS
                // -----------------------------------------
                case 'toggle_status':
                    $newStatus = ($_POST['new_status'] === 'Active') ? 'Active' : 'Inactive';
                    if (executeQuery($conn, "UPDATE users SET account_status = ? WHERE id = ?", "si", [$newStatus, $targetId])) {
                        $flashMessage = "Member status changed to <strong>{$newStatus}</strong> successfully.";
                        $flashType = 'success';
                    }
                    break;

                // -----------------------------------------
                // VERIFY MEMBER MANUALLY
                // -----------------------------------------
                case 'verify':
                    if (executeQuery($conn, "UPDATE users SET is_verified = 1, two_factor_code = NULL, two_factor_expiry = NULL WHERE id = ?", "i", [$targetId])) {
                        $flashMessage = "Member has been verified successfully.";
                        $flashType = 'success';
                    }
                    break;

                // -----------------------------------------
                // DELETE MEMBER
                // -----------------------------------------
                case 'delete':
                    if (executeQuery($conn, "DELETE FROM users WHERE id = ?", "i", [$targetId])) {
                        $flashMessage = "Member deleted successfully.";
                        $flashType = 'success';
                    }
                    break;

                // -----------------------------------------
                // ACTIVATE MEMBERSHIP
                // -----------------------------------------
                case 'activate_membership':
                    if (tableExists($conn, 'memberships')) {
                        // Get the pending membership
                        $getStmt = $conn->prepare("
                            SELECT id, membership_type FROM memberships 
                            WHERE user_id = ? AND status = 'Pending' 
                            ORDER BY created_at DESC LIMIT 1
                        ");
                        $getStmt->bind_param("i", $targetId);
                        $getStmt->execute();
                        $membershipRes = $getStmt->get_result()->fetch_assoc();
                        $getStmt->close();

                        if ($membershipRes) {
                            $startDate = date('Y-m-d');
                            $endDate = null;
                            if ($membershipRes['membership_type'] === 'One Day') {
                                $endDate = date('Y-m-d');
                            } elseif ($membershipRes['membership_type'] === 'Monthly') {
                                $endDate = date('Y-m-d', strtotime('+30 days'));
                            } elseif ($membershipRes['membership_type'] === 'Yearly') {
                                $endDate = date('Y-m-d', strtotime('+365 days'));
                            }

                            executeQuery($conn,
                                "UPDATE memberships SET status = 'Active', start_date = ?, end_date = ? WHERE id = ?",
                                "ssi", [$startDate, $endDate, $membershipRes['id']]
                            );

                            // Notify member
                            if (tableExists($conn, 'notifications')) {
                                $notifTitle = "Membership Activated";
                                $notifMsg = "Your {$membershipRes['membership_type']} membership has been approved and activated.";
                                executeQuery($conn,
                                    "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'membership', ?, ?, NOW())",
                                    "iss", [$targetId, $notifTitle, $notifMsg]
                                );
                            }

                            $flashMessage = "Membership activated successfully.";
                            $flashType = 'success';
                        } else {
                            $flashMessage = "No pending membership found for this member.";
                            $flashType = 'error';
                        }
                    }
                    break;
            }
        }
    }

    // Redirect to prevent resubmission
    if (!empty($flashMessage)) {
        $_SESSION['flash_message'] = $flashMessage;
        $_SESSION['flash_type'] = $flashType;
        header("Location: admin-members.php" . (!empty($_POST['redirect_query']) ? '?' . $_POST['redirect_query'] : ''));
        exit;
    }
}

// ============================================
// FLASH MESSAGE FROM SESSION
// ============================================
if (!empty($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// ============================================
// FILTERS & SEARCH
// ============================================
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$verifiedFilter = $_GET['verified'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build WHERE clause
$where = ["u.role = 'user'"];
$params = [];
$types = '';

if (!empty($search)) {
    $where[] = "(u.fullname LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.phone LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'ssss';
}

if ($statusFilter === 'active') {
    $where[] = "u.account_status = 'Active'";
} elseif ($statusFilter === 'inactive') {
    $where[] = "u.account_status = 'Inactive'";
}

if ($verifiedFilter === 'verified') {
    $where[] = "u.is_verified = 1";
} elseif ($verifiedFilter === 'unverified') {
    $where[] = "u.is_verified = 0";
}

$whereSQL = implode(' AND ', $where);

// Sort
$orderSQL = "u.created_at DESC"; // default newest
if ($sortBy === 'oldest') $orderSQL = "u.created_at ASC";
elseif ($sortBy === 'name_asc') $orderSQL = "u.fullname ASC";
elseif ($sortBy === 'name_desc') $orderSQL = "u.fullname DESC";
elseif ($sortBy === 'active_first') $orderSQL = "u.account_status DESC, u.created_at DESC";

// ============================================
// COUNT TOTAL FOR PAGINATION
// ============================================
$totalMembers = 0;
$countSQL = "SELECT COUNT(*) AS total FROM users u WHERE {$whereSQL}";
if (!empty($params)) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $totalMembers = (int)$row['total'];
    }
    $stmt->close();
} else {
    $result = $conn->query($countSQL);
    if ($result && $row = $result->fetch_assoc()) {
        $totalMembers = (int)$row['total'];
    }
}

$totalPages = max(1, ceil($totalMembers / $perPage));

// ============================================
// FETCH MEMBERS
// ============================================
$members = [];
$fetchSQL = "
    SELECT u.id, u.fullname, u.email, u.phone, u.username, u.account_status, 
           u.is_verified, u.profile_image, u.created_at, u.last_login,
           (SELECT status FROM memberships WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) AS membership_status,
           (SELECT membership_type FROM memberships WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) AS membership_type
    FROM users u
    WHERE {$whereSQL}
    ORDER BY {$orderSQL}
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($fetchSQL);
if ($stmt) {
    $fetchTypes = $types . 'ii';
    $fetchParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($fetchTypes, ...$fetchParams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();
}

// ============================================
// STATS CARDS
// ============================================
$stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'unverified' => 0,
    'new_this_month' => 0,
];

$r = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'user'");
if ($r && $row = $r->fetch_assoc()) $stats['total'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'user' AND account_status = 'Active'");
if ($r && $row = $r->fetch_assoc()) $stats['active'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'user' AND account_status = 'Inactive'");
if ($r && $row = $r->fetch_assoc()) $stats['inactive'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'user' AND is_verified = 0");
if ($r && $row = $r->fetch_assoc()) $stats['unverified'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'user' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
if ($r && $row = $r->fetch_assoc()) $stats['new_this_month'] = (int)$row['total'];

// ============================================
// HELPER FUNCTIONS
// ============================================
function timeAgo($datetime) {
    if (empty($datetime)) return 'Never';
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) != 1 ? 's' : '') . ' ago';
    return date('M j, Y', $timestamp);
}

function getMembershipStatusClass($status) {
    if (empty($status)) return 'info';
    $s = strtolower($status);
    if ($s === 'active') return 'success';
    if ($s === 'pending') return 'warning';
    if (in_array($s, ['expired', 'rejected', 'cancelled'])) return 'danger';
    return 'info';
}

function buildQuery($overrides = []) {
    $params = array_merge($_GET, $overrides);
    return http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members - FitSense Admin</title>
    <link rel="icon" type="image/png" href="../uploads/umak-gym-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --umak-blue: #005b9f;
            --umak-blue-dark: #00467a;
            --umak-blue-light: #0084d1;
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
            font-weight: bold; font-size: 16px; transition: transform 0.2s;
            overflow: hidden;
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar:hover { transform: scale(1.05); }

        /* ============================================
           PAGE CONTENT
           ============================================ */
        .container { max-width: 1400px; margin: 30px auto; padding: 0 25px; }

        .page-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 20px; margin-bottom: 25px; flex-wrap: wrap;
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
        .page-actions { display: flex; gap: 10px; flex-wrap: wrap; }

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
           STATS CARDS
           ============================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px; margin-bottom: 25px;
        }

        .stat-box {
            background: white; border-radius: 16px; padding: 20px 22px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            display: flex; align-items: center; gap: 15px;
            transition: all 0.25s ease;
            text-decoration: none; color: inherit;
        }

        .stat-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }

        .stat-box.active {
            border-color: var(--umak-blue);
            box-shadow: 0 8px 20px rgba(0,91,159,0.12);
        }

        .stat-box-icon {
            width: 50px; height: 50px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
        }

        .stat-box-icon.blue { background: linear-gradient(135deg, rgba(0,91,159,0.12), rgba(0,132,209,0.18)); color: var(--umak-blue); }
        .stat-box-icon.green { background: linear-gradient(135deg, rgba(46,125,50,0.12), rgba(74,222,128,0.18)); color: var(--umak-green); }
        .stat-box-icon.red { background: linear-gradient(135deg, rgba(211,47,47,0.12), rgba(248,113,113,0.18)); color: var(--umak-red); }
        .stat-box-icon.orange { background: linear-gradient(135deg, rgba(245,158,11,0.12), rgba(251,191,36,0.18)); color: #b45309; }
        .stat-box-icon.purple { background: linear-gradient(135deg, rgba(139,92,246,0.12), rgba(167,139,250,0.18)); color: #7c3aed; }

        .stat-box-info { flex: 1; min-width: 0; }
        .stat-box-label { font-size: 11px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .stat-box-value { font-size: 24px; font-weight: 800; color: #111827; line-height: 1.1; letter-spacing: -0.5px; }
        .stat-box-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }

        /* ============================================
           FILTER BAR
           ============================================ */
        .filter-bar {
            background: white; border-radius: 16px; padding: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 22px;
        }

        .filter-row {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
        }

        .search-wrapper {
            position: relative; flex: 1; min-width: 240px;
        }

        .search-wrapper i {
            position: absolute; left: 16px; top: 50%;
            transform: translateY(-50%); color: #9ca3af; font-size: 16px;
        }

        .search-wrapper input {
            width: 100%; padding: 12px 16px 12px 46px;
            border: 2px solid var(--border); border-radius: 10px;
            font-size: 14px; font-weight: 500; font-family: inherit;
            outline: none; transition: all 0.2s; background: #f9fafb;
            color: #111827;
        }

        .search-wrapper input:focus {
            border-color: var(--umak-blue); background: white;
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        .filter-select {
            padding: 12px 38px 12px 16px;
            border: 2px solid var(--border); border-radius: 10px;
            font-size: 13.5px; font-weight: 700; color: #374151;
            background: #f9fafb; cursor: pointer;
            outline: none; transition: all 0.2s;
            appearance: none; font-family: inherit;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%236b7280'%3e%3cpath d='M4.5 6l3.5 3.5L11.5 6z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }

        .filter-select:focus { border-color: var(--umak-blue); }

        .btn {
            padding: 11px 20px; border-radius: 10px; font-size: 13px;
            font-weight: 700; cursor: pointer; border: none;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; transition: all 0.25s ease; text-decoration: none;
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

        .btn-danger {
            background: linear-gradient(135deg, #d32f2f, #ef4444);
            color: white;
        }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(211,47,47,0.3); }

        .btn-sm { padding: 7px 12px; font-size: 12px; }

        /* ============================================
           MEMBERS TABLE
           ============================================ */
        .table-card {
            background: white; border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            overflow: hidden;
            margin-bottom: 22px;
        }

        .table-header {
            padding: 18px 24px; border-bottom: 2px solid var(--border);
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 12px;
        }

        .table-header h3 {
            margin: 0; font-size: 17px; font-weight: 800;
            color: var(--umak-blue);
            display: flex; align-items: center; gap: 10px;
        }

        .table-header h3 i { font-size: 20px; }

        .table-info {
            font-size: 13px; color: var(--text-muted); font-weight: 600;
        }

        .table-wrapper { overflow-x: auto; }

        .data-table {
            width: 100%; border-collapse: collapse; font-size: 13.5px;
        }

        .data-table th {
            padding: 14px 16px; text-align: left;
            color: var(--text-muted); font-weight: 700;
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 1px; background: #f9fafb;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .data-table td {
            padding: 16px; text-align: left;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .data-table tbody tr { transition: background 0.2s; }
        .data-table tbody tr:hover { background: #f9fafb; }
        .data-table tbody tr:last-child td { border-bottom: none; }

        .user-cell {
            display: flex; align-items: center; gap: 12px;
        }

        .user-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 16px; flex-shrink: 0;
            overflow: hidden;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .user-avatar.inactive { background: linear-gradient(135deg, #9ca3af, #6b7280); }

        .user-info { min-width: 0; }
        .user-info .name {
            font-weight: 800; color: #111827; font-size: 14px;
            line-height: 1.2; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
            max-width: 220px;
        }
        .user-info .sub {
            font-size: 12px; color: var(--text-muted);
            margin-top: 2px; display: flex; align-items: center; gap: 6px;
        }

        .user-info .sub i { font-size: 11px; }

        /* Badges */
        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 11px; border-radius: 20px;
            font-size: 10.5px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .badge i { font-size: 9px; }

        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-secondary { background: #f3f4f6; color: #4b5563; }

        /* Action buttons */
        .action-group {
            display: flex; gap: 6px; align-items: center;
        }

        .icon-btn {
            width: 34px; height: 34px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; border: none; font-size: 14px;
            transition: all 0.2s ease;
            background: #f3f4f6; color: #4b5563;
            text-decoration: none;
        }

        .icon-btn:hover { transform: translateY(-2px); }
        .icon-btn.view:hover { background: rgba(0,91,159,0.15); color: var(--umak-blue); }
        .icon-btn.edit:hover { background: rgba(245,158,11,0.15); color: #b45309; }
        .icon-btn.verify:hover { background: rgba(46,125,50,0.15); color: var(--umak-green); }
        .icon-btn.toggle:hover { background: rgba(245,158,11,0.15); color: #b45309; }
        .icon-btn.delete:hover { background: rgba(211,47,47,0.15); color: var(--umak-red); }
        .icon-btn.approve:hover { background: rgba(46,125,50,0.15); color: var(--umak-green); }

        /* Empty State */
        .empty-state {
            text-align: center; padding: 70px 20px;
        }

        .empty-state-icon {
            width: 90px; height: 90px; margin: 0 auto 20px;
            background: rgba(0,91,159,0.08); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 40px; color: var(--umak-blue);
        }

        .empty-state h3 {
            margin: 0 0 10px; color: var(--umak-blue);
            font-size: 20px; font-weight: 800;
        }

        .empty-state p {
            color: var(--text-muted); font-size: 14px;
            margin: 0 0 22px; line-height: 1.6;
            max-width: 400px; margin-left: auto; margin-right: auto;
        }

        /* Pagination */
        .pagination {
            display: flex; justify-content: center; align-items: center;
            gap: 8px; padding: 20px 24px;
            border-top: 1px solid var(--border);
            background: #fafbfc;
            flex-wrap: wrap;
        }

        .page-link {
            min-width: 40px; height: 40px; padding: 0 14px;
            border-radius: 10px; display: inline-flex;
            align-items: center; justify-content: center;
            font-size: 13.5px; font-weight: 700;
            color: #4b5563; background: white;
            border: 1.5px solid var(--border);
            text-decoration: none; transition: all 0.2s ease;
            cursor: pointer;
        }

        .page-link:hover:not(.disabled):not(.active) {
            background: #f3f4f6;
            border-color: var(--umak-blue);
            color: var(--umak-blue);
        }

        .page-link.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; border-color: transparent;
            box-shadow: 0 4px 12px rgba(0,91,159,0.3);
        }

        .page-link.disabled {
            opacity: 0.4; cursor: not-allowed;
        }

        .page-info {
            font-size: 13px; color: var(--text-muted);
            font-weight: 600; margin: 0 8px;
        }

        /* ============================================
           MODAL (for actions confirmation & view)
           ============================================ */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; visibility: hidden;
            transition: all 0.3s ease;
            z-index: 2000; padding: 20px;
        }

        .modal-overlay.open { opacity: 1; visibility: visible; }

        .modal {
            background: white; border-radius: 18px;
            width: 100%; max-width: 480px;
            max-height: 90vh; overflow-y: auto;
            transform: scale(0.9);
            transition: transform 0.3s ease;
            box-shadow: 0 25px 70px rgba(0,0,0,0.3);
        }

        .modal-overlay.open .modal { transform: scale(1); }

        .modal-header {
            padding: 22px 26px;
            border-bottom: 2px solid var(--border);
            display: flex; justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0; font-size: 18px; font-weight: 800;
            color: var(--umak-blue);
            display: flex; align-items: center; gap: 10px;
        }

        .modal-close {
            background: #f3f4f6; border: none;
            width: 36px; height: 36px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center;
            justify-content: center; font-size: 16px;
            color: var(--text-muted); transition: all 0.2s;
        }

        .modal-close:hover { background: #e5e7eb; transform: rotate(90deg); }

        .modal-body { padding: 24px 26px; }

        .modal-footer {
            padding: 18px 26px 22px;
            display: flex; gap: 10px; justify-content: flex-end;
            border-top: 1px solid var(--border);
        }

        .modal-danger-text {
            background: #fee2e2; border-left: 4px solid var(--umak-red);
            padding: 14px 18px; border-radius: 10px;
            font-size: 13.5px; color: #991b1b;
            line-height: 1.6; margin-bottom: 16px;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .filter-row { flex-direction: column; align-items: stretch; }
            .search-wrapper { min-width: auto; }
            .filter-select { width: 100%; }
            .data-table { font-size: 12px; }
            .data-table th, .data-table td { padding: 12px 8px; }
            .user-info .name { max-width: 140px; }
            .stats-row { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 576px) {
            .stats-row { grid-template-columns: 1fr; }
            .user-info .name { max-width: 120px; font-size: 13px; }
            .user-avatar { width: 38px; height: 38px; font-size: 14px; }
            .page-link { min-width: 36px; height: 36px; padding: 0 10px; font-size: 12px; }
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
                <h2>Members</h2>
                <p class="subtitle">Manage gym members and their accounts</p>
            </div>
        </div>

        <div class="header-right">
            <a href="admin-dashboard.php" class="header-btn" title="Dashboard">
                <i class="bi bi-speedometer2"></i>
            </a>
            <a href="admin-notifications.php" class="header-btn" title="Notifications">
                <i class="bi bi-bell-fill"></i>
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
            <div>
                <h1 class="page-title">
                    <i class="bi bi-people-fill"></i>
                    Members
                </h1>
                <p class="page-subtitle">View, search, and manage all gym members.</p>
            </div>
            <div class="page-actions">
                <a href="admin-members-export.php?<?= htmlspecialchars(buildQuery(['export' => 'csv'])) ?>"
                   class="btn btn-outline" target="_blank">
                    <i class="bi bi-download"></i> Export CSV
                </a>
                <a href="admin-members-add.php" class="btn btn-primary">
                    <i class="bi bi-person-plus-fill"></i> Add Member
                </a>
            </div>
        </div>

        <!-- Flash Message -->
        <?php if (!empty($flashMessage)): ?>
            <div class="alert alert-<?= $flashType ?>">
                <i class="bi bi-<?= $flashType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
                <div><?= $flashMessage ?></div>
            </div>
        <?php endif; ?>

        <!-- ============================================
             STATS CARDS (Clickable Filters)
             ============================================ -->
        <div class="stats-row">
            <a href="?status=all&verified=all" class="stat-box <?= ($statusFilter === 'all' && $verifiedFilter === 'all' && empty($search)) ? 'active' : '' ?>">
                <div class="stat-box-icon blue">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Total Members</div>
                    <div class="stat-box-value"><?= number_format($stats['total']) ?></div>
                    <div class="stat-box-sub"><?= $stats['new_this_month'] ?> new this month</div>
                </div>
            </a>

            <a href="?status=active" class="stat-box <?= $statusFilter === 'active' ? 'active' : '' ?>">
                <div class="stat-box-icon green">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Active</div>
                    <div class="stat-box-value"><?= number_format($stats['active']) ?></div>
                    <div class="stat-box-sub">Active accounts</div>
                </div>
            </a>

            <a href="?status=inactive" class="stat-box <?= $statusFilter === 'inactive' ? 'active' : '' ?>">
                <div class="stat-box-icon red">
                    <i class="bi bi-x-circle-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Inactive</div>
                    <div class="stat-box-value"><?= number_format($stats['inactive']) ?></div>
                    <div class="stat-box-sub">Deactivated accounts</div>
                </div>
            </a>

            <a href="?verified=unverified" class="stat-box <?= $verifiedFilter === 'unverified' ? 'active' : '' ?>">
                <div class="stat-box-icon orange">
                    <i class="bi bi-envelope-exclamation-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Unverified</div>
                    <div class="stat-box-value"><?= number_format($stats['unverified']) ?></div>
                    <div class="stat-box-sub">Email not verified</div>
                </div>
            </a>
        </div>

        <!-- ============================================
             FILTER BAR
             ============================================ -->
        <form method="GET" class="filter-bar">
            <div class="filter-row">
                <div class="search-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search by name, email, username, or phone..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>

                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
                </select>

                <select name="verified" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $verifiedFilter === 'all' ? 'selected' : '' ?>>All Verification</option>
                    <option value="verified" <?= $verifiedFilter === 'verified' ? 'selected' : '' ?>>Verified Only</option>
                    <option value="unverified" <?= $verifiedFilter === 'unverified' ? 'selected' : '' ?>>Unverified Only</option>
                </select>

                <select name="sort" class="filter-select" onchange="this.form.submit()">
                    <option value="newest" <?= $sortBy === 'newest' ? 'selected' : '' ?>>Newest First</option>
                    <option value="oldest" <?= $sortBy === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                    <option value="name_asc" <?= $sortBy === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                    <option value="name_desc" <?= $sortBy === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                    <option value="active_first" <?= $sortBy === 'active_first' ? 'selected' : '' ?>>Active First</option>
                </select>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel-fill"></i> Apply
                </button>

                <?php if (!empty($search) || $statusFilter !== 'all' || $verifiedFilter !== 'all'): ?>
                    <a href="admin-members.php" class="btn btn-outline">
                        <i class="bi bi-x-lg"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- ============================================
             MEMBERS TABLE
             ============================================ -->
        <div class="table-card">
            <div class="table-header">
                <h3>
                    <i class="bi bi-list-ul"></i>
                    Member List
                    <?php if ($totalMembers > 0): ?>
                        <span style="background:#e0f2fe; color:#0369a1; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:800;">
                            <?= number_format($totalMembers) ?>
                        </span>
                    <?php endif; ?>
                </h3>
                <div class="table-info">
                    <?php if ($totalMembers > 0): ?>
                        Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalMembers) ?> of <?= $totalMembers ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($members)): ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <h3>No Members Found</h3>
                    <p>
                        <?php if (!empty($search) || $statusFilter !== 'all' || $verifiedFilter !== 'all'): ?>
                            No members match your current filters. Try adjusting your search criteria.
                        <?php else: ?>
                            No members have registered yet. New members will appear here once they sign up.
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($search) || $statusFilter !== 'all' || $verifiedFilter !== 'all'): ?>
                        <a href="admin-members.php" class="btn btn-primary">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Contact</th>
                                <th>Membership</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $m):
                                $initials = strtoupper(substr($m['fullname'] ?? 'U', 0, 1));
                                if (strpos($m['fullname'] ?? '', ' ') !== false) {
                                    $parts = explode(' ', $m['fullname']);
                                    $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
                                }
                                $isActive = $m['account_status'] === 'Active';
                                $isVerified = (int)$m['is_verified'] === 1;
                                $memStatus = $m['membership_status'] ?? null;
                                $memType = $m['membership_type'] ?? null;
                            ?>
                                <tr>
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar <?= !$isActive ? 'inactive' : '' ?>">
                                                <?php if (!empty($m['profile_image']) && file_exists(__DIR__ . '/../' . $m['profile_image'])): ?>
                                                    <img src="../<?= htmlspecialchars($m['profile_image']) ?>" alt="">
                                                <?php else: ?>
                                                    <?= htmlspecialchars($initials) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="user-info">
                                                <div class="name"><?= htmlspecialchars($m['fullname'] ?? 'Unknown') ?></div>
                                                <div class="sub">
                                                    <i class="bi bi-at"></i>
                                                    <?= htmlspecialchars($m['username'] ?? 'n/a') ?>
                                                    <?php if (!$isVerified): ?>
                                                        <span class="badge badge-warning" style="padding: 2px 8px; font-size: 9px;">
                                                            <i class="bi bi-hourglass-split"></i> Unverified
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size: 13px; font-weight: 600; color: #111827;">
                                            <?= htmlspecialchars($m['email'] ?? 'No email') ?>
                                        </div>
                                        <?php if (!empty($m['phone'])): ?>
                                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                                <i class="bi bi-telephone"></i>
                                                <?= htmlspecialchars($m['phone']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($memStatus): ?>
                                            <span class="badge badge-<?= getMembershipStatusClass($memStatus) ?>">
                                                <?= htmlspecialchars($memStatus) ?>
                                            </span>
                                            <?php if ($memType): ?>
                                                <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 4px; font-weight: 600;">
                                                    <?= htmlspecialchars($memType) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">No Plan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isActive): ?>
                                            <span class="badge badge-success">
                                                <i class="bi bi-circle-fill"></i> Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">
                                                <i class="bi bi-circle-fill"></i> Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size: 12.5px; font-weight: 600; color: #374151;">
                                            <?= !empty($m['created_at']) ? date('M j, Y', strtotime($m['created_at'])) : '—' ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                            <?= timeAgo($m['created_at']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-group" style="justify-content: flex-end;">
                                            <a href="admin-member-view.php?id=<?= (int)$m['id'] ?>"
                                               class="icon-btn view" title="View Details">
                                                <i class="bi bi-eye-fill"></i>
                                            </a>

                                            <a href="admin-member-edit.php?id=<?= (int)$m['id'] ?>"
                                               class="icon-btn edit" title="Edit Member">
                                                <i class="bi bi-pencil-fill"></i>
                                            </a>

                                            <?php if (!$isVerified): ?>
                                                <button type="button"
                                                        class="icon-btn verify"
                                                        title="Mark as Verified"
                                                        onclick="confirmAction('verify', <?= (int)$m['id'] ?>, 'Mark this member as verified?')">
                                                    <i class="bi bi-check-circle-fill"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($memStatus === 'Pending'): ?>
                                                <button type="button"
                                                        class="icon-btn approve"
                                                        title="Activate Membership"
                                                        onclick="confirmAction('activate_membership', <?= (int)$m['id'] ?>, 'Activate this member\\'s pending membership?')">
                                                    <i class="bi bi-patch-check-fill"></i>
                                                </button>
                                            <?php endif; ?>

                                            <button type="button"
                                                    class="icon-btn toggle"
                                                    title="<?= $isActive ? 'Deactivate' : 'Activate' ?> Account"
                                                    onclick="toggleStatus(<?= (int)$m['id'] ?>, '<?= $isActive ? 'Inactive' : 'Active' ?>', '<?= htmlspecialchars(addslashes($m['fullname'])) ?>')">
                                                <i class="bi bi-<?= $isActive ? 'pause-circle-fill' : 'play-circle-fill' ?>"></i>
                                            </button>

                                            <button type="button"
                                                    class="icon-btn delete"
                                                    title="Delete Member"
                                                    onclick="confirmDelete(<?= (int)$m['id'] ?>, '<?= htmlspecialchars(addslashes($m['fullname'])) ?>')">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ============================================
                     PAGINATION
                     ============================================ -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <!-- Previous -->
                        <a href="?<?= htmlspecialchars(buildQuery(['page' => max(1, $page - 1)])) ?>"
                           class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>

                        <!-- Page Numbers -->
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1) {
                            echo '<a href="?' . htmlspecialchars(buildQuery(['page' => 1])) . '" class="page-link">1</a>';
                            if ($startPage > 2) echo '<span class="page-info">...</span>';
                        }
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="?<?= htmlspecialchars(buildQuery(['page' => $i])) ?>"
                               class="page-link <?= $i === $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <?php
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) echo '<span class="page-info">...</span>';
                            echo '<a href="?' . htmlspecialchars(buildQuery(['page' => $totalPages])) . '" class="page-link">' . $totalPages . '</a>';
                        }
                        ?>

                        <!-- Next -->
                        <a href="?<?= htmlspecialchars(buildQuery(['page' => min($totalPages, $page + 1)])) ?>"
                           class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ============================================
     ACTION MODAL
     ============================================ -->
<div class="modal-overlay" id="actionModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="bi bi-question-circle-fill"></i> Confirm Action</h3>
            <button class="modal-close" onclick="closeModal()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <p id="modalText" style="margin: 0; font-size: 14.5px; color: #4b5563; line-height: 1.6;"></p>
            <div id="modalDangerBox" style="display: none;">
                <div class="modal-danger-text">
                    <strong><i class="bi bi-exclamation-triangle-fill"></i> Warning:</strong>
                    This action cannot be undone. All member data including memberships, transactions, and records will be permanently deleted.
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
            <form method="POST" id="actionForm" style="display: inline;">
                <input type="hidden" name="action" id="modalAction" value="">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="member_id" id="modalMemberId" value="">
                <input type="hidden" name="new_status" id="modalNewStatus" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-primary" id="modalSubmitBtn">Confirm</button>
            </form>
        </div>
    </div>
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
    // MODAL
    // ============================================
    function openModal() {
        document.getElementById('actionModal').classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        document.getElementById('actionModal').classList.remove('open');
        document.body.style.overflow = '';
        document.getElementById('modalDangerBox').style.display = 'none';
        document.getElementById('modalSubmitBtn').className = 'btn btn-primary';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });

    document.getElementById('actionModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // ============================================
    // CONFIRM ACTION (Verify / Activate Membership)
    // ============================================
    function confirmAction(action, memberId, message) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-question-circle-fill"></i> Confirm Action';
        document.getElementById('modalText').innerText = message;
        document.getElementById('modalAction').value = action;
        document.getElementById('modalMemberId').value = memberId;
        document.getElementById('modalNewStatus').value = '';
        document.getElementById('modalSubmitBtn').className = 'btn btn-primary';
        document.getElementById('modalSubmitBtn').innerText = 'Confirm';
        openModal();
    }

    // ============================================
    // TOGGLE STATUS
    // ============================================
    function toggleStatus(memberId, newStatus, name) {
        const action = newStatus === 'Active' ? 'activate' : 'deactivate';
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-power"></i> ' + (newStatus === 'Active' ? 'Activate' : 'Deactivate') + ' Member';
        document.getElementById('modalText').innerHTML = 'Are you sure you want to <strong>' + action + '</strong> the account of <strong>' + name + '</strong>?<br><br>' + 
            (newStatus === 'Active' 
                ? 'The member will be able to log in and access their account again.'
                : 'The member will not be able to log in until reactivated.');
        document.getElementById('modalAction').value = 'toggle_status';
        document.getElementById('modalMemberId').value = memberId;
        document.getElementById('modalNewStatus').value = newStatus;
        document.getElementById('modalSubmitBtn').className = 'btn ' + (newStatus === 'Active' ? 'btn-primary' : 'btn-danger');
        document.getElementById('modalSubmitBtn').innerText = newStatus === 'Active' ? 'Activate' : 'Deactivate';
        openModal();
    }

    // ============================================
    // DELETE MEMBER
    // ============================================
    function confirmDelete(memberId, name) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-trash3-fill" style="color: #d32f2f;"></i> Delete Member';
        document.getElementById('modalText').innerHTML = 'Are you sure you want to permanently delete <strong>' + name + '</strong>?';
        document.getElementById('modalDangerBox').style.display = 'block';
        document.getElementById('modalAction').value = 'delete';
        document.getElementById('modalMemberId').value = memberId;
        document.getElementById('modalNewStatus').value = '';
        document.getElementById('modalSubmitBtn').className = 'btn btn-danger';
        document.getElementById('modalSubmitBtn').innerText = 'Delete Permanently';
        openModal();
    }

    // ============================================
    // AUTO-DISMISS ALERTS
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert-success');
        alerts.forEach(function(alert) {
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