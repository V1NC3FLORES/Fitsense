<?php
// Admin/admin-dashboard.php
require_once __DIR__ . '/../config/session_security.php';

session_start();
include __DIR__ . '/../conn.php';

// ============================================
// AUTHENTICATION CHECK
// ============================================
$userSession = app_validate_session_user($conn, ['admin', 'super_admin']);
if ($userSession === null) {
    header("Location: admin-login.php");
    exit;
}

$role = $_SESSION['role'];
if ($role !== 'admin' && $role !== 'super_admin') {
    header("Location: admin-login.php");
    exit;
}

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
$stmtAdmin = $conn->prepare("
    SELECT id, fullname, username, email, profile_image, role, 
           last_login, created_at
    FROM users 
    WHERE id = ? LIMIT 1
");
if ($stmtAdmin) {
    $stmtAdmin->bind_param("i", $userId);
    $stmtAdmin->execute();
    $adminData = $stmtAdmin->get_result()->fetch_assoc();
    $stmtAdmin->close();
}

$adminName = $adminData['fullname'] ?? $_SESSION['fullname'] ?? 'Administrator';
$adminFirstName = explode(' ', trim($adminName))[0];
$adminInitial = strtoupper(substr($adminName, 0, 1));
$adminAvatar = $adminData['profile_image'] ?? $_SESSION['profile_image'] ?? '';

// ============================================
// DASHBOARD STATS
// ============================================

// --- MEMBERS ---
$totalMembers = countRows($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'user'");
$activeMembers = countRows($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'user' AND account_status = 'Active'");
$newMembersThisMonth = countRows($conn, "
    SELECT COUNT(*) AS total FROM users 
    WHERE role = 'user' 
    AND MONTH(created_at) = MONTH(CURDATE()) 
    AND YEAR(created_at) = YEAR(CURDATE())
");
$unverifiedMembers = countRows($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'user' AND is_verified = 0");

// --- MEMBERSHIPS ---
$totalMemberships = tableExists($conn, 'memberships') 
    ? countRows($conn, "SELECT COUNT(*) AS total FROM memberships") 
    : 0;
$activeMemberships = tableExists($conn, 'memberships')
    ? countRows($conn, "SELECT COUNT(*) AS total FROM memberships WHERE status = 'Active'")
    : 0;
$pendingMemberships = tableExists($conn, 'memberships')
    ? countRows($conn, "SELECT COUNT(*) AS total FROM memberships WHERE status = 'Pending'")
    : 0;
$expiredMemberships = tableExists($conn, 'memberships')
    ? countRows($conn, "SELECT COUNT(*) AS total FROM memberships WHERE status = 'Expired'")
    : 0;

// --- REVENUE ---
$totalRevenue = tableExists($conn, 'transactions')
    ? sumColumn($conn, "SELECT COALESCE(SUM(amount), 0) AS total FROM transactions WHERE status = 'Paid'")
    : 0;
$monthRevenue = tableExists($conn, 'transactions')
    ? sumColumn($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total FROM transactions 
        WHERE status = 'Paid' 
        AND MONTH(created_at) = MONTH(CURDATE()) 
        AND YEAR(created_at) = YEAR(CURDATE())
    ")
    : 0;
$pendingPayments = tableExists($conn, 'transactions')
    ? sumColumn($conn, "SELECT COALESCE(SUM(amount), 0) AS total FROM transactions WHERE status = 'Pending'")
    : 0;
$pendingTransactionsCount = tableExists($conn, 'transactions')
    ? countRows($conn, "SELECT COUNT(*) AS total FROM transactions WHERE status = 'Pending'")
    : 0;

// --- SESSIONS ---
$totalSessions = tableExists($conn, 'sessions')
    ? countRows($conn, "SELECT COUNT(*) AS total FROM sessions")
    : 0;
$pendingSessions = tableExists($conn, 'sessions')
    ? countRows($conn, "SELECT COUNT(*) AS total FROM sessions WHERE status = 'Pending'")
    : 0;
$bookedSessions = tableExists($conn, 'sessions')
    ? countRows($conn, "SELECT COUNT(*) AS total FROM sessions WHERE status = 'Booked'")
    : 0;

// --- FRANCHISE APPLICATIONS ---
$totalFranchise = tableExists($conn, 'franchise_applications')
    ? countRows($conn, "SELECT COUNT(*) AS total FROM franchise_applications")
    : 0;
$pendingFranchise = tableExists($conn, 'franchise_applications')
    ? countRows($conn, "SELECT COUNT(*) AS total FROM franchise_applications WHERE status = 'pending'")
    : 0;

// ============================================
// RECENT MEMBERS (Top 5)
// ============================================
$recentMembers = [];
$result = safeQuery($conn, "
    SELECT id, fullname, email, username, account_status, is_verified, profile_image, created_at
    FROM users 
    WHERE role = 'user' 
    ORDER BY created_at DESC 
    LIMIT 5
");
if ($result) {
    while ($row = $result->fetch_assoc()) $recentMembers[] = $row;
}

// ============================================
// PENDING MEMBERSHIP APPLICATIONS
// ============================================
$pendingApplications = [];
if (tableExists($conn, 'memberships')) {
    $result = safeQuery($conn, "
        SELECT m.id, m.membership_type, m.amount, m.status, m.created_at,
               u.id AS user_id, u.fullname, u.email, u.username, u.profile_image
        FROM memberships m
        INNER JOIN users u ON m.user_id = u.id
        WHERE m.status = 'Pending'
        ORDER BY m.created_at DESC
        LIMIT 5
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) $pendingApplications[] = $row;
    }
}

// ============================================
// RECENT TRANSACTIONS
// ============================================
$recentTransactions = [];
if (tableExists($conn, 'transactions')) {
    $result = safeQuery($conn, "
        SELECT t.id, t.reference_no, t.transaction_type, t.description, 
               t.amount, t.status, t.created_at,
               u.fullname, u.username
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        ORDER BY t.created_at DESC
        LIMIT 6
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) $recentTransactions[] = $row;
    }
}

// ============================================
// PENDING SESSIONS
// ============================================
$pendingSessionBookings = [];
if (tableExists($conn, 'sessions')) {
    $result = safeQuery($conn, "
        SELECT s.id, s.session_type, s.session_date, s.session_time, s.status,
               u.fullname, u.username
        FROM sessions s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.status = 'Pending'
        ORDER BY s.session_date ASC, s.session_time ASC
        LIMIT 5
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) $pendingSessionBookings[] = $row;
    }
}

// ============================================
// REVENUE CHART DATA (Last 6 months)
// ============================================
$chartLabels = [];
$chartRevenue = [];
$chartMembers = [];

if (tableExists($conn, 'transactions')) {
    for ($i = 5; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-$i months"));
        $monthEnd = date('Y-m-t', strtotime("-$i months"));
        $monthLabel = date('M Y', strtotime($monthStart));

        $revenue = sumColumn($conn, "
            SELECT COALESCE(SUM(amount), 0) AS total 
            FROM transactions 
            WHERE status = 'Paid'
            AND DATE(created_at) BETWEEN ? AND ?
        ", "ss", [$monthStart, $monthEnd]);

        $newMembers = countRows($conn, "
            SELECT COUNT(*) AS total FROM users 
            WHERE role = 'user' 
            AND DATE(created_at) BETWEEN ? AND ?
        ", "ss", [$monthStart, $monthEnd]);

        $chartLabels[] = $monthLabel;
        $chartRevenue[] = $revenue;
        $chartMembers[] = $newMembers;
    }
}

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

function getStatusClass($status) {
    $s = strtolower($status);
    if (in_array($s, ['active', 'paid', 'approved', 'booked', 'completed'])) return 'success';
    if (in_array($s, ['pending', 'in progress'])) return 'warning';
    if (in_array($s, ['rejected', 'cancelled', 'expired', 'inactive', 'suspended'])) return 'danger';
    return 'info';
}

function formatCurrency($amount) {
    return '₱' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - FitSense | UMak Gym</title>
    <link rel="icon" type="image/png" href="../uploads/umak-gym-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Admin Role Badge */
        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-top: 6px;
        }

        .admin-badge.super {
            background: rgba(255,206,0,0.2);
            color: var(--umak-yellow);
            border: 1px solid rgba(255,206,0,0.3);
        }

        .admin-badge.regular {
            background: rgba(74,222,128,0.2);
            color: #4ade80;
            border: 1px solid rgba(74,222,128,0.3);
        }

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
            gap: 20px; margin-bottom: 28px; flex-wrap: wrap;
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

        /* ============================================
           WELCOME BANNER
           ============================================ */
        .welcome-banner {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 20px; padding: 30px 35px;
            color: white; margin-bottom: 25px;
            position: relative; overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,91,159,0.2);
        }

        .welcome-banner::before {
            content: ''; position: absolute;
            top: -80px; right: -80px;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(255,206,0,0.18) 0%, transparent 70%);
            border-radius: 50%;
        }

        .welcome-banner::after {
            content: ''; position: absolute;
            bottom: -80px; left: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(74,222,128,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .welcome-content {
            position: relative; z-index: 2;
            display: flex; justify-content: space-between;
            align-items: center; gap: 20px; flex-wrap: wrap;
        }

        .welcome-text h2 {
            margin: 0 0 6px; font-size: 26px; font-weight: 800;
            letter-spacing: -0.5px;
        }

        .welcome-text h2 span { color: var(--umak-yellow); }

        .welcome-text p {
            margin: 0; font-size: 14px; color: rgba(255,255,255,0.8);
            line-height: 1.6; max-width: 600px;
        }

        .welcome-stats { display: flex; gap: 25px; flex-wrap: wrap; }

        .welcome-stat {
            text-align: center;
            padding: 12px 18px;
            background: rgba(255,255,255,0.08);
            border-radius: 12px; backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .welcome-stat-value {
            font-size: 22px; font-weight: 800;
            color: var(--umak-yellow); line-height: 1;
        }

        .welcome-stat-label {
            font-size: 10.5px; text-transform: uppercase;
            letter-spacing: 1px; color: rgba(255,255,255,0.7);
            margin-top: 4px; font-weight: 700;
        }

        /* ============================================
           STATS GRID
           ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 18px; margin-bottom: 25px;
        }

        .stat-card {
            background: white; border-radius: 16px; padding: 22px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border: 1px solid var(--border);
            transition: all 0.25s ease;
            position: relative; overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(0,0,0,0.08);
        }

        .stat-icon {
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; color: white; margin-bottom: 16px;
        }

        .stat-icon.blue { background: linear-gradient(135deg, #005b9f, #0084d1); box-shadow: 0 8px 20px rgba(0,91,159,0.3); }
        .stat-icon.green { background: linear-gradient(135deg, #2e7d32, #4ade80); box-shadow: 0 8px 20px rgba(46,125,50,0.3); }
        .stat-icon.yellow { background: linear-gradient(135deg, #ffce00, #ffb800); color: #333; box-shadow: 0 8px 20px rgba(255,206,0,0.4); }
        .stat-icon.purple { background: linear-gradient(135deg, #8b5cf6, #a78bfa); box-shadow: 0 8px 20px rgba(139,92,246,0.3); }
        .stat-icon.pink { background: linear-gradient(135deg, #ec4899, #f472b6); box-shadow: 0 8px 20px rgba(236,72,153,0.3); }
        .stat-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); box-shadow: 0 8px 20px rgba(245,158,11,0.3); }
        .stat-icon.red { background: linear-gradient(135deg, #d32f2f, #f87171); box-shadow: 0 8px 20px rgba(211,47,47,0.3); }
        .stat-icon.teal { background: linear-gradient(135deg, #0d849c, #06b6d4); box-shadow: 0 8px 20px rgba(13,132,156,0.3); }

        .stat-label {
            font-size: 11.5px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 1.2px;
            color: var(--text-muted); margin-bottom: 8px;
        }

        .stat-value {
            font-size: 30px; font-weight: 800; color: #111827;
            line-height: 1.1; letter-spacing: -1px;
            display: flex; align-items: baseline; gap: 6px;
        }

        .stat-value .unit {
            font-size: 14px; font-weight: 700;
            color: var(--text-muted); letter-spacing: 0;
        }

        .stat-change {
            font-size: 12px; font-weight: 700;
            margin-top: 8px;
            display: flex; align-items: center; gap: 4px;
        }

        .stat-change.positive { color: var(--umak-green); }
        .stat-change.negative { color: var(--umak-red); }
        .stat-change.neutral { color: var(--text-muted); }

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

        .panel-link {
            font-size: 13px; color: var(--umak-blue);
            text-decoration: none; font-weight: 700;
            display: inline-flex; align-items: center; gap: 4px;
            transition: 0.2s;
        }

        .panel-link:hover { color: var(--umak-blue-dark); gap: 8px; }

        /* ============================================
           CHARTS
           ============================================ */
        .charts-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px; margin-bottom: 22px;
        }

        .chart-container {
            position: relative; height: 280px;
        }

        /* ============================================
           TABLES
           ============================================ */
        .data-table {
            width: 100%; border-collapse: collapse; font-size: 13.5px;
        }

        .data-table th,
        .data-table td {
            padding: 12px 10px; text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .data-table th {
            color: var(--text-muted); font-weight: 700;
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 1px; background: #f9fafb;
        }

        .data-table th:first-child { border-radius: 8px 0 0 8px; }
        .data-table th:last-child { border-radius: 0 8px 8px 0; }

        .data-table tbody tr { transition: background 0.2s; }
        .data-table tbody tr:hover { background: #f9fafb; }

        .data-table .user-cell {
            display: flex; align-items: center; gap: 10px;
        }

        .user-avatar-sm {
            width: 36px; height: 36px; border-radius: 50%;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; display: flex; align-items: center;
            justify-content: center; font-weight: 800;
            font-size: 13px; flex-shrink: 0;
            overflow: hidden;
        }

        .user-avatar-sm img { width: 100%; height: 100%; object-fit: cover; }

        .user-info-sm { min-width: 0; }
        .user-info-sm .name {
            font-weight: 700; color: #111827;
            font-size: 13.5px; line-height: 1.2;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 180px;
        }
        .user-info-sm .sub {
            font-size: 11.5px; color: var(--text-muted);
            margin-top: 2px;
        }

        /* Status Pills */
        .status-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 11px; border-radius: 20px;
            font-size: 10.5px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        .status-pill.success { background: #dcfce7; color: #166534; }
        .status-pill.warning { background: #fef3c7; color: #92400e; }
        .status-pill.danger { background: #fee2e2; color: #991b1b; }
        .status-pill.info { background: #dbeafe; color: #1e40af; }

        .status-pill i { font-size: 9px; }

        /* Empty State */
        .empty-state {
            text-align: center; padding: 40px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 44px; opacity: 0.3;
            display: block; margin-bottom: 12px;
        }

        .empty-state p {
            margin: 0; font-size: 13.5px; line-height: 1.6;
        }

        .empty-state a {
            color: var(--umak-blue); font-weight: 700;
            text-decoration: none; margin-top: 10px;
            display: inline-block;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }

        .quick-action {
            padding: 18px 15px;
            border-radius: 12px;
            background: #f9fafb;
            border: 2px solid var(--border);
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }

        .quick-action:hover {
            transform: translateY(-4px);
            border-color: var(--umak-blue);
            box-shadow: 0 10px 25px rgba(0,91,159,0.15);
            background: white;
        }

        .quick-action-icon {
            width: 46px; height: 46px; border-radius: 14px;
            background: linear-gradient(135deg, rgba(0,91,159,0.12), rgba(0,132,209,0.18));
            color: var(--umak-blue);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
        }

        .quick-action-label {
            font-size: 12.5px; font-weight: 700; color: #374151;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 1200px) {
            .charts-row { grid-template-columns: 1fr; }
        }

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
            .welcome-banner { padding: 22px; }
            .welcome-text h2 { font-size: 20px; }
            .welcome-stats { width: 100%; justify-content: space-between; }
            .welcome-stat { flex: 1; padding: 10px; }
            .stat-value { font-size: 24px; }
            .data-table { font-size: 12px; }
            .data-table th, .data-table td { padding: 10px 6px; }
        }

        @media (max-width: 576px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .quick-actions { grid-template-columns: 1fr 1fr; }
            .welcome-stats { flex-wrap: wrap; }
            .welcome-stat { flex: 1 1 45%; }
            .user-info-sm .name { max-width: 120px; }
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
        <a href="admin-dashboard.php" class="nav-item active">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>

        <div class="nav-section-title">Management</div>
        <a href="admin-members.php" class="nav-item">
            <i class="bi bi-people-fill"></i><span>Members</span>
            <?php if ($unverifiedMembers > 0): ?>
                <span class="badge-count"><?= $unverifiedMembers ?></span>
            <?php endif; ?>
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
        <a href="admin-classes.php" class="nav-item <?= $activePage === 'classes' ? 'active' : '' ?>">
    <i class="bi bi-calendar-event-fill"></i><span>Classes</span>
    <?php if (($nav_todayClasses ?? 0) > 0): ?>
        <span class="badge-count"><?= $nav_todayClasses ?></span>
    <?php endif; ?>
</a>
        
        <a href="admin-payments.php" class="nav-item">
            <i class="bi bi-credit-card-fill"></i><span>Payment Logs</span>
            <?php if ($pendingTransactionsCount > 0): ?>
                <span class="badge-count"><?= $pendingTransactionsCount ?></span>
            <?php endif; ?>
        </a>

        <?php if ($isSuperAdmin): ?>
            <div class="nav-section-title">Super Admin</div>
            <a href="admin-franchise.php" class="nav-item">
                <i class="bi bi-shop"></i><span>Franchise Apps</span>
                <?php if ($pendingFranchise > 0): ?>
                    <span class="badge-count"><?= $pendingFranchise ?></span>
                <?php endif; ?>
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

    <!-- TOP HEADER -->
    <header class="top-header">
        <div class="header-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h2>Dashboard</h2>
                <p class="subtitle">Welcome back, <?= htmlspecialchars($adminFirstName) ?></p>
            </div>
        </div>

        <div class="header-right">
            <a href="admin-reports.php" class="header-btn" title="Reports">
                <i class="bi bi-graph-up-arrow"></i>
            </a>
            <a href="admin-notifications.php" class="header-btn" title="Notifications">
                <i class="bi bi-bell-fill"></i>
                <?php if ($pendingMemberships + $pendingTransactionsCount + $pendingSessions > 0): ?>
                    <span class="notif-badge"><?= $pendingMemberships + $pendingTransactionsCount + $pendingSessions ?></span>
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

    <!-- PAGE CONTENT -->
    <main class="container">

        <!-- ============================================
             PAGE HEADER
             ============================================ -->
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="bi bi-speedometer2"></i>
                    Dashboard
                </h1>
                <p class="page-subtitle">Overview of your gym operations, members, and revenue.</p>
            </div>
            <div class="page-actions">
                <a href="admin-reports.php" class="btn btn-outline">
                    <i class="bi bi-file-earmark-bar-graph"></i> View Reports
                </a>
                <a href="admin-members.php" class="btn btn-primary">
                    <i class="bi bi-person-plus-fill"></i> Manage Members
                </a>
            </div>
        </div>

        <!-- ============================================
             WELCOME BANNER
             ============================================ -->
        <div class="welcome-banner">
            <div class="welcome-content">
                <div class="welcome-text">
                    <h2>Welcome back, <span><?= htmlspecialchars($adminFirstName) ?></span></h2>
                    <p>
                        You're logged in as <strong><?= $isSuperAdmin ? 'Super Administrator' : 'Administrator' ?></strong>. 
                        <?php if ($pendingMemberships + $pendingTransactionsCount + $pendingSessions > 0): ?>
                            You have <strong><?= $pendingMemberships + $pendingTransactionsCount + $pendingSessions ?></strong> 
                            item<?= ($pendingMemberships + $pendingTransactionsCount + $pendingSessions) != 1 ? 's' : '' ?> requiring attention.
                        <?php else: ?>
                            Everything is up to date. No pending items require your attention.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="welcome-stats">
                    <div class="welcome-stat">
                        <div class="welcome-stat-value"><?= number_format($activeMembers) ?></div>
                        <div class="welcome-stat-label">Active Members</div>
                    </div>
                    <div class="welcome-stat">
                        <div class="welcome-stat-value"><?= number_format($monthRevenue, 0) ?></div>
                        <div class="welcome-stat-label">This Month</div>
                    </div>
                    <div class="welcome-stat">
                        <div class="welcome-stat-value"><?= number_format($pendingMemberships + $pendingTransactionsCount + $pendingSessions) ?></div>
                        <div class="welcome-stat-label">Pending Items</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================
             STATS GRID
             ============================================ -->
        <div class="stats-grid">
            <!-- Total Members -->
            <div class="stat-card">
                <div class="stat-icon blue">
                    <i class="bi bi-people-fill"></i>
                </div>
                <div class="stat-label">Total Members</div>
                <div class="stat-value"><?= number_format($totalMembers) ?></div>
                <div class="stat-change positive">
                    <i class="bi bi-arrow-up-short"></i>
                    +<?= $newMembersThisMonth ?> this month
                </div>
            </div>

            <!-- Active Memberships -->
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="bi bi-patch-check-fill"></i>
                </div>
                <div class="stat-label">Active Memberships</div>
                <div class="stat-value"><?= number_format($activeMemberships) ?></div>
                <div class="stat-change <?= $pendingMemberships > 0 ? 'negative' : 'neutral' ?>">
                    <i class="bi bi-<?= $pendingMemberships > 0 ? 'exclamation-circle' : 'check-circle' ?>"></i>
                    <?= $pendingMemberships ?> pending approval
                </div>
            </div>

            <!-- Monthly Revenue -->
            <div class="stat-card">
                <div class="stat-icon yellow">
                    <i class="bi bi-cash-coin"></i>
                </div>
                <div class="stat-label">Monthly Revenue</div>
                <div class="stat-value">₱<?= number_format($monthRevenue, 0) ?></div>
                <div class="stat-change positive">
                    <i class="bi bi-graph-up-arrow"></i>
                    Total: ₱<?= number_format($totalRevenue, 0) ?>
                </div>
            </div>

            <!-- Pending Items -->
            <div class="stat-card">
                <div class="stat-icon orange">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="stat-label">Pending Items</div>
                <div class="stat-value"><?= number_format($pendingMemberships + $pendingTransactionsCount + $pendingSessions) ?></div>
                <div class="stat-change <?= ($pendingMemberships + $pendingTransactionsCount + $pendingSessions) > 0 ? 'negative' : 'positive' ?>">
                    <i class="bi bi-<?= ($pendingMemberships + $pendingTransactionsCount + $pendingSessions) > 0 ? 'exclamation-triangle' : 'check' ?>-fill"></i>
                    <?= ($pendingMemberships + $pendingTransactionsCount + $pendingSessions) > 0 ? 'Requires attention' : 'All caught up' ?>
                </div>
            </div>

            <!-- Total Sessions -->
            <div class="stat-card">
                <div class="stat-icon purple">
                    <i class="bi bi-calendar-event-fill"></i>
                </div>
                <div class="stat-label">Total Sessions</div>
                <div class="stat-value"><?= number_format($totalSessions) ?></div>
                <div class="stat-change neutral">
                    <i class="bi bi-clock-history"></i>
                    <?= $bookedSessions ?> booked
                </div>
            </div>

            <!-- Pending Payments -->
            <div class="stat-card">
                <div class="stat-icon red">
                    <i class="bi bi-credit-card-fill"></i>
                </div>
                <div class="stat-label">Pending Payments</div>
                <div class="stat-value">₱<?= number_format($pendingPayments, 0) ?></div>
                <div class="stat-change neutral">
                    <i class="bi bi-receipt"></i>
                    <?= $pendingTransactionsCount ?> transaction<?= $pendingTransactionsCount != 1 ? 's' : '' ?>
                </div>
            </div>

            <?php if ($isSuperAdmin): ?>
            <!-- Franchise Applications (Super Admin only) -->
            <div class="stat-card">
                <div class="stat-icon teal">
                    <i class="bi bi-shop"></i>
                </div>
                <div class="stat-label">Franchise Apps</div>
                <div class="stat-value"><?= number_format($totalFranchise) ?></div>
                <div class="stat-change <?= $pendingFranchise > 0 ? 'negative' : 'positive' ?>">
                    <i class="bi bi-<?= $pendingFranchise > 0 ? 'clock' : 'check-circle' ?>-fill"></i>
                    <?= $pendingFranchise ?> pending review
                </div>
            </div>
            <?php endif; ?>

            <!-- Unverified Members -->
            <div class="stat-card">
                <div class="stat-icon pink">
                    <i class="bi bi-person-x-fill"></i>
                </div>
                <div class="stat-label">Unverified</div>
                <div class="stat-value"><?= number_format($unverifiedMembers) ?></div>
                <div class="stat-change <?= $unverifiedMembers > 0 ? 'negative' : 'positive' ?>">
                    <i class="bi bi-envelope-<?= $unverifiedMembers > 0 ? 'exclamation' : 'check' ?>-fill"></i>
                    <?= $unverifiedMembers > 0 ? 'Need verification' : 'All verified' ?>
                </div>
            </div>
        </div>

        <!-- ============================================
             CHARTS
             ============================================ -->
        <div class="charts-row">
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-graph-up-arrow"></i> Revenue Trend
                    </h3>
                    <a href="admin-reports.php" class="panel-link">
                        View Full Report <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-pie-chart-fill"></i> Membership Status
                    </h3>
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>

        <!-- ============================================
             PENDING MEMBERSHIP APPLICATIONS
             ============================================ -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <i class="bi bi-hourglass-split"></i> Pending Membership Applications
                    <?php if ($pendingMemberships > 0): ?>
                        <span style="background:#fef3c7; color:#92400e; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:800;">
                            <?= $pendingMemberships ?>
                        </span>
                    <?php endif; ?>
                </h3>
                <a href="admin-memberships.php" class="panel-link">
                    Manage All <i class="bi bi-arrow-right"></i>
                </a>
            </div>

            <?php if (empty($pendingApplications)): ?>
                <div class="empty-state">
                    <i class="bi bi-check-circle-fill" style="color: #10b981; opacity: 0.6;"></i>
                    <p><strong>All clear!</strong> No pending membership applications.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Plan</th>
                                <th>Amount</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingApplications as $app): ?>
                                <tr>
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar-sm">
                                                <?php if (!empty($app['profile_image']) && file_exists(__DIR__ . '/../' . $app['profile_image'])): ?>
                                                    <img src="../<?= htmlspecialchars($app['profile_image']) ?>" alt="">
                                                <?php else: ?>
                                                    <?= strtoupper(substr($app['fullname'] ?? 'U', 0, 1)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="user-info-sm">
                                                <div class="name"><?= htmlspecialchars($app['fullname']) ?></div>
                                                <div class="sub">@<?= htmlspecialchars($app['username']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><strong><?= htmlspecialchars($app['membership_type']) ?></strong></td>
                                    <td><strong>₱<?= number_format($app['amount'], 2) ?></strong></td>
                                    <td><?= timeAgo($app['created_at']) ?></td>
                                    <td>
                                        <span class="status-pill warning">
                                            <i class="bi bi-circle-fill"></i> Pending
                                        </span>
                                    </td>
                                    <td>
                                        <a href="admin-memberships.php?view=<?= (int)$app['id'] ?>" 
                                           class="btn btn-primary" style="padding: 6px 14px; font-size: 11px;">
                                            Review
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================
             RECENT MEMBERS + RECENT TRANSACTIONS
             ============================================ -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-bottom: 22px;">

            <!-- Recent Members -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-person-plus-fill"></i> Recent Members
                    </h3>
                    <a href="admin-members.php" class="panel-link">
                        View All <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <?php if (empty($recentMembers)): ?>
                    <div class="empty-state">
                        <i class="bi bi-people"></i>
                        <p>No members yet.</p>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($recentMembers as $member): ?>
                            <div style="display: flex; align-items: center; gap: 12px; padding: 10px; border-radius: 10px; transition: 0.2s; border: 1px solid transparent;"
                                 onmouseover="this.style.background='#f9fafb'; this.style.borderColor='var(--border)'"
                                 onmouseout="this.style.background=''; this.style.borderColor='transparent'">
                                <div class="user-avatar-sm" style="width: 42px; height: 42px; font-size: 15px;">
                                    <?php if (!empty($member['profile_image']) && file_exists(__DIR__ . '/../' . $member['profile_image'])): ?>
                                        <img src="../<?= htmlspecialchars($member['profile_image']) ?>" alt="">
                                    <?php else: ?>
                                        <?= strtoupper(substr($member['fullname'] ?? 'U', 0, 1)) ?>
                                    <?php endif; ?>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-weight: 700; font-size: 13.5px; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars($member['fullname']) ?>
                                    </div>
                                    <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">
                                        @<?= htmlspecialchars($member['username']) ?> · <?= timeAgo($member['created_at']) ?>
                                    </div>
                                </div>
                                <?php if ((int)$member['is_verified'] === 1): ?>
                                    <span class="status-pill success" style="flex-shrink: 0;">
                                        <i class="bi bi-check-circle-fill"></i>
                                    </span>
                                <?php else: ?>
                                    <span class="status-pill warning" style="flex-shrink: 0;">
                                        <i class="bi bi-hourglass-split"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Transactions -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-receipt"></i> Recent Transactions
                    </h3>
                    <a href="admin-payments.php" class="panel-link">
                        View All <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <?php if (empty($recentTransactions)): ?>
                    <div class="empty-state">
                        <i class="bi bi-receipt"></i>
                        <p>No transactions yet.</p>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($recentTransactions as $txn):
                            $statusClass = getStatusClass($txn['status']);
                        ?>
                            <div style="display: flex; align-items: center; gap: 12px; padding: 10px; border-radius: 10px; transition: 0.2s; border: 1px solid transparent;"
                                 onmouseover="this.style.background='#f9fafb'; this.style.borderColor='var(--border)'"
                                 onmouseout="this.style.background=''; this.style.borderColor='transparent'">
                                <div style="width: 42px; height: 42px; border-radius: 12px; background: rgba(0,91,159,0.1); color: var(--umak-blue); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                                    <i class="bi bi-credit-card-fill"></i>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-weight: 700; font-size: 13.5px; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars($txn['description'] ?? $txn['reference_no']) ?>
                                    </div>
                                    <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">
                                        <?= htmlspecialchars($txn['fullname'] ?? 'Unknown') ?> · <?= timeAgo($txn['created_at']) ?>
                                    </div>
                                </div>
                                <div style="text-align: right; flex-shrink: 0;">
                                    <div style="font-weight: 800; font-size: 14px; color: #111827;">
                                        ₱<?= number_format($txn['amount'], 0) ?>
                                    </div>
                                    <span class="status-pill <?= $statusClass ?>" style="margin-top: 4px;">
                                        <?= htmlspecialchars($txn['status']) ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================
             PENDING SESSION BOOKINGS
             ============================================ -->
        <?php if (!empty($pendingSessionBookings)): ?>
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <i class="bi bi-calendar-check-fill"></i> Pending Session Requests
                    <span style="background:#fef3c7; color:#92400e; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:800;">
                        <?= count($pendingSessionBookings) ?>
                    </span>
                </h3>
                <a href="admin-sessions.php" class="panel-link">
                    Manage Sessions <i class="bi bi-arrow-right"></i>
                </a>
            </div>

            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Session Type</th>
                            <th>Date & Time</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingSessionBookings as $session): ?>
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <div class="user-avatar-sm">
                                            <?= strtoupper(substr($session['fullname'] ?? 'U', 0, 1)) ?>
                                        </div>
                                        <div class="user-info-sm">
                                            <div class="name"><?= htmlspecialchars($session['fullname'] ?? 'Unknown') ?></div>
                                            <div class="sub">@<?= htmlspecialchars($session['username'] ?? '') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><strong><?= htmlspecialchars($session['session_type']) ?></strong></td>
                                <td>
                                    <?= date('M j, Y', strtotime($session['session_date'])) ?>
                                    <div style="font-size: 11.5px; color: var(--text-muted);">
                                        <?= date('g:i A', strtotime($session['session_time'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-pill warning">
                                        <i class="bi bi-hourglass-split"></i> Pending
                                    </span>
                                </td>
                                <td>
                                    <a href="admin-sessions.php?view=<?= (int)$session['id'] ?>" 
                                       class="btn btn-primary" style="padding: 6px 14px; font-size: 11px;">
                                        Review
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================
             QUICK ACTIONS
             ============================================ -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <i class="bi bi-lightning-charge-fill" style="color: var(--umak-yellow);"></i>
                    Quick Actions
                </h3>
            </div>

            <div class="quick-actions">
                <a href="admin-members.php" class="quick-action">
                    <div class="quick-action-icon">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div class="quick-action-label">Manage Members</div>
                </a>

                <a href="admin-memberships.php" class="quick-action">
                    <div class="quick-action-icon">
                        <i class="bi bi-ticket-perforated-fill"></i>
                    </div>
                    <div class="quick-action-label">Memberships</div>
                </a>

                <a href="admin-sessions.php" class="quick-action">
                    <div class="quick-action-icon">
                        <i class="bi bi-calendar-event-fill"></i>
                    </div>
                    <div class="quick-action-label">Sessions</div>
                </a>

                <a href="admin-payments.php" class="quick-action">
                    <div class="quick-action-icon">
                        <i class="bi bi-credit-card-fill"></i>
                    </div>
                    <div class="quick-action-label">Payments</div>
                </a>

                <a href="admin-reports.php" class="quick-action">
                    <div class="quick-action-icon">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <div class="quick-action-label">Reports</div>
                </a>

                <?php if ($isSuperAdmin): ?>
                <a href="admin-admins.php" class="quick-action">
                    <div class="quick-action-icon">
                        <i class="bi bi-person-badge-fill"></i>
                    </div>
                    <div class="quick-action-label">Manage Admins</div>
                </a>
                <?php endif; ?>
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
    // REVENUE CHART
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('revenueChart');
        if (ctx) {
            const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 280);
            gradient.addColorStop(0, 'rgba(0, 91, 159, 0.3)');
            gradient.addColorStop(1, 'rgba(0, 91, 159, 0.02)');

            new Chart(ctx, {
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
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#111827',
                            titleFont: { size: 13, weight: '700' },
                            bodyFont: { size: 13, weight: '600' },
                            padding: 12,
                            cornerRadius: 8,
                            displayColors: false,
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
                            grid: { color: 'rgba(0, 0, 0, 0.05)', drawBorder: false },
                            ticks: {
                                font: { size: 11, weight: '600' },
                                color: '#9ca3af',
                                callback: function(value) {
                                    return '₱' + (value / 1000) + 'k';
                                }
                            }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { size: 11, weight: '600' }, color: '#9ca3af' }
                        }
                    }
                }
            });
        }

        // ============================================
        // MEMBERSHIP STATUS DOUGHNUT
        // ============================================
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Active', 'Pending', 'Expired'],
                    datasets: [{
                        data: [
                            <?= $activeMemberships ?>,
                            <?= $pendingMemberships ?>,
                            <?= $expiredMemberships ?>
                        ],
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                        borderWidth: 0,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '68%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                font: { size: 12, weight: '600' },
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
                            bodyFont: { size: 13, weight: '600' }
                        }
                    }
                }
            });
        }
    });
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>