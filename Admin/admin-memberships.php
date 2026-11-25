<?php
// Admin/admin-memberships.php
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
// HANDLE MEMBERSHIP ACTIONS
// ============================================
$flashMessage = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_message'] = 'This form has expired or is invalid. Please refresh the page and try again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: admin-memberships.php');
        exit;
    }

    $membershipId = (int)($_POST['membership_id'] ?? 0);
    $action = $_POST['action'];
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($membershipId > 0 && tableExists($conn, 'memberships')) {

        // Fetch the membership
        $getStmt = $conn->prepare("
            SELECT m.*, u.fullname, u.email, u.id AS user_id
            FROM memberships m
            INNER JOIN users u ON m.user_id = u.id
            WHERE m.id = ?
            LIMIT 1
        ");
        $getStmt->bind_param("i", $membershipId);
        $getStmt->execute();
        $membershipRes = $getStmt->get_result()->fetch_assoc();
        $getStmt->close();

        if ($membershipRes) {

            switch ($action) {

                // -----------------------------------------
                // APPROVE MEMBERSHIP
                // -----------------------------------------
                case 'approve':
                    $startDate = date('Y-m-d');
                    $endDate = null;
                    $type = $membershipRes['membership_type'];

                    if ($type === 'One Day') {
                        $endDate = date('Y-m-d');
                    } elseif ($type === 'Monthly') {
                        $endDate = date('Y-m-d', strtotime('+30 days'));
                    } elseif ($type === 'Yearly') {
                        $endDate = date('Y-m-d', strtotime('+365 days'));
                    } else {
                        $endDate = date('Y-m-d', strtotime('+30 days'));
                    }

                    $ok = executeQuery($conn,
                        "UPDATE memberships 
                         SET status = 'Active', start_date = ?, end_date = ? 
                         WHERE id = ?",
                        "ssi", [$startDate, $endDate, $membershipId]
                    );

                    if ($ok) {
                        // Mark paid transaction if any
                        if (tableExists($conn, 'transactions')) {
                            executeQuery($conn,
                                "UPDATE transactions 
                                 SET status = 'Paid' 
                                 WHERE user_id = ? AND transaction_type = 'Membership' AND status = 'Pending'",
                                "i", [$membershipRes['user_id']]
                            );
                        }

                        // Send notification to member
                        if (tableExists($conn, 'notifications')) {
                            $notifTitle = "Membership Approved";
                            $notifMsg = "Congratulations! Your {$type} membership has been approved. It's valid until " . date('F j, Y', strtotime($endDate)) . ".";
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'membership', ?, ?, NOW())",
                                "iss", [$membershipRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }

                        $flashMessage = "Membership approved for <strong>" . htmlspecialchars($membershipRes['fullname']) . "</strong>.";
                        $flashType = 'success';
                    } else {
                        $flashMessage = "Failed to approve membership.";
                        $flashType = 'error';
                    }
                    break;

                // -----------------------------------------
                // REJECT MEMBERSHIP
                // -----------------------------------------
                case 'reject':
                    $ok = executeQuery($conn,
                        "UPDATE memberships SET status = 'Rejected' WHERE id = ?",
                        "i", [$membershipId]
                    );

                    if ($ok) {
                        // Cancel related pending transaction
                        if (tableExists($conn, 'transactions')) {
                            executeQuery($conn,
                                "UPDATE transactions 
                                 SET status = 'Cancelled' 
                                 WHERE user_id = ? AND transaction_type = 'Membership' AND status = 'Pending'",
                                "i", [$membershipRes['user_id']]
                            );
                        }

                        // Notify member
                        if (tableExists($conn, 'notifications')) {
                            $notifTitle = "Membership Rejected";
                            $notifMsg = "Unfortunately, your {$membershipRes['membership_type']} membership application was rejected." . 
                                        (!empty($adminNote) ? " Reason: {$adminNote}" : "");
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'membership', ?, ?, NOW())",
                                "iss", [$membershipRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }

                        $flashMessage = "Membership rejected for <strong>" . htmlspecialchars($membershipRes['fullname']) . "</strong>.";
                        $flashType = 'success';
                    }
                    break;

                // -----------------------------------------
                // CANCEL MEMBERSHIP
                // -----------------------------------------
                case 'cancel':
                    $ok = executeQuery($conn,
                        "UPDATE memberships SET status = 'Cancelled' WHERE id = ?",
                        "i", [$membershipId]
                    );

                    if ($ok) {
                        if (tableExists($conn, 'notifications')) {
                            $notifTitle = "Membership Cancelled";
                            $notifMsg = "Your {$membershipRes['membership_type']} membership has been cancelled by an administrator.";
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'membership', ?, ?, NOW())",
                                "iss", [$membershipRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }

                        $flashMessage = "Membership cancelled.";
                        $flashType = 'success';
                    }
                    break;

                // -----------------------------------------
                // REACTIVATE (Extend)
                // -----------------------------------------
                case 'reactivate':
                    $extendDays = (int)($_POST['extend_days'] ?? 30);
                    $newEnd = date('Y-m-d', strtotime("+{$extendDays} days"));

                    $ok = executeQuery($conn,
                        "UPDATE memberships 
                         SET status = 'Active', 
                             start_date = CURDATE(), 
                             end_date = ? 
                         WHERE id = ?",
                        "si", [$newEnd, $membershipId]
                    );

                    if ($ok) {
                        if (tableExists($conn, 'notifications')) {
                            $notifTitle = "Membership Reactivated";
                            $notifMsg = "Your membership has been reactivated for {$extendDays} days. Valid until " . date('F j, Y', strtotime($newEnd)) . ".";
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'membership', ?, ?, NOW())",
                                "iss", [$membershipRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }

                        $flashMessage = "Membership reactivated for {$extendDays} days.";
                        $flashType = 'success';
                    }
                    break;

                // -----------------------------------------
                // EXTEND EXPIRY
                // -----------------------------------------
                case 'extend':
                    $extendDays = (int)($_POST['extend_days'] ?? 30);
                    $currentEnd = $membershipRes['end_date'] ?? date('Y-m-d');
                    $newEnd = date('Y-m-d', strtotime($currentEnd . " +{$extendDays} days"));

                    $ok = executeQuery($conn,
                        "UPDATE memberships SET end_date = ? WHERE id = ?",
                        "si", [$newEnd, $membershipId]
                    );

                    if ($ok) {
                        if (tableExists($conn, 'notifications')) {
                            $notifTitle = "Membership Extended";
                            $notifMsg = "Your membership has been extended by {$extendDays} days. New expiry: " . date('F j, Y', strtotime($newEnd)) . ".";
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'membership', ?, ?, NOW())",
                                "iss", [$membershipRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }

                        $flashMessage = "Membership extended by {$extendDays} days.";
                        $flashType = 'success';
                    }
                    break;

                // -----------------------------------------
                // DELETE MEMBERSHIP RECORD
                // -----------------------------------------
                case 'delete':
                    $ok = executeQuery($conn, "DELETE FROM memberships WHERE id = ?", "i", [$membershipId]);
                    if ($ok) {
                        $flashMessage = "Membership record deleted permanently.";
                        $flashType = 'success';
                    }
                    break;
            }
        }
    }

    if (!empty($flashMessage)) {
        $_SESSION['flash_message'] = $flashMessage;
        $_SESSION['flash_type'] = $flashType;
        header("Location: admin-memberships.php" . (!empty($_POST['redirect_query']) ? '?' . $_POST['redirect_query'] : ''));
        exit;
    }
}

