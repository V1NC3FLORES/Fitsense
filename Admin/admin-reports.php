<?php
// Admin/admin-reports.php
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

function tableExists($conn, $tableName) {
    $tableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    return $result && $result->num_rows > 0;
}

function countRows($conn, $sql, $types = '', $params = []) {
    $result = safeQuery($conn, $sql, $types, $params);
    if ($result && $row = $result->fetch_assoc()) {
        return (int)($row['total'] ?? 0);
    }
    return 0;
}

function sumColumn($conn, $sql, $types = '', $params = []) {
    $result = safeQuery($conn, $sql, $types, $params);
    if ($result && $row = $result->fetch_assoc()) {
        return (float)($row['total'] ?? 0);
    }
    return 0;
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
// DATE RANGE FILTER
// ============================================
$range = $_GET['range'] ?? '30days';
$customStart = $_GET['start'] ?? '';
$customEnd = $_GET['end'] ?? '';

$today = date('Y-m-d');
switch ($range) {
    case 'today':
        $startDate = $today;
        $endDate = $today;
        $rangeLabel = 'Today';
        break;
    case '7days':
        $startDate = date('Y-m-d', strtotime('-6 days'));
        $endDate = $today;
        $rangeLabel = 'Last 7 Days';
        break;
    case '30days':
        $startDate = date('Y-m-d', strtotime('-29 days'));
        $endDate = $today;
        $rangeLabel = 'Last 30 Days';
        break;
    case '90days':
        $startDate = date('Y-m-d', strtotime('-89 days'));
        $endDate = $today;
        $rangeLabel = 'Last 90 Days';
        break;
    case 'year':
        $startDate = date('Y-01-01');
        $endDate = $today;
        $rangeLabel = 'This Year';
        break;
    case 'all':
        $startDate = '2000-01-01';
        $endDate = $today;
        $rangeLabel = 'All Time';
        break;
    case 'custom':
        if (!empty($customStart) && !empty($customEnd)) {
            $startDate = $customStart;
            $endDate = $customEnd;
            $rangeLabel = date('M j, Y', strtotime($startDate)) . ' — ' . date('M j, Y', strtotime($endDate));
        } else {
            $startDate = date('Y-m-d', strtotime('-29 days'));
            $endDate = $today;
            $rangeLabel = 'Last 30 Days';
        }
        break;
    default:
        $startDate = date('Y-m-d', strtotime('-29 days'));
        $endDate = $today;
        $rangeLabel = 'Last 30 Days';
}

// ============================================
// CORE METRICS (filtered by date range)
// ============================================

// Revenue
$totalRevenue = tableExists($conn, 'transactions')
    ? sumColumn($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total FROM transactions 
        WHERE status = 'Paid' AND DATE(created_at) BETWEEN ? AND ?
    ", "ss", [$startDate, $endDate]) : 0;

$totalPending = tableExists($conn, 'transactions')
    ? sumColumn($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total FROM transactions 
        WHERE status = 'Pending' AND DATE(created_at) BETWEEN ? AND ?
    ", "ss", [$startDate, $endDate]) : 0;

$paidCount = tableExists($conn, 'transactions')
    ? countRows($conn, "
        SELECT COUNT(*) AS total FROM transactions 
        WHERE status = 'Paid' AND DATE(created_at) BETWEEN ? AND ?
    ", "ss", [$startDate, $endDate]) : 0;

$pendingCount = tableExists($conn, 'transactions')
    ? countRows($conn, "
        SELECT COUNT(*) AS total FROM transactions 
        WHERE status = 'Pending' AND DATE(created_at) BETWEEN ? AND ?
    ", "ss", [$startDate, $endDate]) : 0;

$cancelledCount = tableExists($conn, 'transactions')
    ? countRows($conn, "
        SELECT COUNT(*) AS total FROM transactions 
        WHERE status = 'Cancelled' AND DATE(created_at) BETWEEN ? AND ?
    ", "ss", [$startDate, $endDate]) : 0;

// Average transaction value
$avgTransaction = $paidCount > 0 ? ($totalRevenue / $paidCount) : 0;

// Members
$newMembers = countRows($conn, "
    SELECT COUNT(*) AS total FROM users 
    WHERE role = 'user' AND DATE(created_at) BETWEEN ? AND ?
", "ss", [$startDate, $endDate]);

$totalMembers = countRows($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'user'");
$activeMembers = countRows($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'user' AND account_status = 'Active'");

// Memberships
$newMemberships = tableExists($conn, 'memberships')
    ? countRows($conn, "
        SELECT COUNT(*) AS total FROM memberships 
        WHERE DATE(created_at) BETWEEN ? AND ?
    ", "ss", [$startDate, $endDate]) : 0;

$activeMemberships = tableExists($conn, 'memberships')
    ? countRows($conn, "SELECT COUNT(*) AS total FROM memberships WHERE status = 'Active'") : 0;

$pendingMemberships = tableExists($conn, 'memberships')
    ? countRows($conn, "SELECT COUNT(*) AS total FROM memberships WHERE status = 'Pending'") : 0;

// Sessions
$totalSessions = tableExists($conn, 'sessions')
    ? countRows($conn, "
        SELECT COUNT(*) AS total FROM sessions 
        WHERE DATE(created_at) BETWEEN ? AND ?
    ", "ss", [$startDate, $endDate]) : 0;

$completedSessions = tableExists($conn, 'sessions')
    ? countRows($conn, "
        SELECT COUNT(*) AS total FROM sessions 
        WHERE status = 'Completed' AND DATE(created_at) BETWEEN ? AND ?
    ", "ss", [$startDate, $endDate]) : 0;

// ============================================
// REVENUE CHART DATA (grouped by day/week/month)
// ============================================
$chartLabels = [];
$chartRevenue = [];
$chartMembers = [];
$chartSessions = [];

$start = strtotime($startDate);
$end = strtotime($endDate);
$daysDiff = (int)(($end - $start) / 86400) + 1;

// Determine grouping strategy
if ($daysDiff <= 31) {
    // Group by day
    for ($i = 0; $i < $daysDiff; $i++) {
        $date = date('Y-m-d', strtotime("+{$i} days", $start));
        $chartLabels[] = date('M j', strtotime($date));

        if (tableExists($conn, 'transactions')) {
            $chartRevenue[] = sumColumn($conn, "
                SELECT COALESCE(SUM(amount), 0) AS total FROM transactions 
                WHERE status = 'Paid' AND DATE(created_at) = ?
            ", "s", [$date]);
        } else {
            $chartRevenue[] = 0;
        }

        $chartMembers[] = countRows($conn, "
            SELECT COUNT(*) AS total FROM users 
            WHERE role = 'user' AND DATE(created_at) = ?
        ", "s", [$date]);

        if (tableExists($conn, 'sessions')) {
            $chartSessions[] = countRows($conn, "
                SELECT COUNT(*) AS total FROM sessions 
                WHERE DATE(created_at) = ?
            ", "s", [$date]);
        } else {
            $chartSessions[] = 0;
        }
    }
} elseif ($daysDiff <= 120) {
    // Group by week
    $weeks = ceil($daysDiff / 7);
    for ($i = 0; $i < $weeks; $i++) {
        $wStart = date('Y-m-d', strtotime("+{$i} weeks", $start));
        $wEnd = date('Y-m-d', strtotime("+6 days", strtotime($wStart)));
        if (strtotime($wEnd) > $end) $wEnd = $end;

        $chartLabels[] = 'Week ' . ($i + 1);

        if (tableExists($conn, 'transactions')) {
            $chartRevenue[] = sumColumn($conn, "
                SELECT COALESCE(SUM(amount), 0) AS total FROM transactions 
                WHERE status = 'Paid' AND DATE(created_at) BETWEEN ? AND ?
            ", "ss", [$wStart, $wEnd]);
        } else {
            $chartRevenue[] = 0;
        }

        $chartMembers[] = countRows($conn, "
            SELECT COUNT(*) AS total FROM users 
            WHERE role = 'user' AND DATE(created_at) BETWEEN ? AND ?
        ", "ss", [$wStart, $wEnd]);

        if (tableExists($conn, 'sessions')) {
            $chartSessions[] = countRows($conn, "
                SELECT COUNT(*) AS total FROM sessions 
                WHERE DATE(created_at) BETWEEN ? AND ?
            ", "ss", [$wStart, $wEnd]);
        } else {
            $chartSessions[] = 0;
        }
    }
} else {
    // Group by month
    $current = strtotime(date('Y-m-01', $start));
    while ($current <= $end) {
        $mStart = date('Y-m-01', $current);
        $mEnd = date('Y-m-t', $current);
        $chartLabels[] = date('M Y', $current);

        if (tableExists($conn, 'transactions')) {
            $chartRevenue[] = sumColumn($conn, "
                SELECT COALESCE(SUM(amount), 0) AS total FROM transactions 
                WHERE status = 'Paid' AND DATE(created_at) BETWEEN ? AND ?
            ", "ss", [$mStart, $mEnd]);
        } else {
            $chartRevenue[] = 0;
        }

        $chartMembers[] = countRows($conn, "
            SELECT COUNT(*) AS total FROM users 
            WHERE role = 'user' AND DATE(created_at) BETWEEN ? AND ?
        ", "ss", [$mStart, $mEnd]);

        if (tableExists($conn, 'sessions')) {
            $chartSessions[] = countRows($conn, "
                SELECT COUNT(*) AS total FROM sessions 
                WHERE DATE(created_at) BETWEEN ? AND ?
            ", "ss", [$mStart, $mEnd]);
        } else {
            $chartSessions[] = 0;
        }

        $current = strtotime('+1 month', $current);
    }
}

// Limit to last 14 data points for readability
if (count($chartLabels) > 14) {
    $chartLabels = array_slice($chartLabels, -14);
    $chartRevenue = array_slice($chartRevenue, -14);
    $chartMembers = array_slice($chartMembers, -14);
    $chartSessions = array_slice($chartSessions, -14);
}

// ============================================
// MEMBERSHIP TYPE BREAKDOWN
// ============================================
$membershipBreakdown = [];
if (tableExists($conn, 'memberships')) {
    $r = safeQuery($conn, "
        SELECT membership_type, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS revenue
        FROM memberships 
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY membership_type
        ORDER BY cnt DESC
    ", "ss", [$startDate, $endDate]);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $membershipBreakdown[] = $row;
        }
    }
}

// ============================================
// TRANSACTION TYPE BREAKDOWN
// ============================================
$txnTypeBreakdown = [];
if (tableExists($conn, 'transactions')) {
    $r = safeQuery($conn, "
        SELECT transaction_type, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS revenue
        FROM transactions 
        WHERE status = 'Paid' AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY transaction_type
        ORDER BY revenue DESC
    ", "ss", [$startDate, $endDate]);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $txnTypeBreakdown[] = $row;
        }
    }
}

// ============================================
// TOP MEMBERS BY SPENDING
// ============================================
$topMembers = [];
if (tableExists($conn, 'transactions')) {
    $r = safeQuery($conn, "
        SELECT u.id, u.fullname, u.username, u.email, u.profile_image,
               COUNT(t.id) AS txn_count,
               COALESCE(SUM(t.amount), 0) AS total_spent
        FROM users u
        INNER JOIN transactions t ON t.user_id = u.id
        WHERE u.role = 'user' 
          AND t.status = 'Paid'
          AND DATE(t.created_at) BETWEEN ? AND ?
        GROUP BY u.id
        ORDER BY total_spent DESC
        LIMIT 5
    ", "ss", [$startDate, $endDate]);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $topMembers[] = $row;
        }
    }
}

// ============================================
// SESSION TYPE BREAKDOWN
// ============================================
$sessionBreakdown = [];
if (tableExists($conn, 'sessions')) {
    $r = safeQuery($conn, "
        SELECT session_type, 
               COUNT(*) AS total_count,
               SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
               SUM(CASE WHEN status = 'Booked' THEN 1 ELSE 0 END) AS booked_count,
               SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count
        FROM sessions 
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY session_type
        ORDER BY total_count DESC
    ", "ss", [$startDate, $endDate]);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $sessionBreakdown[] = $row;
        }
    }
}

// ============================================
// RECENT ACTIVITY FEED
// ============================================
$recentActivity = [];

if (tableExists($conn, 'transactions')) {
    $r = safeQuery($conn, "
        SELECT 'payment' AS type, t.created_at, t.amount, t.status, 
               u.fullname, u.username, t.description AS detail
        FROM transactions t
        INNER JOIN users u ON t.user_id = u.id
        WHERE DATE(t.created_at) BETWEEN ? AND ?
        ORDER BY t.created_at DESC
        LIMIT 5
    ", "ss", [$startDate, $endDate]);
    if ($r) {
        while ($row = $r->fetch_assoc()) $recentActivity[] = $row;
    }
}

if (tableExists($conn, 'memberships')) {
    $r = safeQuery($conn, "
        SELECT 'membership' AS type, m.created_at, m.amount, m.status, 
               u.fullname, u.username, CONCAT(m.membership_type, ' membership') AS detail
        FROM memberships m
        INNER JOIN users u ON m.user_id = u.id
        WHERE DATE(m.created_at) BETWEEN ? AND ?
        ORDER BY m.created_at DESC
        LIMIT 5
    ", "ss", [$startDate, $endDate]);
    if ($r) {
        while ($row = $r->fetch_assoc()) $recentActivity[] = $row;
    }
}

if (tableExists($conn, 'sessions')) {
    $r = safeQuery($conn, "
        SELECT 'session' AS type, s.created_at, 0 AS amount, s.status, 
               u.fullname, u.username, CONCAT(s.session_type, ' session') AS detail
        FROM sessions s
        INNER JOIN users u ON s.user_id = u.id
        WHERE DATE(s.created_at) BETWEEN ? AND ?
        ORDER BY s.created_at DESC
        LIMIT 5
    ", "ss", [$startDate, $endDate]);
    if ($r) {
        while ($row = $r->fetch_assoc()) $recentActivity[] = $row;
    }
}

// Sort combined activity by date
usort($recentActivity, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
$recentActivity = array_slice($recentActivity, 0, 8);

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

function formatMoney($amount) {
    return '₱' . number_format($amount, 0);
}

function formatMoneyFull($amount) {
    return '₱' . number_format($amount, 2);
}

function calcGrowth($current, $previous) {
    if ($previous == 0) return $current > 0 ? 100 : 0;
    return round((($current - $previous) / $previous) * 100, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - FitSense Admin</title>
    <link rel="icon" type="image/png" href="../uploads/umak-gym-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* ============================================
           DATE RANGE PICKER
           ============================================ */
        .range-bar {
            background: white; border-radius: 16px; padding: 18px 22px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
            display: flex; justify-content: space-between;
            align-items: center; gap: 15px; flex-wrap: wrap;
        }

        .range-tabs {
            display: flex; gap: 6px; flex-wrap: wrap;
        }

        .range-tab {
            padding: 10px 18px; border-radius: 10px;
            background: #f3f4f6; color: #4b5563;
            text-decoration: none; font-size: 13px;
            font-weight: 700; transition: all 0.2s ease;
            border: 2px solid transparent;
        }

        .range-tab:hover { background: #e5e7eb; color: var(--umak-blue); }
        .range-tab.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 12px rgba(0,91,159,0.3);
        }

        .range-info {
            font-size: 13px; color: var(--text-muted);
            font-weight: 600; display: flex; align-items: center; gap: 8px;
        }

        .range-info strong { color: var(--umak-blue); }

        /* Custom Date Range */
        .custom-range-form {
            display: flex; gap: 8px; align-items: center;
            margin-left: auto;
        }

        .custom-range-form input[type="date"] {
            padding: 9px 12px; border: 2px solid var(--border);
            border-radius: 8px; font-size: 12.5px;
            font-family: inherit; font-weight: 600;
            color: #374151; outline: none; transition: 0.2s;
        }

        .custom-range-form input[type="date"]:focus {
            border-color: var(--umak-blue);
        }

        .custom-range-form button {
            padding: 9px 16px; background: var(--umak-blue);
            color: white; border: none; border-radius: 8px;
            font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: 0.2s;
            font-family: inherit;
        }

        .custom-range-form button:hover { background: var(--umak-blue-dark); }

        /* ============================================
           KPI CARDS
           ============================================ */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px; margin-bottom: 25px;
        }

        .kpi-card {
            background: white; border-radius: 16px; padding: 22px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(0,0,0,0.08);
        }

        .kpi-icon {
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; color: white; margin-bottom: 16px;
        }

        .kpi-icon.blue { background: linear-gradient(135deg, #005b9f, #0084d1); box-shadow: 0 8px 20px rgba(0,91,159,0.3); }
        .kpi-icon.green { background: linear-gradient(135deg, #2e7d32, #4ade80); box-shadow: 0 8px 20px rgba(46,125,50,0.3); }
        .kpi-icon.yellow { background: linear-gradient(135deg, #ffce00, #ffb800); color: #333; box-shadow: 0 8px 20px rgba(255,206,0,0.4); }
        .kpi-icon.purple { background: linear-gradient(135deg, #8b5cf6, #a78bfa); box-shadow: 0 8px 20px rgba(139,92,246,0.3); }
        .kpi-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); box-shadow: 0 8px 20px rgba(245,158,11,0.3); }
        .kpi-icon.red { background: linear-gradient(135deg, #d32f2f, #f87171); box-shadow: 0 8px 20px rgba(211,47,47,0.3); }
        .kpi-icon.teal { background: linear-gradient(135deg, #0d849c, #06b6d4); box-shadow: 0 8px 20px rgba(13,132,156,0.3); }
        .kpi-icon.pink { background: linear-gradient(135deg, #ec4899, #f472b6); box-shadow: 0 8px 20px rgba(236,72,153,0.3); }

        .kpi-label {
            font-size: 11.5px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 1.2px;
            color: var(--text-muted); margin-bottom: 8px;
        }

        .kpi-value {
            font-size: 28px; font-weight: 800; color: #111827;
            line-height: 1.1; letter-spacing: -1px;
            display: flex; align-items: baseline; gap: 4px;
        }

        .kpi-value .unit {
            font-size: 14px; font-weight: 700;
            color: var(--text-muted); letter-spacing: 0;
        }

        .kpi-change {
            font-size: 12px; font-weight: 700;
            margin-top: 8px;
            display: flex; align-items: center; gap: 4px;
        }

        .kpi-change.positive { color: var(--umak-green); }
        .kpi-change.negative { color: var(--umak-red); }
        .kpi-change.neutral { color: var(--text-muted); }

        /* ============================================
           PANELS
           ============================================ */
        .panel {
            background: white; border-radius: 16px;
            padding: 24px 26px; border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 22px;
        }

        .panel-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
            gap: 12px; flex-wrap: wrap;
        }

        .panel-title {
            margin: 0; font-size: 17px; font-weight: 800;
            color: var(--umak-blue);
            display: flex; align-items: center; gap: 10px;
        }

        .panel-title i { font-size: 20px; }

        .panel-subtitle {
            font-size: 12.5px; color: var(--text-muted);
            font-weight: 600; margin-top: 4px;
        }

        /* ============================================
           CHARTS
           ============================================ */
        .chart-container {
            position: relative; height: 320px;
        }

        .chart-container.small { height: 260px; }

        .charts-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px; margin-bottom: 22px;
        }

        /* ============================================
           BREAKDOWN LISTS
           ============================================ */
        .breakdown-list {
            display: flex; flex-direction: column; gap: 12px;
        }

        .breakdown-item {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 16px; background: #f9fafb;
            border-radius: 12px; transition: all 0.2s;
            border: 1px solid var(--border);
        }

        .breakdown-item:hover {
            background: white;
            transform: translateX(3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .breakdown-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center;
            justify-content: center; font-size: 20px;
            flex-shrink: 0;
        }

        .breakdown-icon.blue { background: rgba(0,91,159,0.12); color: var(--umak-blue); }
        .breakdown-icon.green { background: rgba(46,125,50,0.12); color: var(--umak-green); }
        .breakdown-icon.purple { background: rgba(139,92,246,0.12); color: #7c3aed; }
        .breakdown-icon.yellow { background: rgba(255,206,0,0.2); color: #b8860b; }
        .breakdown-icon.orange { background: rgba(245,158,11,0.12); color: #b45309; }

        .breakdown-info { flex: 1; min-width: 0; }

        .breakdown-name {
            font-size: 14px; font-weight: 800;
            color: #111827; margin-bottom: 4px;
        }

        .breakdown-sub {
            font-size: 12px; color: var(--text-muted);
            font-weight: 600;
        }

        .breakdown-value {
            text-align: right; flex-shrink: 0;
        }

        .breakdown-amount {
            font-size: 16px; font-weight: 800;
            color: var(--umak-blue);
        }

        .breakdown-percent {
            font-size: 11.5px; color: var(--text-muted);
            font-weight: 700; margin-top: 2px;
        }

        /* Progress bar for breakdowns */
        .breakdown-bar {
            height: 6px; background: #f3f4f6;
            border-radius: 3px; overflow: hidden;
            margin-top: 8px;
        }

        .breakdown-bar-fill {
            height: 100%; border-radius: 3px;
            transition: width 0.8s ease;
        }

        /* ============================================
           TOP MEMBERS TABLE
           ============================================ */
        .leaderboard-list {
            display: flex; flex-direction: column; gap: 10px;
        }

        .leaderboard-item {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 16px; border-radius: 12px;
            border: 1px solid var(--border);
            transition: 0.2s;
            background: white;
        }

        .leaderboard-item:hover {
            background: #f9fafb;
            transform: translateX(3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .leaderboard-item.top-1 { border-left: 4px solid #ffce00; }
        .leaderboard-item.top-2 { border-left: 4px solid #9ca3af; }
        .leaderboard-item.top-3 { border-left: 4px solid #cd7f32; }

        .rank-badge {
            width: 32px; height: 32px; border-radius: 50%;
            background: #f3f4f6; color: #6b7280;
            display: flex; align-items: center; justify-content: center;
            font-weight: 900; font-size: 13px;
            flex-shrink: 0;
        }

        .rank-badge.gold { background: linear-gradient(135deg, #ffce00, #ffb800); color: #333; }
        .rank-badge.silver { background: linear-gradient(135deg, #e5e7eb, #9ca3af); color: #333; }
        .rank-badge.bronze { background: linear-gradient(135deg, #d97706, #b45309); color: white; }

        .member-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 15px; flex-shrink: 0;
            overflow: hidden;
        }

        .member-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .member-info { flex: 1; min-width: 0; }

        .member-name {
            font-size: 14px; font-weight: 800;
            color: #111827; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }

        .member-meta {
            font-size: 12px; color: var(--text-muted);
            font-weight: 600; margin-top: 2px;
        }

        .member-amount {
            text-align: right; flex-shrink: 0;
        }

        .member-amount-value {
            font-size: 16px; font-weight: 800;
            color: var(--umak-green);
        }

        .member-amount-count {
            font-size: 11.5px;
            color: var(--text-muted);
            font-weight: 700; margin-top: 2px;
        }

        /* ============================================
           ACTIVITY FEED
           ============================================ */
        .activity-list {
            display: flex; flex-direction: column; gap: 2px;
        }

        .activity-item {
            display: flex; gap: 14px;
            padding: 12px 10px;
            border-radius: 10px;
            transition: 0.2s;
            align-items: flex-start;
        }

        .activity-item:hover { background: #f9fafb; }

        .activity-icon {
            width: 38px; height: 38px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }

        .activity-icon.payment { background: rgba(46,125,50,0.12); color: var(--umak-green); }
        .activity-icon.membership { background: rgba(0,91,159,0.12); color: var(--umak-blue); }
        .activity-icon.session { background: rgba(139,92,246,0.12); color: #7c3aed; }

        .activity-content { flex: 1; min-width: 0; }

        .activity-text {
            font-size: 13.5px;
            color: #374151;
            font-weight: 600;
            line-height: 1.5;
        }

        .activity-text strong { color: #111827; font-weight: 800; }

        .activity-time {
            font-size: 11.5px;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 3px;
        }

        /* ============================================
           SUMMARY STATS ROW
           ============================================ */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px;
            padding: 18px 0;
            border-top: 1px solid var(--border);
            margin-top: 18px;
        }

        .summary-stat {
            text-align: center;
        }

        .summary-stat-value {
            font-size: 22px; font-weight: 800;
            color: var(--umak-blue); line-height: 1;
        }

        .summary-stat-label {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-top: 6px;
        }

        /* ============================================
           EMPTY STATE
           ============================================ */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 42px;
            opacity: 0.3;
            display: block;
            margin-bottom: 12px;
        }

        .empty-state p {
            margin: 0;
            font-size: 13.5px;
            line-height: 1.6;
        }

        /* ============================================
           BUTTONS
           ============================================ */
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
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,91,159,0.35); color: white; }

        .btn-outline {
            background: white; color: var(--umak-blue);
            border: 2px solid var(--umak-blue);
        }
        .btn-outline:hover { background: var(--umak-blue); color: white; }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
            .charts-row-2 { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .range-bar { padding: 14px 16px; flex-direction: column; align-items: stretch; }
            .range-tabs { overflow-x: auto; padding-bottom: 4px; }
            .range-tab { white-space: nowrap; flex-shrink: 0; padding: 8px 14px; font-size: 12px; }
            .custom-range-form { margin-left: 0; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .kpi-value { font-size: 22px; }
            .kpi-icon { width: 42px; height: 42px; font-size: 20px; }
            .panel { padding: 20px 16px; }
            .chart-container { height: 240px; }
            .chart-container.small { height: 220px; }
        }

        @media (max-width: 576px) {
            .kpi-grid { grid-template-columns: 1fr; }
            .range-tab { padding: 7px 12px; font-size: 11px; }
            .custom-range-form { flex-direction: column; }
            .custom-range-form input[type="date"],
            .custom-range-form button { width: 100%; }
            .member-info .member-name { max-width: 120px; }
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
        <a href="admin-reports.php" class="nav-item active">
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
                <h2>Reports & Analytics</h2>
                <p class="subtitle">Track performance and insights</p>
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
            <div>
                <h1 class="page-title">
                    <i class="bi bi-graph-up-arrow"></i>
                    Reports & Analytics
                </h1>
                <p class="page-subtitle">Monitor revenue, members, sessions, and overall gym performance.</p>
            </div>
            <div class="page-actions">
                <a href="admin-reports-export.php?range=<?= urlencode($range) ?>&start=<?= urlencode($startDate) ?>&end=<?= urlencode($endDate) ?>"
                   class="btn btn-outline" target="_blank">
                    <i class="bi bi-file-earmark-arrow-down"></i> Export Report
                </a>
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer-fill"></i> Print
                </button>
            </div>
        </div>

        <!-- ============================================
             DATE RANGE BAR
             ============================================ -->
        <div class="range-bar">
            <div class="range-tabs">
                <a href="?range=today" class="range-tab <?= $range === 'today' ? 'active' : '' ?>">Today</a>
                <a href="?range=7days" class="range-tab <?= $range === '7days' ? 'active' : '' ?>">7 Days</a>
                <a href="?range=30days" class="range-tab <?= $range === '30days' ? 'active' : '' ?>">30 Days</a>
                <a href="?range=90days" class="range-tab <?= $range === '90days' ? 'active' : '' ?>">90 Days</a>
                <a href="?range=year" class="range-tab <?= $range === 'year' ? 'active' : '' ?>">This Year</a>
                <a href="?range=all" class="range-tab <?= $range === 'all' ? 'active' : '' ?>">All Time</a>
            </div>

            <div class="range-info">
                <i class="bi bi-calendar-range"></i>
                Showing: <strong><?= htmlspecialchars($rangeLabel) ?></strong>
            </div>

            <form method="GET" class="custom-range-form">
                <input type="hidden" name="range" value="custom">
                <input type="date" name="start" value="<?= htmlspecialchars($customStart ?: $startDate) ?>" max="<?= date('Y-m-d') ?>" required>
                <input type="date" name="end" value="<?= htmlspecialchars($customEnd ?: $endDate) ?>" max="<?= date('Y-m-d') ?>" required>
                <button type="submit">
                    <i class="bi bi-search"></i> Apply
                </button>
            </form>
        </div>

        <!-- ============================================
             KPI CARDS
             ============================================ -->
        <div class="kpi-grid">
            <!-- Total Revenue -->
            <div class="kpi-card">
                <div class="kpi-icon yellow">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div class="kpi-label">Total Revenue</div>
                <div class="kpi-value"><?= formatMoney($totalRevenue) ?></div>
                <div class="kpi-change positive">
                    <i class="bi bi-check-circle-fill"></i>
                    <?= $paidCount ?> paid transaction<?= $paidCount != 1 ? 's' : '' ?>
                </div>
            </div>

            <!-- Average Transaction -->
            <div class="kpi-card">
                <div class="kpi-icon green">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <div class="kpi-label">Avg. Transaction</div>
                <div class="kpi-value"><?= formatMoney($avgTransaction) ?></div>
                <div class="kpi-change neutral">
                    <i class="bi bi-info-circle-fill"></i>
                    Per paid transaction
                </div>
            </div>

            <!-- New Members -->
            <div class="kpi-card">
                <div class="kpi-icon blue">
                    <i class="bi bi-person-plus-fill"></i>
                </div>
                <div class="kpi-label">New Members</div>
                <div class="kpi-value"><?= number_format($newMembers) ?></div>
                <div class="kpi-change neutral">
                    <i class="bi bi-people-fill"></i>
                    <?= number_format($totalMembers) ?> total members
                </div>
            </div>

            <!-- New Memberships -->
            <div class="kpi-card">
                <div class="kpi-icon purple">
                    <i class="bi bi-ticket-perforated-fill"></i>
                </div>
                <div class="kpi-label">New Memberships</div>
                <div class="kpi-value"><?= number_format($newMemberships) ?></div>
                <div class="kpi-change neutral">
                    <i class="bi bi-patch-check-fill"></i>
                    <?= number_format($activeMemberships) ?> active
                </div>
            </div>

            <!-- Sessions -->
            <div class="kpi-card">
                <div class="kpi-icon teal">
                    <i class="bi bi-calendar-event-fill"></i>
                </div>
                <div class="kpi-label">Sessions Booked</div>
                <div class="kpi-value"><?= number_format($totalSessions) ?></div>
                <div class="kpi-change neutral">
                    <i class="bi bi-check2-circle"></i>
                    <?= number_format($completedSessions) ?> completed
                </div>
            </div>

            <!-- Pending -->
            <div class="kpi-card">
                <div class="kpi-icon orange">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="kpi-label">Pending Payments</div>
                <div class="kpi-value"><?= formatMoney($totalPending) ?></div>
                <div class="kpi-change <?= $pendingCount > 0 ? 'negative' : 'neutral' ?>">
                    <i class="bi bi-clock-fill"></i>
                    <?= $pendingCount ?> awaiting payment
                </div>
            </div>

            <!-- Cancelled -->
            <div class="kpi-card">
                <div class="kpi-icon red">
                    <i class="bi bi-x-circle-fill"></i>
                </div>
                <div class="kpi-label">Cancelled</div>
                <div class="kpi-value"><?= number_format($cancelledCount) ?></div>
                <div class="kpi-change neutral">
                    <i class="bi bi-slash-circle"></i>
                    Voided transactions
                </div>
            </div>

            <!-- Pending Memberships -->
            <div class="kpi-card">
                <div class="kpi-icon pink">
                    <i class="bi bi-hourglass"></i>
                </div>
                <div class="kpi-label">Pending Memberships</div>
                <div class="kpi-value"><?= number_format($pendingMemberships) ?></div>
                <div class="kpi-change <?= $pendingMemberships > 0 ? 'negative' : 'neutral' ?>">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    Need review
                </div>
            </div>
        </div>

        <!-- ============================================
             REVENUE TREND CHART
             ============================================ -->
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">
                        <i class="bi bi-graph-up-arrow"></i>
                        Revenue Trend
                    </h3>
                    <div class="panel-subtitle">
                        Daily, weekly, or monthly revenue over the selected period
                    </div>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <!-- ============================================
             TWO-COLUMN CHARTS
             ============================================ -->
        <div class="charts-row-2">
            <!-- Membership breakdown -->
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">
                            <i class="bi bi-pie-chart-fill"></i>
                            Memberships
                        </h3>
                        <div class="panel-subtitle">
                            By plan type in selected period
                        </div>
                    </div>
                </div>
                <div class="chart-container small">
                    <?php if (!empty($membershipBreakdown)): ?>
                        <canvas id="membershipChart"></canvas>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-pie-chart"></i>
                            <p>No membership data available for this period.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Transaction type breakdown -->
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">
                            <i class="bi bi-bar-chart-fill"></i>
                            Revenue Sources
                        </h3>
                        <div class="panel-subtitle">
                            Breakdown by transaction type
                        </div>
                    </div>
                </div>
                <div class="chart-container small">
                    <?php if (!empty($txnTypeBreakdown)): ?>
                        <canvas id="txnTypeChart"></canvas>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-bar-chart"></i>
                            <p>No transaction data available for this period.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ============================================
             BREAKDOWN DETAILS ROW
             ============================================ -->
        <div class="charts-row-2">
            <!-- Membership breakdown details -->
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">
                            <i class="bi bi-ticket-perforated-fill"></i>
                            Membership Breakdown
                        </h3>
                    </div>
                </div>

                <?php if (empty($membershipBreakdown)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>No membership records in this period.</p>
                    </div>
                <?php else:
                    $totalMbCount = array_sum(array_column($membershipBreakdown, 'cnt'));
                ?>
                    <div class="breakdown-list">
                        <?php foreach ($membershipBreakdown as $mb):
                            $percent = $totalMbCount > 0 ? round(($mb['cnt'] / $totalMbCount) * 100, 1) : 0;
                            $iconClass = 'blue';
                            $icon = 'calendar-check-fill';
                            if (strtolower($mb['membership_type']) === 'monthly') { $iconClass = 'purple'; $icon = 'calendar-month-fill'; }
                            if (strtolower($mb['membership_type']) === 'yearly') { $iconClass = 'yellow'; $icon = 'calendar-heart-fill'; }
                        ?>
                            <div class="breakdown-item">
                                <div class="breakdown-icon <?= $iconClass ?>">
                                    <i class="bi bi-<?= $icon ?>"></i>
                                </div>
                                <div class="breakdown-info">
                                    <div class="breakdown-name"><?= htmlspecialchars($mb['membership_type']) ?></div>
                                    <div class="breakdown-sub"><?= number_format($mb['cnt']) ?> purchase<?= $mb['cnt'] != 1 ? 's' : '' ?></div>
                                    <div class="breakdown-bar">
                                        <div class="breakdown-bar-fill" style="width: <?= $percent ?>%; background: var(--umak-blue);"></div>
                                    </div>
                                </div>
                                <div class="breakdown-value">
                                    <div class="breakdown-amount">₱<?= number_format($mb['revenue'], 0) ?></div>
                                    <div class="breakdown-percent"><?= $percent ?>%</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Top members -->
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">
                            <i class="bi bi-trophy-fill"></i>
                            Top Members
                        </h3>
                        <div class="panel-subtitle">
                            Highest spenders in this period
                        </div>
                    </div>
                </div>

                <?php if (empty($topMembers)): ?>
                    <div class="empty-state">
                        <i class="bi bi-trophy"></i>
                        <p>No paid transactions in this period yet.</p>
                    </div>
                <?php else: ?>
                    <div class="leaderboard-list">
                        <?php foreach ($topMembers as $i => $m):
                            $rank = $i + 1;
                            $rankClass = '';
                            if ($rank === 1) $rankClass = 'gold';
                            elseif ($rank === 2) $rankClass = 'silver';
                            elseif ($rank === 3) $rankClass = 'bronze';

                            $initials = strtoupper(substr($m['fullname'], 0, 1));
                            if (strpos($m['fullname'], ' ') !== false) {
                                $parts = explode(' ', $m['fullname']);
                                $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
                            }
                        ?>
                            <div class="leaderboard-item <?= $rank <= 3 ? 'top-' . $rank : '' ?>">
                                <div class="rank-badge <?= $rankClass ?>">#<?= $rank ?></div>
                                <div class="member-avatar">
                                    <?php if (!empty($m['profile_image']) && file_exists(__DIR__ . '/../' . $m['profile_image'])): ?>
                                        <img src="../<?= htmlspecialchars($m['profile_image']) ?>" alt="">
                                    <?php else: ?>
                                        <?= htmlspecialchars($initials) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="member-info">
                                    <div class="member-name"><?= htmlspecialchars($m['fullname']) ?></div>
                                    <div class="member-meta">@<?= htmlspecialchars($m['username']) ?></div>
                                </div>
                                <div class="member-amount">
                                    <div class="member-amount-value">₱<?= number_format($m['total_spent'], 0) ?></div>
                                    <div class="member-amount-count"><?= $m['txn_count'] ?> txn<?= $m['txn_count'] != 1 ? 's' : '' ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================
             SESSION BREAKDOWN + ACTIVITY FEED
             ============================================ -->
        <div class="charts-row-2">
            <!-- Session Breakdown -->
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">
                            <i class="bi bi-calendar-event-fill"></i>
                            Session Breakdown
                        </h3>
                    </div>
                </div>

                <?php if (empty($sessionBreakdown)): ?>
                    <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <p>No session bookings in this period.</p>
                    </div>
                <?php else: ?>
                    <div class="breakdown-list">
                        <?php foreach ($sessionBreakdown as $sess):
                            $total = (int)$sess['total_count'];
                            $completed = (int)$sess['completed_count'];
                            $booked = (int)$sess['booked_count'];
                            $pending = (int)$sess['pending_count'];
                            $completedPercent = $total > 0 ? round(($completed / $total) * 100) : 0;
                        ?>
                            <div class="breakdown-item">
                                <div class="breakdown-icon blue">
                                    <i class="bi bi-heart-pulse-fill"></i>
                                </div>
                                <div class="breakdown-info">
                                    <div class="breakdown-name"><?= htmlspecialchars($sess['session_type']) ?></div>
                                    <div class="breakdown-sub">
                                        <?= $completed ?> completed · <?= $booked ?> booked · <?= $pending ?> pending
                                    </div>
                                    <div class="breakdown-bar">
                                        <div class="breakdown-bar-fill" style="width: <?= $completedPercent ?>%; background: var(--umak-green);"></div>
                                    </div>
                                </div>
                                <div class="breakdown-value">
                                    <div class="breakdown-amount"><?= $total ?></div>
                                    <div class="breakdown-percent">sessions</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Activity Feed -->
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">
                            <i class="bi bi-activity"></i>
                            Recent Activity
                        </h3>
                        <div class="panel-subtitle">
                            Latest actions in this period
                        </div>
                    </div>
                </div>

                <?php if (empty($recentActivity)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>No activity in this period.</p>
                    </div>
                <?php else: ?>
                    <div class="activity-list">
                        <?php foreach ($recentActivity as $a):
                            $typeClass = $a['type'];
                            $icon = 'credit-card-fill';
                            $verb = '';
                            if ($a['type'] === 'payment') {
                                $icon = 'cash-stack';
                                $verb = 'made a payment of <strong>₱' . number_format($a['amount'], 2) . '</strong>';
                            } elseif ($a['type'] === 'membership') {
                                $icon = 'ticket-perforated-fill';
                                $verb = 'submitted a <strong>' . htmlspecialchars($a['detail']) . '</strong>';
                            } elseif ($a['type'] === 'session') {
                                $icon = 'calendar-event-fill';
                                $verb = 'booked a <strong>' . htmlspecialchars($a['detail']) . '</strong>';
                            }
                        ?>
                            <div class="activity-item">
                                <div class="activity-icon <?= $typeClass ?>">
                                    <i class="bi bi-<?= $icon ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-text">
                                        <strong><?= htmlspecialchars($a['fullname']) ?></strong> <?= $verb ?>
                                    </div>
                                    <div class="activity-time">
                                        <i class="bi bi-clock"></i>
                                        <?= timeAgo($a['created_at']) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================
             SUMMARY STATS FOOTER
             ============================================ -->
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">
                        <i class="bi bi-clipboard-data-fill"></i>
                        Summary Snapshot
                    </h3>
                    <div class="panel-subtitle">
                        Overall gym metrics for <?= htmlspecialchars($rangeLabel) ?>
                    </div>
                </div>
            </div>

            <div class="summary-row">
                <div class="summary-stat">
                    <div class="summary-stat-value"><?= number_format($totalMembers) ?></div>
                    <div class="summary-stat-label">Total Members</div>
                </div>
                <div class="summary-stat">
                    <div class="summary-stat-value"><?= number_format($activeMembers) ?></div>
                    <div class="summary-stat-label">Active Accounts</div>
                </div>
                <div class="summary-stat">
                    <div class="summary-stat-value"><?= number_format($activeMemberships) ?></div>
                    <div class="summary-stat-label">Active Memberships</div>
                </div>
                <div class="summary-stat">
                    <div class="summary-stat-value">₱<?= number_format($totalRevenue, 0) ?></div>
                    <div class="summary-stat-label">Revenue Earned</div>
                </div>
                <div class="summary-stat">
                    <div class="summary-stat-value"><?= number_format($totalSessions) ?></div>
                    <div class="summary-stat-label">Sessions Booked</div>
                </div>
                <div class="summary-stat">
                    <div class="summary-stat-value"><?= number_format($newMembers) ?></div>
                    <div class="summary-stat-label">New This Period</div>
                </div>
            </div>
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
    // REVENUE TREND CHART
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const revCtx = document.getElementById('revenueChart');
        if (revCtx) {
            const gradient = revCtx.getContext('2d').createLinearGradient(0, 0, 0, 320);
            gradient.addColorStop(0, 'rgba(0, 91, 159, 0.35)');
            gradient.addColorStop(1, 'rgba(0, 91, 159, 0.02)');

            new Chart(revCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [{
                        label: 'Revenue (₱)',
                        data: <?= json_encode($chartRevenue) ?>,
                        borderColor: '#005b9f',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#005b9f',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 8,
                        yAxisID: 'y'
                    }, {
                        label: 'New Members',
                        data: <?= json_encode($chartMembers) ?>,
                        borderColor: '#ffce00',
                        backgroundColor: 'rgba(255,206,0,0.1)',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.4,
                        pointBackgroundColor: '#ffce00',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            align: 'end',
                            labels: {
                                padding: 15,
                                font: { size: 12, weight: '700' },
                                color: '#4b5563',
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 8,
                                boxHeight: 8
                            }
                        },
                        tooltip: {
                            backgroundColor: '#111827',
                            titleFont: { size: 13, weight: '700' },
                            bodyFont: { size: 13, weight: '600' },
                            padding: 12,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    const label = context.dataset.label || '';
                                    const value = context.parsed.y;
                                    if (label.includes('Revenue')) {
                                        return label + ': ₱' + value.toLocaleString();
                                    }
                                    return label + ': ' + value;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false },
                            ticks: {
                                font: { size: 11, weight: '600' },
                                color: '#9ca3af',
                                callback: function(value) {
                                    if (value >= 1000) return '₱' + (value / 1000).toFixed(1) + 'k';
                                    return '₱' + value;
                                }
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            beginAtZero: true,
                            grid: { drawOnChartArea: false },
                            ticks: {
                                font: { size: 11, weight: '600' },
                                color: '#b45309',
                                stepSize: 1
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: { size: 11, weight: '600' },
                                color: '#9ca3af',
                                maxRotation: 45,
                                minRotation: 0
                            }
                        }
                    }
                }
            });
        }

        // ============================================
        // MEMBERSHIP BREAKDOWN DOUGHNUT
        // ============================================
        const mbCtx = document.getElementById('membershipChart');
        if (mbCtx && <?= !empty($membershipBreakdown) ? 'true' : 'false' ?>) {
            const mbData = <?= json_encode($membershipBreakdown) ?>;
            new Chart(mbCtx, {
                type: 'doughnut',
                data: {
                    labels: mbData.map(m => m.membership_type),
                    datasets: [{
                        data: mbData.map(m => m.cnt),
                        backgroundColor: ['#005b9f', '#8b5cf6', '#ffce00', '#10b981', '#ec4899'],
                        borderWidth: 0,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 12,
                                font: { size: 12, weight: '700' },
                                color: '#4b5563',
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 8,
                                boxHeight: 8
                            }
                        },
                        tooltip: {
                            backgroundColor: '#111827',
                            padding: 12,
                            cornerRadius: 8,
                            bodyFont: { size: 13, weight: '600' },
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return label + ': ' + value + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        }

        // ============================================
        // TRANSACTION TYPE CHART
        // ============================================
        const txnCtx = document.getElementById('txnTypeChart');
        if (txnCtx && <?= !empty($txnTypeBreakdown) ? 'true' : 'false' ?>) {
            const txnData = <?= json_encode($txnTypeBreakdown) ?>;
            new Chart(txnCtx, {
                type: 'bar',
                data: {
                    labels: txnData.map(t => t.transaction_type),
                    datasets: [{
                        label: 'Revenue (₱)',
                        data: txnData.map(t => t.revenue),
                        backgroundColor: [
                            'rgba(0,91,159,0.85)',
                            'rgba(139,92,246,0.85)',
                            'rgba(16,185,129,0.85)',
                            'rgba(236,72,153,0.85)'
                        ],
                        borderRadius: 8,
                        borderSkipped: false,
                        barThickness: 40
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#111827',
                            padding: 12,
                            cornerRadius: 8,
                            bodyFont: { size: 13, weight: '600' },
                            callbacks: {
                                label: function(context) {
                                    return '₱' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false },
                            ticks: {
                                font: { size: 11, weight: '600' },
                                color: '#9ca3af',
                                callback: function(value) {
                                    if (value >= 1000) return '₱' + (value / 1000).toFixed(0) + 'k';
                                    return '₱' + value;
                                }
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: { size: 12, weight: '700' },
                                color: '#4b5563'
                            }
                        }
                    }
                }
            });
        }
    });

    // ============================================
    // PRINT STYLES
    // ============================================
    const printStyle = document.createElement('style');
    printStyle.media = 'print';
    printStyle.innerHTML = `
        .sidebar, .top-header, .page-actions, .range-tabs,
        .custom-range-form button, .header-right { display: none !important; }
        .main-wrapper { margin-left: 0 !important; }
        .container { padding: 0 !important; }
        .panel, .kpi-card { break-inside: avoid; }
        body { background: white !important; }
        .range-bar { box-shadow: none !important; }
    `;
    document.head.appendChild(printStyle);
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>