if (!empty($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// ============================================
// FILTERS
// ============================================
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build WHERE
$where = ["1=1"];
$params = [];
$types = '';

if ($statusFilter !== 'all') {
    $where[] = "m.status = ?";
    $params[] = ucfirst($statusFilter);
    $types .= 's';
}

if ($typeFilter !== 'all') {
    $where[] = "m.membership_type = ?";
    $params[] = ucfirst($typeFilter);
    $types .= 's';
}

if (!empty($search)) {
    $where[] = "(u.fullname LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

$whereSQL = implode(' AND ', $where);

// ============================================
// COUNT FOR PAGINATION
// ============================================
$totalRecords = 0;
$countSQL = "SELECT COUNT(*) AS total 
             FROM memberships m 
             INNER JOIN users u ON m.user_id = u.id 
             WHERE {$whereSQL}";

if (!empty($params)) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r && $row = $r->fetch_assoc()) $totalRecords = (int)$row['total'];
    $stmt->close();
} else {
    $r = $conn->query($countSQL);
    if ($r && $row = $r->fetch_assoc()) $totalRecords = (int)$row['total'];
}

$totalPages = max(1, ceil($totalRecords / $perPage));

// ============================================
// FETCH MEMBERSHIPS
// ============================================
$memberships = [];
$fetchSQL = "
    SELECT m.id, m.membership_type, m.amount, m.status, m.start_date, m.end_date, 
           m.created_at,
           u.id AS user_id, u.fullname, u.email, u.username, u.phone, u.profile_image, 
           u.account_status
    FROM memberships m
    INNER JOIN users u ON m.user_id = u.id
    WHERE {$whereSQL}
    ORDER BY 
        FIELD(m.status, 'Pending', 'Active', 'Expired', 'Rejected', 'Cancelled'),
        m.created_at DESC
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
        $memberships[] = $row;
    }
    $stmt->close();
}

// ============================================
// STATS
// ============================================
$stats = [
    'total' => 0,
    'pending' => 0,
    'active' => 0,
    'expired' => 0,
    'rejected' => 0,
    'revenue' => 0,
    'monthly_count' => 0,
];

$r = $conn->query("SELECT COUNT(*) AS total FROM memberships");
if ($r && $row = $r->fetch_assoc()) $stats['total'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM memberships WHERE status = 'Pending'");
if ($r && $row = $r->fetch_assoc()) $stats['pending'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM memberships WHERE status = 'Active'");
if ($r && $row = $r->fetch_assoc()) $stats['active'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM memberships WHERE status = 'Expired'");
if ($r && $row = $r->fetch_assoc()) $stats['expired'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM memberships WHERE status = 'Rejected'");
if ($r && $row = $r->fetch_assoc()) $stats['rejected'] = (int)$row['total'];

$r = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM memberships WHERE status = 'Active'");
if ($r && $row = $r->fetch_assoc()) $stats['revenue'] = (float)$row['total'];

$r = $conn->query("
    SELECT COUNT(*) AS total FROM memberships 
    WHERE MONTH(created_at) = MONTH(CURDATE()) 
    AND YEAR(created_at) = YEAR(CURDATE())
");
if ($r && $row = $r->fetch_assoc()) $stats['monthly_count'] = (int)$row['total'];

// ============================================
// HELPER FUNCTIONS
// ============================================
function timeAgo($datetime) {
    if (empty($datetime)) return '—';
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
}

function getStatusClass($status) {
    $s = strtolower($status);
    if ($s === 'active') return 'success';
    if ($s === 'pending') return 'warning';
    if (in_array($s, ['expired', 'rejected', 'cancelled'])) return 'danger';
    return 'info';
}

function getStatusIcon($status) {
    $s = strtolower($status);
    if ($s === 'active') return 'check-circle-fill';
    if ($s === 'pending') return 'hourglass-split';
    if ($s === 'expired') return 'clock-history';
    if ($s === 'rejected') return 'x-circle-fill';
    if ($s === 'cancelled') return 'slash-circle-fill';
    return 'circle-fill';
}

function daysRemaining($endDate) {
    if (empty($endDate)) return null;
    $diff = strtotime($endDate) - time();
    return (int)floor($diff / 86400);
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
    <title>Memberships - FitSense Admin</title>
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
        .stat-box-icon.orange { background: linear-gradient(135deg, rgba(245,158,11,0.12), rgba(251,191,36,0.18)); color: #b45309; }
        .stat-box-icon.red { background: linear-gradient(135deg, rgba(211,47,47,0.12), rgba(248,113,113,0.18)); color: var(--umak-red); }
        .stat-box-icon.purple { background: linear-gradient(135deg, rgba(139,92,246,0.12), rgba(167,139,250,0.18)); color: #7c3aed; }
        .stat-box-icon.teal { background: linear-gradient(135deg, rgba(13,132,156,0.12), rgba(6,182,212,0.18)); color: #0d849c; }

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

        .btn-success {
            background: linear-gradient(135deg, #2e7d32, #4ade80);
            color: white;
            box-shadow: 0 4px 12px rgba(46,125,50,0.25);
        }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(46,125,50,0.35); color: white; }

        .btn-danger {
            background: linear-gradient(135deg, #d32f2f, #ef4444);
            color: white;
        }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(211,47,47,0.3); color: white; }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: #333;
            box-shadow: 0 4px 12px rgba(245,158,11,0.25);
        }
        .btn-warning:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(245,158,11,0.35); color: #333; }

        .btn-sm { padding: 8px 14px; font-size: 12px; }

        /* ============================================
           TABLE
           ============================================ */
        .table-card {
            background: white; border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            overflow: hidden;
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

        .data-table tr.pending-row {
            background: linear-gradient(90deg, rgba(245,158,11,0.04), transparent 50%);
            border-left: 3px solid var(--umak-orange);
        }

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

        .user-info { min-width: 0; }
        .user-info .name {
            font-weight: 800; color: #111827; font-size: 14px;
            line-height: 1.2; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
            max-width: 200px;
        }
        .user-info .sub {
            font-size: 12px; color: var(--text-muted);
            margin-top: 2px;
        }

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

        .plan-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 8px;
            font-size: 11.5px; font-weight: 800;
            background: rgba(0,91,159,0.1);
            color: var(--umak-blue);
        }

        .plan-badge.one-day { background: rgba(139,92,246,0.1); color: #7c3aed; }
        .plan-badge.monthly { background: rgba(0,91,159,0.1); color: var(--umak-blue); }
        .plan-badge.yearly { background: rgba(255,206,0,0.15); color: #b8860b; }

        .price {
            font-weight: 800; color: #111827;
            font-size: 14px;
        }

        .days-left {
            font-size: 11.5px; font-weight: 700;
            margin-top: 2px;
        }

        .days-left.warning { color: #b45309; }
        .days-left.danger { color: #b91c1c; }
        .days-left.success { color: #166534; }

        /* Action buttons */
        .action-group {
            display: flex; gap: 6px; align-items: center;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 7px 12px; border-radius: 8px;
            font-size: 11.5px; font-weight: 800;
            cursor: pointer; border: none;
            display: inline-flex; align-items: center; gap: 5px;
            transition: all 0.2s ease;
            text-transform: uppercase; letter-spacing: 0.3px;
            font-family: inherit;
        }

        .action-btn.approve {
            background: linear-gradient(135deg, #2e7d32, #4ade80);
            color: white;
            box-shadow: 0 2px 8px rgba(46,125,50,0.25);
        }
        .action-btn.approve:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(46,125,50,0.35); }

        .action-btn.reject {
            background: linear-gradient(135deg, #d32f2f, #ef4444);
            color: white;
            box-shadow: 0 2px 8px rgba(211,47,47,0.25);
        }
        .action-btn.reject:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(211,47,47,0.35); }

        .action-btn.extend {
            background: white;
            color: var(--umak-blue);
            border: 1.5px solid var(--umak-blue);
        }
        .action-btn.extend:hover { background: var(--umak-blue); color: white; }

        .action-btn.view {
            background: #f3f4f6;
            color: #4b5563;
        }
        .action-btn.view:hover { background: #e5e7eb; }

        .action-btn.cancel {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .action-btn.cancel:hover { background: #fde68a; }

        /* Empty state */
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
           MODAL
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
            width: 100%; max-width: 520px;
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

        .modal-detail-list {
            display: flex; flex-direction: column; gap: 10px;
            margin-bottom: 20px;
        }

        .modal-detail-item {
            display: flex; justify-content: space-between;
            align-items: center; padding: 12px 16px;
            background: #f9fafb; border-radius: 10px;
            border: 1px solid var(--border);
        }

        .modal-detail-label {
            font-size: 12px; font-weight: 700;
            color: var(--text-muted); text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .modal-detail-value {
            font-size: 14px; font-weight: 800;
            color: #111827;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 12px; font-weight: 800;
            color: #374151; text-transform: uppercase;
            letter-spacing: 0.8px; margin-bottom: 8px;
        }

        .form-control {
            width: 100%; padding: 12px 14px;
            border: 2px solid var(--border); border-radius: 10px;
            font-size: 14px; font-weight: 500;
            color: #111827; background: #f9fafb;
            transition: all 0.25s ease;
            font-family: inherit; outline: none;
        }

        .form-control:focus {
            border-color: var(--umak-blue); background: white;
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        textarea.form-control {
            resize: vertical; min-height: 100px;
            line-height: 1.6;
        }

        .modal-footer {
            padding: 18px 26px 22px;
            display: flex; gap: 10px; justify-content: flex-end;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .modal-danger-text {
            background: #fee2e2; border-left: 4px solid var(--umak-red);
            padding: 14px 18px; border-radius: 10px;
            font-size: 13.5px; color: #991b1b;
            line-height: 1.6; margin-bottom: 16px;
        }

        .modal-warning-text {
            background: #fef3c7; border-left: 4px solid var(--umak-orange);
            padding: 14px 18px; border-radius: 10px;
            font-size: 13.5px; color: #78350f;
            line-height: 1.6; margin-bottom: 16px;
        }

        /* Days input */
        .days-input-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px; margin-bottom: 14px;
        }

        .days-preset {
            padding: 12px; border: 2px solid var(--border);
            border-radius: 10px; background: #f9fafb;
            font-size: 13px; font-weight: 700;
            cursor: pointer; text-align: center;
            color: #4b5563; transition: all 0.2s;
            font-family: inherit;
        }

        .days-preset:hover { border-color: var(--umak-blue); color: var(--umak-blue); }
        .days-preset.active {
            background: var(--umak-blue); color: white;
            border-color: var(--umak-blue);
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
            .action-btn { padding: 6px 10px; font-size: 10.5px; }
            .days-input-row { grid-template-columns: 1fr 1fr; }
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
        <a href="admin-memberships.php" class="nav-item active">
            <i class="bi bi-ticket-perforated-fill"></i><span>Memberships</span>
            <?php if ($stats['pending'] > 0): ?>
                <span class="badge-count"><?= $stats['pending'] ?></span>
            <?php endif; ?>
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
                <h2>Memberships</h2>
                <p class="subtitle">Approve, reject, and manage membership plans</p>
            </div>
        </div>

        <div class="header-right">
            <a href="admin-dashboard.php" class="header-btn" title="Dashboard">
                <i class="bi bi-speedometer2"></i>
            </a>
            <a href="admin-notifications.php" class="header-btn" title="Notifications">
                <i class="bi bi-bell-fill"></i>
                <?php if ($stats['pending'] > 0): ?>
                    <span class="notif-badge"><?= $stats['pending'] ?></span>
                <?php endif; ?>
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
                    <i class="bi bi-ticket-perforated-fill"></i>
                    Memberships
                </h1>
                <p class="page-subtitle">Review pending applications, activate plans, and manage active memberships.</p>
            </div>
            <div class="page-actions">
                <a href="admin-memberships-export.php?<?= htmlspecialchars(buildQuery(['export' => 'csv'])) ?>"
                   class="btn btn-outline" target="_blank">
                    <i class="bi bi-download"></i> Export CSV
                </a>
                <a href="admin-prices.php" class="btn btn-primary">
                    <i class="bi bi-gear-fill"></i> Manage Prices
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
            <a href="?status=all" class="stat-box <?= $statusFilter === 'all' ? 'active' : '' ?>">
                <div class="stat-box-icon blue">
                    <i class="bi bi-collection-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Total</div>
                    <div class="stat-box-value"><?= number_format($stats['total']) ?></div>
                    <div class="stat-box-sub"><?= $stats['monthly_count'] ?> this month</div>
                </div>
            </a>

            <a href="?status=pending" class="stat-box <?= $statusFilter === 'pending' ? 'active' : '' ?>">
                <div class="stat-box-icon orange">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Pending</div>
                    <div class="stat-box-value"><?= number_format($stats['pending']) ?></div>
                    <div class="stat-box-sub">Need review</div>
                </div>
            </a>

            <a href="?status=active" class="stat-box <?= $statusFilter === 'active' ? 'active' : '' ?>">
                <div class="stat-box-icon green">
                    <i class="bi bi-patch-check-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Active</div>
                    <div class="stat-box-value"><?= number_format($stats['active']) ?></div>
                    <div class="stat-box-sub">Currently valid</div>
                </div>
            </a>

            <a href="?status=expired" class="stat-box <?= $statusFilter === 'expired' ? 'active' : '' ?>">
                <div class="stat-box-icon red">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Expired</div>
                    <div class="stat-box-value"><?= number_format($stats['expired']) ?></div>
                    <div class="stat-box-sub">Past end date</div>
                </div>
            </a>

            <a href="?status=rejected" class="stat-box <?= $statusFilter === 'rejected' ? 'active' : '' ?>">
                <div class="stat-box-icon purple">
                    <i class="bi bi-x-circle-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Rejected</div>
                    <div class="stat-box-value"><?= number_format($stats['rejected']) ?></div>
                    <div class="stat-box-sub">Declined apps</div>
                </div>
            </a>

            <div class="stat-box">
                <div class="stat-box-icon teal">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Active Revenue</div>
                    <div class="stat-box-value">₱<?= number_format($stats['revenue'], 0) ?></div>
                    <div class="stat-box-sub">From active plans</div>
                </div>
            </div>
        </div>

        <!-- ============================================
             FILTER BAR
             ============================================ -->
        <form method="GET" class="filter-bar">
            <div class="filter-row">
                <div class="search-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search by member name, email, or username..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>

                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>

                <select name="type" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All Plans</option>
                    <option value="one day" <?= $typeFilter === 'one day' ? 'selected' : '' ?>>One Day</option>
                    <option value="monthly" <?= $typeFilter === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    <option value="yearly" <?= $typeFilter === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                </select>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel-fill"></i> Apply
                </button>

                <?php if (!empty($search) || $statusFilter !== 'all' || $typeFilter !== 'all'): ?>
                    <a href="admin-memberships.php" class="btn btn-outline">
                        <i class="bi bi-x-lg"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- ============================================
             MEMBERSHIPS TABLE
             ============================================ -->
        <div class="table-card">
            <div class="table-header">
                <h3>
                    <i class="bi bi-list-ul"></i>
                    Membership Records
                    <?php if ($totalRecords > 0): ?>
                        <span style="background:#e0f2fe; color:#0369a1; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:800;">
                            <?= number_format($totalRecords) ?>
                        </span>
                    <?php endif; ?>
                </h3>
                <div class="table-info">
                    <?php if ($totalRecords > 0): ?>
                        Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($memberships)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-ticket-perforated"></i>
                    </div>
                    <h3>No Membership Records</h3>
                    <p>
                        <?php if (!empty($search) || $statusFilter !== 'all' || $typeFilter !== 'all'): ?>
                            No memberships match your current filters. Try adjusting your search criteria.
                        <?php else: ?>
                            No memberships have been created yet. They'll appear here once members apply.
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($search) || $statusFilter !== 'all' || $typeFilter !== 'all'): ?>
                        <a href="admin-memberships.php" class="btn btn-primary">
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
                                <th>Plan</th>
                                <th>Amount</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Applied</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($memberships as $m):
                                $initials = strtoupper(substr($m['fullname'] ?? 'U', 0, 1));
                                if (strpos($m['fullname'] ?? '', ' ') !== false) {
                                    $parts = explode(' ', $m['fullname']);
                                    $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
                                }
                                $statusClass = getStatusClass($m['status']);
                                $statusIcon = getStatusIcon($m['status']);
                                $isPending = strtolower($m['status']) === 'pending';
                                $planSlug = strtolower(str_replace(' ', '-', $m['membership_type']));
                                $daysLeft = daysRemaining($m['end_date']);
                            ?>
                                <tr class="<?= $isPending ? 'pending-row' : '' ?>">
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar">
                                                <?php if (!empty($m['profile_image']) && file_exists(__DIR__ . '/../' . $m['profile_image'])): ?>
                                                    <img src="../<?= htmlspecialchars($m['profile_image']) ?>" alt="">
                                                <?php else: ?>
                                                    <?= htmlspecialchars($initials) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="user-info">
                                                <div class="name"><?= htmlspecialchars($m['fullname']) ?></div>
                                                <div class="sub">@<?= htmlspecialchars($m['username']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="plan-badge <?= $planSlug ?>">
                                            <i class="bi bi-<?= 
                                                $m['membership_type'] === 'One Day' ? 'sun-fill' : 
                                                ($m['membership_type'] === 'Monthly' ? 'calendar-month-fill' : 
                                                'calendar-heart-fill') ?>"></i>
                                            <?= htmlspecialchars($m['membership_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="price">₱<?= number_format($m['amount'], 2) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($m['status'] === 'Active' && $m['start_date'] && $m['end_date']): ?>
                                            <div style="font-size: 12.5px; color: #374151; font-weight: 600;">
                                                <?= date('M j', strtotime($m['start_date'])) ?> → <?= date('M j, Y', strtotime($m['end_date'])) ?>
                                            </div>
                                            <?php if ($daysLeft !== null):
                                                $dlClass = 'success';
                                                if ($daysLeft < 0) $dlClass = 'danger';
                                                elseif ($daysLeft <= 7) $dlClass = 'warning';
                                            ?>
                                                <div class="days-left <?= $dlClass ?>">
                                                    <?php if ($daysLeft < 0): ?>
                                                        Expired <?= abs($daysLeft) ?> days ago
                                                    <?php elseif ($daysLeft == 0): ?>
                                                        Expires today
                                                    <?php else: ?>
                                                        <?= $daysLeft ?> day<?= $daysLeft != 1 ? 's' : '' ?> left
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 12.5px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $statusClass ?>">
                                            <i class="bi bi-<?= $statusIcon ?>"></i>
                                            <?= htmlspecialchars($m['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-size: 12.5px; font-weight: 600; color: #374151;">
                                            <?= date('M j, Y', strtotime($m['created_at'])) ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                            <?= timeAgo($m['created_at']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-group" style="justify-content: flex-end;">
                                            <?php if ($isPending): ?>
                                                <button type="button"
                                                        class="action-btn approve"
                                                        onclick="approveMembership(<?= (int)$m['id'] ?>, '<?= htmlspecialchars(addslashes($m['fullname'])) ?>', '<?= htmlspecialchars($m['membership_type']) ?>', '<?= number_format($m['amount'], 2) ?>')">
                                                    <i class="bi bi-check-circle-fill"></i> Approve
                                                </button>
                                                <button type="button"
                                                        class="action-btn reject"
                                                        onclick="openRejectModal(<?= (int)$m['id'] ?>, '<?= htmlspecialchars(addslashes($m['fullname'])) ?>')">
                                                    <i class="bi bi-x-circle-fill"></i> Reject
                                                </button>
                                            <?php elseif (strtolower($m['status']) === 'active'): ?>
                                                <button type="button"
                                                        class="action-btn extend"
                                                        onclick="openExtendModal(<?= (int)$m['id'] ?>, '<?= htmlspecialchars(addslashes($m['fullname'])) ?>', '<?= htmlspecialchars($m['end_date'] ?? '') ?>')">
                                                    <i class="bi bi-calendar-plus"></i> Extend
                                                </button>
                                                <button type="button"
                                                        class="action-btn cancel"
                                                        onclick="cancelMembership(<?= (int)$m['id'] ?>, '<?= htmlspecialchars(addslashes($m['fullname'])) ?>')">
                                                    <i class="bi bi-slash-circle"></i> Cancel
                                                </button>
                                            <?php elseif (in_array(strtolower($m['status']), ['expired', 'cancelled', 'rejected'])): ?>
                                                <button type="button"
                                                        class="action-btn approve"
                                                        onclick="reactivateMembership(<?= (int)$m['id'] ?>, '<?= htmlspecialchars(addslashes($m['fullname'])) ?>')">
                                                    <i class="bi bi-arrow-clockwise"></i> Reactivate
                                                </button>
                                                <button type="button"
                                                        class="action-btn view"
                                                        onclick="deleteMembership(<?= (int)$m['id'] ?>, '<?= htmlspecialchars(addslashes($m['fullname'])) ?>')">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            <?php endif; ?>

                                            <a href="admin-member-view.php?id=<?= (int)$m['user_id'] ?>"
                                               class="action-btn view" title="View Member">
                                                <i class="bi bi-eye-fill"></i>
                                            </a>
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
                        <a href="?<?= htmlspecialchars(buildQuery(['page' => max(1, $page - 1)])) ?>"
                           class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>

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
     APPROVE MODAL
     ============================================ -->
<div class="modal-overlay" id="approveModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-check-circle-fill" style="color: var(--umak-green);"></i> Approve Membership</h3>
            <button class="modal-close" onclick="closeModal('approveModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <div class="modal-detail-list">
                <div class="modal-detail-item">
                    <span class="modal-detail-label">Member</span>
                    <span class="modal-detail-value" id="approveMemberName">—</span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">Plan</span>
                    <span class="modal-detail-value" id="approvePlanType">—</span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">Amount</span>
                    <span class="modal-detail-value" id="approveAmount">—</span>
                </div>
            </div>

            <div class="modal-warning-text">
                <strong><i class="bi bi-info-circle-fill"></i> Note:</strong>
                Approving this membership will activate the plan immediately, mark related payments as paid, and notify the member.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('approveModal')">Cancel</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="approve">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="membership_id" id="approveMembershipId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle-fill"></i> Approve & Activate
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ============================================
     REJECT MODAL
     ============================================ -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-x-circle-fill" style="color: var(--umak-red);"></i> Reject Membership</h3>
            <button class="modal-close" onclick="closeModal('rejectModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="modal-detail-list">
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">Member</span>
                        <span class="modal-detail-value" id="rejectMemberName">—</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Reason for Rejection (Optional)</label>
                    <textarea name="admin_note" class="form-control"
                              placeholder="e.g., Payment not received, invalid documents, etc."></textarea>
                    <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 6px; line-height: 1.5;">
                        This note will be included in the notification sent to the member.
                    </div>
                </div>

                <div class="modal-danger-text">
                    <strong><i class="bi bi-exclamation-triangle-fill"></i> Warning:</strong>
                    The member will be notified and their pending payment will be cancelled.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
                <input type="hidden" name="action" value="reject">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="membership_id" id="rejectMembershipId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-x-circle-fill"></i> Reject Application
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================
     EXTEND MODAL
     ============================================ -->
<div class="modal-overlay" id="extendModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-calendar-plus" style="color: var(--umak-blue);"></i> Extend Membership</h3>
            <button class="modal-close" onclick="closeModal('extendModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="modal-detail-list">
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">Member</span>
                        <span class="modal-detail-value" id="extendMemberName">—</span>
                    </div>
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">Current Expiry</span>
                        <span class="modal-detail-value" id="extendCurrentDate">—</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Extend by how many days?</label>
                    <div class="days-input-row">
                        <button type="button" class="days-preset" onclick="setDays(this, 7)">7 days</button>
                        <button type="button" class="days-preset active" onclick="setDays(this, 30)">30 days</button>
                        <button type="button" class="days-preset" onclick="setDays(this, 90)">90 days</button>
                    </div>
                    <input type="number" name="extend_days" id="extendDaysInput" class="form-control"
                           value="30" min="1" max="3650" required>
                    <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 6px;">
                        Extends from current expiry date. Enter custom value or use presets above.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('extendModal')">Cancel</button>
                <input type="hidden" name="action" value="extend">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="membership_id" id="extendMembershipId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-calendar-check-fill"></i> Extend Membership
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================
     CANCEL MODAL
     ============================================ -->
<div class="modal-overlay" id="cancelModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-slash-circle" style="color: #b45309;"></i> Cancel Membership</h3>
            <button class="modal-close" onclick="closeModal('cancelModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <p style="margin: 0 0 16px; font-size: 14.5px; color: #4b5563; line-height: 1.6;">
                Are you sure you want to cancel the membership of <strong id="cancelMemberName">—</strong>?
            </p>

            <div class="modal-warning-text">
                <strong><i class="bi bi-info-circle-fill"></i> Note:</strong>
                The member will lose access immediately. The record will remain in history and can be reactivated later.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('cancelModal')">Cancel</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="cancel">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="membership_id" id="cancelMembershipId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-slash-circle"></i> Cancel Membership
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ============================================
     REACTIVATE MODAL
     ============================================ -->
<div class="modal-overlay" id="reactivateModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-arrow-clockwise" style="color: var(--umak-green);"></i> Reactivate Membership</h3>
            <button class="modal-close" onclick="closeModal('reactivateModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <p style="margin: 0 0 16px; font-size: 14.5px; color: #4b5563; line-height: 1.6;">
                    Reactivate the membership for <strong id="reactivateMemberName">—</strong>?
                </p>

                <div class="form-group">
                    <label class="form-label">Reactivate for how many days?</label>
                    <div class="days-input-row">
                        <button type="button" class="days-preset" onclick="setDays(this, 1)">1 day</button>
                        <button type="button" class="days-preset" onclick="setDays(this, 30)">30 days</button>
                        <button type="button" class="days-preset active" onclick="setDays(this, 30)">30 days</button>
                    </div>
                    <input type="number" name="extend_days" id="reactivateDaysInput" class="form-control"
                           value="30" min="1" max="3650" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('reactivateModal')">Cancel</button>
                <input type="hidden" name="action" value="reactivate">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="membership_id" id="reactivateMembershipId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-arrow-clockwise"></i> Reactivate
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================
     DELETE MODAL
     ============================================ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-trash3-fill" style="color: var(--umak-red);"></i> Delete Membership Record</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <p style="margin: 0 0 16px; font-size: 14.5px; color: #4b5563; line-height: 1.6;">
                Permanently delete the membership record for <strong id="deleteMemberName">—</strong>?
            </p>

            <div class="modal-danger-text">
                <strong><i class="bi bi-exclamation-triangle-fill"></i> Warning:</strong>
                This action cannot be undone. The membership record will be removed from the database permanently.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="delete">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="membership_id" id="deleteMembershipId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash3-fill"></i> Delete Permanently
                </button>
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
    // MODAL HELPERS
    // ============================================
    function openModal(id) {
        document.getElementById(id).classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(m => {
                m.classList.remove('open');
            });
            document.body.style.overflow = '';
        }
    });

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('open');
                document.body.style.overflow = '';
            }
        });
    });

    // ============================================
    // APPROVE
    // ============================================
    function approveMembership(id, name, plan, amount) {
        document.getElementById('approveMemberName').textContent = name;
        document.getElementById('approvePlanType').textContent = plan;
        document.getElementById('approveAmount').textContent = '₱' + amount;
        document.getElementById('approveMembershipId').value = id;
        openModal('approveModal');
    }

    // ============================================
    // REJECT
    // ============================================
    function openRejectModal(id, name) {
        document.getElementById('rejectMemberName').textContent = name;
        document.getElementById('rejectMembershipId').value = id;
        openModal('rejectModal');
    }

    // ============================================
    // EXTEND
    // ============================================
    function openExtendModal(id, name, currentDate) {
        document.getElementById('extendMemberName').textContent = name;
        document.getElementById('extendCurrentDate').textContent = currentDate ? 
            new Date(currentDate).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A';
        document.getElementById('extendMembershipId').value = id;
        document.getElementById('extendDaysInput').value = 30;
        
        // Reset presets
        document.querySelectorAll('#extendModal .days-preset').forEach((b, i) => {
            b.classList.toggle('active', i === 1);
        });
        
        openModal('extendModal');
    }

    function setDays(btn, days) {
        const modal = btn.closest('.modal');
        modal.querySelectorAll('.days-preset').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        modal.querySelector('input[type="number"]').value = days;
    }

    // ============================================
    // CANCEL
    // ============================================
    function cancelMembership(id, name) {
        document.getElementById('cancelMemberName').textContent = name;
        document.getElementById('cancelMembershipId').value = id;
        openModal('cancelModal');
    }

    // ============================================
    // REACTIVATE
    // ============================================
    function reactivateMembership(id, name) {
        document.getElementById('reactivateMemberName').textContent = name;
        document.getElementById('reactivateMembershipId').value = id;
        document.getElementById('reactivateDaysInput').value = 30;
        openModal('reactivateModal');
    }

    // ============================================
    // DELETE
    // ============================================
    function deleteMembership(id, name) {
        document.getElementById('deleteMemberName').textContent = name;
        document.getElementById('deleteMembershipId').value = id;
        openModal('deleteModal');
    }

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