<?php
require_once __DIR__ . '/config/session_security.php';

session_start();
include 'conn.php';

// ============================================
// AUTHENTICATION CHECK
// ============================================
$userSession = app_validate_session_user($conn, 'user');
if ($userSession === null) {
    header("Location: login.php");
    exit();
}

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

$userColumns = getTableColumns($conn, 'users');
$hasColumn = function($col) use ($userColumns) {
    return in_array($col, $userColumns);
};

// ============================================
// FETCH USER DATA (with profile picture)
// ============================================
$selectFields = ['id', 'username'];
foreach (['fullname', 'email', 'profile_image'] as $f) {
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
// FETCH UNREAD NOTIFICATIONS
// ============================================
$unreadNotifCount = 0;
$recentNotifications = [];

if (tableExists($conn, 'notifications')) {
    $result = safeQuery($conn,
        "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $unreadNotifCount = (int)$row['total'];
    }

    $result = safeQuery($conn,
        "SELECT id, title, message, type, created_at 
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
        "i", [$userId]
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) $recentNotifications[] = $row;
    }
}

// ============================================
// GYM CAPACITY
// ============================================
$maxCapacity = 20;
$currentOccupancy = 0;

if (tableExists($conn, 'attendance')) {
    $result = safeQuery($conn,
        "SELECT COUNT(*) as current_count FROM attendance 
         WHERE DATE(check_in_time) = CURDATE() AND check_out_time IS NULL"
    );
    if ($result && $row = $result->fetch_assoc()) {
        $currentOccupancy = (int)$row['current_count'];
    }
}
$occupancyPercentage = $maxCapacity > 0 ? round(($currentOccupancy / $maxCapacity) * 100) : 0;
$availableSlots = max(0, $maxCapacity - $currentOccupancy);

// ============================================
// MEMBERSHIP STATUS
// ============================================
$membershipStatus = "INACTIVE";
$membershipExpiry = "N/A";
$membershipPlan = "No Plan";
$daysUntilExpiry = 0;

if (tableExists($conn, 'memberships')) {
    $result = safeQuery($conn,
        "SELECT status, end_date, membership_type FROM memberships 
         WHERE user_id = ? ORDER BY end_date DESC LIMIT 1",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $membershipStatus = strtoupper($row['status'] ?? 'INACTIVE');
        $membershipExpiry = !empty($row['end_date']) ? date('M d, Y', strtotime($row['end_date'])) : 'N/A';
        $membershipPlan = $row['membership_type'] ?? 'Standard';
        
        if (!empty($row['end_date'])) {
            $daysUntilExpiry = max(0, (int)((strtotime($row['end_date']) - time()) / 86400));
        }
    }
}

// ============================================
// MONTHLY ATTENDANCE
// ============================================
$monthlyVisits = 0;
$weeklyData = [0, 0, 0, 0, 0, 0, 0];

if (tableExists($conn, 'attendance')) {
    $result = safeQuery($conn,
        "SELECT COUNT(*) as visits FROM attendance 
         WHERE user_id = ? AND MONTH(check_in_time) = MONTH(CURDATE()) 
         AND YEAR(check_in_time) = YEAR(CURDATE())",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $monthlyVisits = (int)$row['visits'];
    }
    
    $result = safeQuery($conn,
        "SELECT DAYOFWEEK(check_in_time) as day, COUNT(*) as cnt 
         FROM attendance 
         WHERE user_id = ? AND YEARWEEK(check_in_time, 1) = YEARWEEK(CURDATE(), 1)
         GROUP BY DAYOFWEEK(check_in_time)",
        "i", [$userId]
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $dayIndex = ((int)$row['day'] + 5) % 7;
            $weeklyData[$dayIndex] = (int)$row['cnt'];
        }
    }
}

// ============================================
// WORKOUT STATS
// ============================================
$workoutsThisWeek = 0;
if (tableExists($conn, 'workout_completions')) {
    $result = safeQuery($conn,
        "SELECT COUNT(DISTINCT completion_date) as total 
         FROM workout_completions 
         WHERE user_id = ? AND YEARWEEK(completion_date, 1) = YEARWEEK(CURDATE(), 1)",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $workoutsThisWeek = (int)$row['total'];
    }
}

// ============================================
// UPCOMING CLASSES
// ============================================
$upcomingClasses = [];
if (tableExists($conn, 'class_bookings')) {
    $result = safeQuery($conn,
        "SELECT c.name, c.class_date, c.start_time, u.fullname AS coach_name
         FROM class_bookings cb
         INNER JOIN classes c ON cb.class_id = c.id
         LEFT JOIN users u ON c.coach_id = u.id
         WHERE cb.user_id = ? AND cb.status = 'Confirmed' AND c.class_date >= CURDATE()
         ORDER BY c.class_date ASC, c.start_time ASC
         LIMIT 3",
        "i", [$userId]
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) $upcomingClasses[] = $row;
    }
}

// ============================================
// RECENT TRANSACTIONS
// ============================================
$transactions = [];
if (tableExists($conn, 'transactions')) {
    $result = safeQuery($conn,
        "SELECT reference_no, description, amount, status, created_at 
         FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
        "i", [$userId]
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) $transactions[] = $row;
    }
}

// Notification type icons (no emojis, use Bootstrap Icons)
function getNotifIcon($type) {
    $icons = [
        'success' => 'bi-check-circle-fill',
        'warning' => 'bi-exclamation-triangle-fill',
        'info' => 'bi-info-circle-fill',
        'error' => 'bi-x-circle-fill',
        'achievement' => 'bi-trophy-fill',
        'message' => 'bi-chat-dots-fill',
        'payment' => 'bi-credit-card-fill',
        'class' => 'bi-calendar-event-fill',
        'system' => 'bi-gear-fill',
    ];
    return $icons[$type] ?? 'bi-bell-fill';
}

function getNotifColor($type) {
    $colors = [
        'success' => ['bg' => '#dcfce7', 'text' => '#166534'],
        'warning' => ['bg' => '#fef3c7', 'text' => '#92400e'],
        'info' => ['bg' => '#dbeafe', 'text' => '#1e40af'],
        'error' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
        'achievement' => ['bg' => '#fff8e1', 'text' => '#92400e'],
        'message' => ['bg' => '#e9d5ff', 'text' => '#6b21a8'],
        'payment' => ['bg' => '#e0e7ff', 'text' => '#3730a3'],
        'class' => ['bg' => '#fbcfe8', 'text' => '#9d174d'],
        'system' => ['bg' => '#f3f4f6', 'text' => '#374151'],
    ];
    return $colors[$type] ?? $colors['info'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - UMak Gym: Fitsense</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<?php include 'brand.php'; renderBrand('favicon'); ?>    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
           SIDEBAR NAVIGATION
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
            font-weight: bold; font-size: 16px; transition: transform 0.2s;
            overflow: hidden;
        }

        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar:hover { transform: scale(1.05); }

        /* ============================================
           PAGE CONTENT
           ============================================ */
        .container { max-width: 1300px; margin: 30px auto; padding: 0 25px; }

        .greeting {
            font-size: 26px; font-weight: 700; color: var(--umak-blue);
            margin-bottom: 6px; display: flex; align-items: center; gap: 10px;
        }

        .greeting i { font-size: 26px; }

        .greeting-sub { color: var(--text-muted); font-size: 14px; margin-bottom: 25px; }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border-top: 4px solid var(--umak-blue);
            position: relative;
            transition: all 0.25s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }

        .stat-card.yellow { border-top-color: var(--umak-yellow); }
        .stat-card.green { border-top-color: var(--umak-green); }
        .stat-card.orange { border-top-color: var(--umak-orange); }

        .stat-icon {
            width: 42px; height: 42px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; margin-bottom: 12px;
            background: rgba(0,91,159,0.1); color: var(--umak-blue);
        }

        .stat-card.yellow .stat-icon { background: rgba(255,206,0,0.15); color: #b8860b; }
        .stat-card.green .stat-icon { background: rgba(46,125,50,0.1); color: var(--umak-green); }
        .stat-card.orange .stat-icon { background: rgba(245,158,11,0.12); color: #b45309; }

        .stat-title {
            font-size: 12px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;
        }

        .stat-value {
            font-size: 26px; font-weight: 800; color: #111827;
            margin-bottom: 4px; letter-spacing: -0.5px;
        }

        .stat-subtitle {
            font-size: 13px; color: var(--text-muted); margin-bottom: 15px;
        }

        .status-badge {
            display: inline-block; background: #e8f5e9; color: var(--umak-green);
            padding: 4px 12px; border-radius: 20px; font-size: 12px;
            font-weight: 700; margin-bottom: 8px;
        }

        .status-badge.inactive { background: #fee2e2; color: #b91c1c; }
        .status-badge.warning { background: #fef3c7; color: #b45309; }

        .btn-action {
            background: var(--umak-blue); color: #fff; border: none;
            padding: 8px 16px; border-radius: 6px; font-size: 12px;
            font-weight: 600; cursor: pointer; transition: 0.2s;
            display: inline-flex; align-items: center; gap: 5px;
            text-decoration: none;
        }

        .btn-action:hover { background: var(--umak-blue-dark); color: white; }
        .btn-renew { background: var(--umak-yellow); color: #333; }
        .btn-renew:hover { background: #e5b800; color: #333; }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .panel {
            background: var(--card-bg);
            padding: 22px;
            border-radius: 12px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }

        .panel.full-width { grid-column: 1 / -1; }

        .panel-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 18px; padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        .panel-title {
            font-size: 16px; font-weight: 700; margin: 0;
            color: var(--umak-blue); display: flex; align-items: center; gap: 8px;
        }

        .panel-title i { font-size: 18px; }

        .panel-link {
            font-size: 13px; color: var(--umak-blue);
            text-decoration: none; font-weight: 600;
            display: inline-flex; align-items: center; gap: 4px;
            transition: 0.2s;
        }

        .panel-link:hover { color: var(--umak-blue-dark); gap: 8px; }

        /* Progress Bar */
        .progress-container {
            background: #f3f4f6; height: 22px; border-radius: 12px;
            overflow: hidden; margin-bottom: 10px; position: relative;
        }

        .progress-bar {
            height: 100%; background: linear-gradient(90deg, #005b9f, #0084d1);
            width: var(--progress-width, 0%); transition: width 0.5s ease;
            border-radius: 12px;
        }

        .progress-bar.warning { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .progress-bar.danger { background: linear-gradient(90deg, #d32f2f, #ef4444); }

        /* Chart */
        .chart-container { height: 260px; position: relative; }

        /* Activity List */
        .activity-item {
            display: flex; align-items: flex-start;
            gap: 14px; padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .activity-item:last-child { border-bottom: none; }

        .activity-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }

        .activity-content { flex: 1; min-width: 0; }

        .activity-title {
            font-weight: 700; color: #1a1a2e;
            font-size: 14px; margin-bottom: 3px;
        }

        .activity-message {
            color: #6b7280; font-size: 13px; line-height: 1.5;
            display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden;
        }

        .activity-time {
            color: #9ca3af; font-size: 11.5px;
            white-space: nowrap; flex-shrink: 0;
            display: flex; align-items: center; gap: 4px;
        }

        /* Upcoming Class Item */
        .class-item {
            display: flex; align-items: center;
            gap: 14px; padding: 12px;
            border-radius: 10px; background: #f9fafb;
            margin-bottom: 8px; transition: 0.2s;
            text-decoration: none; color: inherit;
        }

        .class-item:hover {
            background: #f0f7fc; transform: translateX(3px);
        }

        .class-date-box {
            width: 50px; height: 50px;
            border-radius: 10px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .class-date-day { font-size: 18px; font-weight: 800; line-height: 1; }
        .class-date-month { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; }

        .class-info { flex: 1; min-width: 0; }
        .class-name { font-size: 14px; font-weight: 800; color: #111827; margin: 0 0 3px; }
        .class-meta { font-size: 12px; color: var(--text-muted); display: flex; gap: 10px; flex-wrap: wrap; }
        .class-meta i { font-size: 11px; }

        /* Quick Actions */
        .quick-action-btn {
            padding: 20px 15px;
            border-radius: 12px;
            border: 2px dashed #e5e7eb;
            background: transparent;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            width: 100%;
            color: #6b7280;
            text-decoration: none;
            display: block;
        }

        .quick-action-btn:hover {
            border-color: var(--umak-blue);
            background: rgba(0,91,159,0.05);
            color: var(--umak-blue);
            transform: translateY(-3px);
        }

        .quick-action-btn i {
            font-size: 28px; display: block; margin-bottom: 8px;
        }

        .quick-action-btn span {
            font-size: 13px; font-weight: 600;
        }

        /* Transactions Table */
        .transaction-table {
            width: 100%; border-collapse: collapse; font-size: 14px;
        }

        .transaction-table th, .transaction-table td {
            padding: 12px 10px; text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .transaction-table th {
            color: var(--text-muted); font-weight: 600;
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .transaction-table tbody tr:hover { background: #f9fafb; }

        .badge-paid, .badge-pending, .badge-overdue {
            padding: 4px 10px; border-radius: 12px; font-size: 11px;
            font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        }

        .badge-paid { background: #e8f5e9; color: var(--umak-green); }
        .badge-pending { background: #fef3c7; color: #b45309; }
        .badge-overdue { background: #fee2e2; color: #b91c1c; }

        /* Empty State */
        .empty-state-small {
            text-align: center; padding: 30px 15px;
            color: var(--text-muted);
        }

        .empty-state-small i {
            font-size: 36px; opacity: 0.3;
            display: block; margin-bottom: 10px;
        }

        .empty-state-small p {
            margin: 0; font-size: 13.5px; line-height: 1.6;
        }

        .empty-state-small a {
            color: var(--umak-blue); font-weight: 700;
            text-decoration: none; display: inline-block; margin-top: 10px;
        }

        

        /* ============================================
           CHAT PANEL (SLIDE-IN)
           ============================================ */
        .chat-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            opacity: 0; visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1999;
        }

        .chat-overlay.open { opacity: 1; visibility: visible; }

        .chat-panel {
            position: fixed; top: 0; right: 0;
            width: 900px; max-width: 95vw; height: 100vh;
            background: #fff;
            box-shadow: -10px 0 40px rgba(0,0,0,0.15);
            transform: translateX(100%);
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 2000;
            display: flex; flex-direction: column;
        }

        .chat-panel.open { transform: translateX(0); }

        .chat-panel-header {
            background: linear-gradient(135deg, #005b9f 0%, #0084d1 100%);
            color: white; padding: 18px 22px;
            display: flex; justify-content: space-between; align-items: center;
            flex-shrink: 0;
        }

        .chat-panel-header h3 {
            margin: 0; font-size: 18px; font-weight: 700;
            display: flex; align-items: center; gap: 10px;
        }

        .chat-search {
            display: flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.15);
            padding: 8px 14px; border-radius: 20px;
            margin-left: 20px; flex: 1; max-width: 300px;
        }

        .chat-search input {
            background: none; border: none; color: white;
            outline: none; width: 100%; font-size: 13px;
        }

        .chat-search input::placeholder { color: rgba(255,255,255,0.7); }

        .chat-close-btn {
            background: rgba(255,255,255,0.15); border: none; color: white;
            width: 36px; height: 36px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center;
            justify-content: center; font-size: 18px; transition: 0.2s;
        }

        .chat-close-btn:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }

        .chat-panel-body { display: flex; flex: 1; overflow: hidden; }

        .chat-sidebar {
            width: 240px; background: #f9fafb;
            border-right: 1px solid var(--border);
            overflow-y: auto; flex-shrink: 0;
        }

        .chat-filter-tabs { padding: 15px 15px 5px; }

        .chat-filter-tab {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; border-radius: 8px;
            cursor: pointer; font-size: 13px; font-weight: 600;
            color: var(--text-muted); margin-bottom: 2px; transition: 0.2s;
        }

        .chat-filter-tab:hover { background: #e5e7eb; color: var(--text); }
        .chat-filter-tab.active { background: rgba(0,91,159,0.1); color: var(--umak-blue); }

        .chat-divider { height: 1px; background: var(--border); margin: 12px 15px; }

        .chat-contacts-list { padding: 0 8px 15px; }

        .chat-contact-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 8px;
            cursor: pointer; font-size: 13px; transition: 0.2s;
            margin-bottom: 2px;
        }

        .chat-contact-item:hover { background: #e5e7eb; }
        .chat-contact-item.active {
            background: rgba(0,91,159,0.1);
            border-left: 3px solid var(--umak-blue);
        }

        .chat-contact-item .contact-avatar {
            font-size: 16px; flex-shrink: 0;
        }

        .chat-contact-item .contact-name {
            font-weight: 600; color: var(--text); flex: 1;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .chat-contact-item .unread-tag {
            background: var(--umak-blue); color: white;
            font-size: 10px; font-weight: 700;
            padding: 2px 7px; border-radius: 10px; flex-shrink: 0;
        }

        .chat-main {
            flex: 1; display: flex; flex-direction: column;
            background: #fff; min-width: 0;
        }

        .chat-main-header {
            padding: 15px 22px;
            border-bottom: 1px solid var(--border);
            background: #fff; flex-shrink: 0;
        }

        .chat-main-header h4 {
            margin: 0; font-size: 15px; color: var(--umak-blue); font-weight: 700;
        }

        .chat-main-header p {
            margin: 2px 0 0; font-size: 12px; color: var(--text-muted);
        }

        .chat-messages {
            flex: 1; padding: 20px 22px;
            overflow-y: auto; background: #fafbfc;
            display: flex; flex-direction: column; gap: 14px;
        }

        .chat-message-item { display: flex; flex-direction: column; gap: 4px; }

        .chat-message-sender {
            font-size: 12px; font-weight: 700; color: var(--umak-blue);
            display: flex; align-items: center; gap: 6px;
        }

        .chat-message-text {
            background: #fff; padding: 10px 14px;
            border-radius: 12px; border: 1px solid var(--border);
            font-size: 14px; line-height: 1.5; max-width: 85%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }

        .chat-message-item.self { align-items: flex-end; }
        .chat-message-item.self .chat-message-sender { color: var(--umak-green); flex-direction: row-reverse; }
        .chat-message-item.self .chat-message-text {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; border-color: transparent;
        }

        .chat-message-time {
            font-size: 10px; color: var(--text-muted); margin-top: 2px;
        }

        .chat-input-area {
            padding: 15px 22px;
            border-top: 1px solid var(--border);
            background: #fff; display: flex; align-items: center;
            gap: 12px; flex-shrink: 0;
        }

        .emoji-btn {
            background: none; border: none; font-size: 20px;
            cursor: pointer; padding: 5px; border-radius: 6px; transition: 0.2s;
        }

        .emoji-btn:hover { background: #f3f4f6; }

        .chat-input-area input {
            flex: 1; padding: 11px 16px;
            border: 1px solid var(--border); border-radius: 22px;
            outline: none; font-size: 14px; transition: 0.2s;
        }

        .chat-input-area input:focus {
            border-color: var(--umak-blue);
            box-shadow: 0 0 0 3px rgba(0,91,159,0.1);
        }

        .chat-send-btn {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; border: none; width: 42px; height: 42px;
            border-radius: 50%; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; transition: 0.2s; flex-shrink: 0;
        }

        .chat-send-btn:hover { transform: scale(1.08); }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
            .content-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .btn-chat-open span.label { display: none; }
            .container { padding: 0 15px; margin: 20px auto; }
            .greeting { font-size: 20px; }
            .chat-sidebar { width: 200px; }
            .chat-panel { width: 100%; }
            .stats-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 576px) {
            .chat-sidebar { display: none; }
            .header-left .subtitle { display: none; }
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
        <a href="user-dashboard.php" class="nav-item active">
            <i class="bi bi-house-door-fill"></i><span>Dashboard</span>
        </a>
        <a href="my-membership.php" class="nav-item">
            <i class="bi bi-ticket-perforated-fill"></i><span>My Membership</span>
        </a>
        <a href="attendance.php" class="nav-item">
            <i class="bi bi-bar-chart-fill"></i><span>Attendance</span>
        </a>

        <div class="nav-section-title">Fitness</div>
        <a href="workout-plan.php" class="nav-item">
            <i class="bi bi-heart-pulse-fill"></i><span>Workout Plan</span>
        </a>
        <a href="nutrition-plan.php" class="nav-item">
            <i class="bi bi-egg-fried"></i><span>Nutrition Plan</span>
        </a>
        <a href="my-progress.php" class="nav-item">
            <i class="bi bi-graph-up-arrow"></i><span>My Progress</span>
        </a>

        <div class="nav-section-title">Services</div>
        <a href="classes.php" class="nav-item">
            <i class="bi bi-calendar-event-fill"></i><span>Classes & Booking</span>
        </a>
        <a href="payments.php" class="nav-item">
            <i class="bi bi-credit-card-fill"></i><span>Payments & Billing</span>
        </a>
        <a href="notifications.php" class="nav-item">
            <i class="bi bi-bell-fill"></i><span>Notifications</span>
            <?php if ($unreadNotifCount > 0): ?>
                <span class="badge-count"><?= $unreadNotifCount ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section-title">Account</div>
        <a href="my-profile.php" class="nav-item">
            <i class="bi bi-person-circle"></i><span>My Profile</span>
        </a>
        <a href="help.php" class="nav-item">
            <i class="bi bi-life-preserver"></i><span>Help & Support</span>
        </a>
        <a href="settings.php" class="nav-item">
            <i class="bi bi-gear-fill"></i><span>Settings</span>
        </a>
        <div class="nav-divider"></div>
        <a href="logout.php" class="nav-item nav-logout">
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
                <p class="subtitle">Welcome back, <?= htmlspecialchars($firstName) ?></p>
            </div>
        </div>

        <div class="header-right">
            <!-- Chat Button - Opens Chat Panel -->
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
                    <?php if (!empty($userAvatar) && file_exists(__DIR__ . '/' . $userAvatar)): ?>
                        <img src="<?= htmlspecialchars($userAvatar) ?>" alt="<?= htmlspecialchars($displayName) ?>">
                    <?php else: ?>
                        <?= htmlspecialchars($userInitial) ?>
                    <?php endif; ?>
                </div>
            </button>
        </div>
    </header>

    <!-- PAGE CONTENT -->
    <main class="container">

        <div class="greeting">
            <i class="bi bi-hand-thumbs-up-fill" style="color: var(--umak-yellow);"></i>
            Welcome back, <?= htmlspecialchars($firstName) ?>
        </div>
        <p class="greeting-sub">Here's your fitness overview for today, <?= date('F d, Y') ?>.</p>

        <!-- ============================================
             STATS GRID
             ============================================ -->
        <div class="stats-grid">
           <!-- Gym Capacity (LIVE) -->
<div class="stat-card <?= $occupancyPercentage >= 80 ? 'status-high' : ($occupancyPercentage >= 50 ? 'status-medium' : 'status-low') ?>" id="liveCapacityCard">
    <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
    <div class="stat-title-row">
        <span class="stat-title" style="margin: 0;">Gym Capacity</span>
        <span class="live-pill" id="livePill">
            <span class="live-pill-dot"></span> Live
        </span>
    </div>
    <div class="stat-value">
        <span id="liveOccupancy"><?= $currentOccupancy ?></span> / <span id="liveMax"><?= $maxCapacity ?></span>
    </div>
    <div class="stat-subtitle">
        <span id="livePercentText"><?= $occupancyPercentage ?>% Full</span>
        &middot;
        <span id="liveAvailableText"><?= $availableSlots ?> slot<?= $availableSlots != 1 ? 's' : '' ?> available</span>
    </div>
    <div class="progress-container">
        <div class="progress-bar <?= $occupancyPercentage > 80 ? 'danger' : ($occupancyPercentage > 50 ? 'warning' : '') ?>"
             id="liveProgressBar"
             style="--progress-width: <?= $occupancyPercentage ?>%"></div>
    </div>
    <a href="classes.php" class="btn-action">
        <i class="bi bi-calendar-check"></i> Reserve Slot
    </a>
    <div class="live-updated" id="liveUpdated">
        <i class="bi bi-clock-history"></i>
        <span>Updated: <?= date('g:i:s A') ?></span>
    </div>
</div>

            <!-- Membership -->
            <div class="stat-card yellow">
                <div class="stat-icon"><i class="bi bi-ticket-perforated-fill"></i></div>
                <div class="stat-title">Membership</div>
                <span class="status-badge <?= $membershipStatus === 'INACTIVE' ? 'inactive' : ($daysUntilExpiry > 0 && $daysUntilExpiry <= 7 ? 'warning' : '') ?>">
                    <?= htmlspecialchars($membershipStatus) ?>
                </span>
                <div class="stat-subtitle">
                    <?= htmlspecialchars($membershipPlan) ?> &middot; 
                    <?php if ($daysUntilExpiry > 0): ?>
                        <?= $daysUntilExpiry ?> days left
                    <?php else: ?>
                        Until <?= htmlspecialchars($membershipExpiry) ?>
                    <?php endif; ?>
                </div>
                <a href="my-membership.php" class="btn-action btn-renew">
                    <i class="bi bi-arrow-repeat"></i> View Membership
                </a>
            </div>

            <!-- Monthly Attendance -->
            <div class="stat-card green">
                <div class="stat-icon"><i class="bi bi-calendar-check-fill"></i></div>
                <div class="stat-title">Monthly Attendance</div>
                <div class="stat-value"><?= $monthlyVisits ?></div>
                <div class="stat-subtitle">visits this month</div>
                <a href="attendance.php" class="btn-action" style="background: var(--umak-green);">
                    <i class="bi bi-list-check"></i> View Log
                </a>
            </div>

            <!-- Workouts -->
            <div class="stat-card orange">
                <div class="stat-icon"><i class="bi bi-activity"></i></div>
                <div class="stat-title">Workouts This Week</div>
                <div class="stat-value"><?= $workoutsThisWeek ?></div>
                <div class="stat-subtitle">sessions completed</div>
                <a href="workout-plan.php" class="btn-action" style="background: var(--umak-orange);">
                    <i class="bi bi-arrow-right"></i> View Plan
                </a>
            </div>
        </div>

        <!-- ============================================
             WEEKLY ACTIVITY + NOTIFICATIONS
             ============================================ -->
        <div class="content-grid">
            <!-- Weekly Activity Chart -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-graph-up"></i> Weekly Activity
                    </h3>
                    <a href="attendance.php" class="panel-link">
                        View All <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
                <div class="chart-container">
                    <canvas id="weeklyChart"></canvas>
                </div>
            </div>

            <!-- Recent Notifications -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-bell-fill"></i> Recent Notifications
                    </h3>
                    <a href="notifications.php" class="panel-link">
                        View All <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <?php if (empty($recentNotifications)): ?>
                    <div class="empty-state-small">
                        <i class="bi bi-bell-slash"></i>
                        <p>No notifications yet.<br>You'll see updates here when they arrive.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recentNotifications as $notif):
                        $color = getNotifColor($notif['type']);
                        $icon = getNotifIcon($notif['type']);
                        $timeAgo = time() - strtotime($notif['created_at']);
                        if ($timeAgo < 60) $timeStr = 'just now';
                        elseif ($timeAgo < 3600) $timeStr = floor($timeAgo / 60) . ' min ago';
                        elseif ($timeAgo < 86400) $timeStr = floor($timeAgo / 3600) . ' hr ago';
                        else $timeStr = floor($timeAgo / 86400) . ' days ago';
                    ?>
                        <div class="activity-item">
                            <div class="activity-icon" style="background: <?= $color['bg'] ?>; color: <?= $color['text'] ?>;">
                                <i class="bi <?= $icon ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title"><?= htmlspecialchars($notif['title']) ?></div>
                                <div class="activity-message"><?= htmlspecialchars($notif['message']) ?></div>
                            </div>
                            <div class="activity-time">
                                <i class="bi bi-clock"></i>
                                <?= $timeStr ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================
             UPCOMING CLASSES + QUICK ACTIONS
             ============================================ -->
        <div class="content-grid">
            <!-- Upcoming Classes -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-calendar-event-fill"></i> Upcoming Classes
                    </h3>
                    <a href="classes.php" class="panel-link">
                        Browse <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <?php if (empty($upcomingClasses)): ?>
                    <div class="empty-state-small">
                        <i class="bi bi-calendar-x"></i>
                        <p>No upcoming classes booked.</p>
                        <a href="classes.php">Browse Classes</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcomingClasses as $class):
                        $classDate = strtotime($class['class_date']);
                    ?>
                        <a href="classes.php" class="class-item">
                            <div class="class-date-box">
                                <div class="class-date-day"><?= date('j', $classDate) ?></div>
                                <div class="class-date-month"><?= date('M', $classDate) ?></div>
                            </div>
                            <div class="class-info">
                                <div class="class-name"><?= htmlspecialchars($class['name']) ?></div>
                                <div class="class-meta">
                                    <span><i class="bi bi-clock"></i> <?= date('g:i A', strtotime($class['start_time'])) ?></span>
                                    <?php if (!empty($class['coach_name'])): ?>
                                        <span><i class="bi bi-person-fill"></i> <?= htmlspecialchars($class['coach_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <i class="bi bi-chevron-right" style="color: var(--text-muted);"></i>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-lightning-charge-fill"></i> Quick Actions
                    </h3>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <a href="workout-plan.php" class="quick-action-btn">
                        <i class="bi bi-heart-pulse-fill"></i>
                        <span>Workout Plan</span>
                    </a>
                    <a href="nutrition-plan.php" class="quick-action-btn">
                        <i class="bi bi-egg-fried"></i>
                        <span>Nutrition</span>
                    </a>
                    <a href="classes.php" class="quick-action-btn">
                        <i class="bi bi-calendar-plus-fill"></i>
                        <span>Book Class</span>
                    </a>
                    <a href="my-progress.php" class="quick-action-btn">
                        <i class="bi bi-graph-up-arrow"></i>
                        <span>My Progress</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- ============================================
             RECENT TRANSACTIONS
             ============================================ -->
        <div class="content-grid">
            <div class="panel full-width">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-receipt"></i> Recent Transactions
                    </h3>
                    <a href="payments.php" class="panel-link">
                        View All <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <?php if (empty($transactions)): ?>
                    <div class="empty-state-small">
                        <i class="bi bi-inbox"></i>
                        <p>No recent transactions found.</p>
                        <a href="payments.php">View Payment History</a>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="transaction-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Description</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $txn): 
                                    $status = strtolower($txn['status'] ?? 'pending');
                                ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($txn['reference_no']) ?></strong></td>
                                        <td><?= htmlspecialchars($txn['description']) ?></td>
                                        <td><?= date('M d, Y', strtotime($txn['created_at'])) ?></td>
                                        <td><strong>₱<?= number_format($txn['amount'], 2) ?></strong></td>
                                        <td>
                                            <span class="badge-<?= $status ?>">
                                                <?= htmlspecialchars($txn['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($status === 'pending' || $status === 'overdue'): ?>
                                                <a href="payments.php" class="btn-action" style="padding: 5px 12px; font-size: 11px;">
                                                    <i class="bi bi-credit-card"></i> Pay Now
                                                </a>
                                            <?php else: ?>
                                                <a href="payments.php" style="font-size: 12px; color: var(--umak-blue); text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                                    <i class="bi bi-receipt"></i> Receipt
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<!-- ============================================
     UNIFIED CHAT PANEL (LIVE DATA)
     ============================================ -->
<div class="chat-overlay" id="chatOverlay" onclick="closeChatPanel()"></div>

<div class="chat-panel" id="chatPanel">
    <div class="chat-panel-header">
        <h3>
            <i class="bi bi-chat-dots-fill"></i> Chat
        </h3>
        <div class="chat-search">
            <i class="bi bi-search" style="font-size: 14px;"></i>
            <input type="text" id="chatSearchInput" placeholder="Search conversations..." oninput="filterContacts(this.value)">
        </div>
        <button class="chat-close-btn" onclick="closeChatPanel()">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <div class="chat-panel-body">
        <div class="chat-sidebar">
            <div class="chat-filter-tabs">
                <div class="chat-filter-tab active" onclick="selectChatFilter(this, 'all')" data-filter="all">
                    <i class="bi bi-collection"></i> All
                </div>
                <div class="chat-filter-tab" onclick="selectChatFilter(this, 'friends')" data-filter="friends">
                    <i class="bi bi-people"></i> Members
                </div>
                <div class="chat-filter-tab" onclick="selectChatFilter(this, 'admin')" data-filter="admin">
                    <i class="bi bi-shield-lock"></i> Admin
                </div>
            </div>

            <div class="chat-divider"></div>

            <div class="chat-contacts-list" id="chatContactsList">
                <!-- Community Room always first -->
                <div class="chat-contact-item active" 
                     onclick="selectChatContact(this, 'community', 'Gym Community')"
                     data-type="community"
                     data-name="gym community">
                    <span class="contact-avatar">
                        <span style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #005b9f, #0084d1); color: white; display: inline-flex; align-items: center; justify-content: center; font-size: 14px;">
                            <i class="bi bi-people-fill"></i>
                        </span>
                    </span>
                    <span class="contact-name">Gym Community</span>
                </div>

                <!-- Live users will be injected here by JS -->
                <div id="liveContactsLoader" style="padding: 12px; text-align: center; color: #9ca3af; font-size: 12px;">
                    <i class="bi bi-arrow-clockwise" style="display:inline-block; animation: spin 1s linear infinite;"></i> Loading...
                </div>
            </div>
        </div>

        <div class="chat-main">
            <div class="chat-main-header">
                <h4 id="chatRoomTitle">Gym Community</h4>
                <p id="chatRoomSubtitle">Public chat room - All members</p>
            </div>

            <div class="chat-messages" id="chatMessages">
                <div style="text-align: center; padding: 30px; color: #9ca3af; font-size: 13px;">
                    <i class="bi bi-arrow-clockwise" style="font-size: 24px; display: block; margin-bottom: 8px; animation: spin 1s linear infinite;"></i>
                    Loading messages...
                </div>
            </div>

            <div class="chat-input-area">
                <button class="emoji-btn" type="button" title="Insert emoji" onclick="insertEmoji('😊')">
                    <i class="bi bi-emoji-smile" style="color: var(--text-muted); font-size: 20px;"></i>
                </button>
                <input type="text" id="chatMessageInput" placeholder="Type a message..."
                       maxlength="2000"
                       onkeypress="if(event.key==='Enter' && !event.shiftKey) { event.preventDefault(); sendChatMessage(); }">
                <button class="chat-send-btn" onclick="sendChatMessage()" id="chatSendBtn">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
        </div>
    </div>
</div>


<style>
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    .chat-contact-item .contact-avatar img {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
    }
    .chat-contact-item .contact-avatar .initials {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 800;
        color: white;
        background: linear-gradient(135deg, #005b9f, #0084d1);
    }
    .chat-contact-item .contact-avatar .initials.admin {
        background: linear-gradient(135deg, #ffce00, #ffb800);
        color: #333;
    }
    .chat-contact-item .online-dot {
        width: 8px;
        height: 8px;
        background: #4ade80;
        border-radius: 50%;
        display: inline-block;
        margin-left: 4px;
    }
    .chat-message-item .msg-avatar {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        object-fit: cover;
        margin-right: 6px;
    }
    .chat-message-item .msg-avatar-placeholder {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: linear-gradient(135deg, #005b9f, #0084d1);
        color: white;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 800;
        margin-right: 6px;
    }
    .chat-message-item .msg-avatar-placeholder.admin {
        background: linear-gradient(135deg, #ffce00, #ffb800);
        color: #333;
    }
    .chat-message-item.self .msg-avatar-placeholder {
        background: linear-gradient(135deg, #2e7d32, #4ade80);
    }
    .chat-message-sender {
        display: flex;
        align-items: center;
    }
    .chat-message-item.self .chat-message-sender {
        flex-direction: row-reverse;
    }
    .chat-date-separator {
        text-align: center;
        margin: 12px 0;
        position: relative;
    }
    .chat-date-separator span {
        background: #f0f2f6;
        padding: 3px 12px;
        border-radius: 12px;
        font-size: 11px;
        color: #6b7280;
        font-weight: 700;
        position: relative;
        z-index: 1;
    }
    .chat-date-separator::before {
        content: '';
        position: absolute;
        left: 0; right: 0;
        top: 50%;
        height: 1px;
        background: #e5e7eb;
        z-index: 0;
    }
    .live-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 9px;
    background: #dcfce7;
    color: #166534;
    border-radius: 12px;
    font-size: 9.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.live-pill-dot {
    width: 6px;
    height: 6px;
    background: #10b981;
    border-radius: 50%;
    animation: livePulse 2s infinite;
}

@keyframes livePulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.3); }
}

.stat-title-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.live-updated {
    font-size: 10.5px;
    color: var(--text-muted);
    margin-top: 10px;
    font-weight: 600;
    opacity: 0.75;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: color 0.2s;
}

.live-updated.updating {
    color: var(--umak-blue);
    opacity: 1;
}

.stat-value.flash-update {
    animation: flashValue 0.7s ease;
    border-radius: 6px;
}

@keyframes flashValue {
    0% { background: rgba(255,206,0,0.4); transform: scale(1); }
    50% { transform: scale(1.06); }
    100% { background: transparent; transform: scale(1); }
}

/* Status color states on the capacity card */
#liveCapacityCard.status-low { border-top-color: #10b981; }
#liveCapacityCard.status-medium { border-top-color: #f59e0b; }
#liveCapacityCard.status-high { border-top-color: #ef4444; }
    
</style>

<script>
    // ============================================
    // SIDEBAR TOGGLE (unchanged)
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
    // CHAT STATE
    // ============================================
    const chatState = {
        currentRoom: 'community',
        currentReceiverId: 0,
        currentReceiverName: 'Gym Community',
        lastMessageId: 0,
        pollTimer: null,
        contacts: [],
        filter: 'all',
    };

    // ============================================
    // OPEN / CLOSE CHAT
    // ============================================
    function openChatPanel() {
        document.getElementById('chatPanel').classList.add('open');
        document.getElementById('chatOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';

        // Load contacts and messages
        loadContacts();
        loadMessages(true); // reset

        // Start polling
        startPolling();

        setTimeout(() => {
            const input = document.getElementById('chatMessageInput');
            if (input) input.focus();
        }, 400);
    }

    function closeChatPanel() {
        document.getElementById('chatPanel').classList.remove('open');
        document.getElementById('chatOverlay').classList.remove('open');
        document.body.style.overflow = '';
        stopPolling();
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeChatPanel();
    });

    // ============================================
    // POLLING
    // ============================================
    function startPolling() {
        stopPolling();
        chatState.pollTimer = setInterval(() => {
            loadMessages(false);
        }, 3000);
    }

    function stopPolling() {
        if (chatState.pollTimer) {
            clearInterval(chatState.pollTimer);
            chatState.pollTimer = null;
        }
    }

    // ============================================
    // LOAD CONTACTS (real users from DB)
    // ============================================
    async function loadContacts() {
        try {
            const res = await fetch('chat_api.php?action=get_contacts');
            const data = await res.json();

            if (!data.success) {
                console.error('Failed to load contacts:', data.error);
                return;
            }

            chatState.contacts = data.contacts;
            renderContacts();
        } catch (err) {
            console.error('Load contacts error:', err);
        }
    }

    function renderContacts() {
        const list = document.getElementById('chatContactsList');
        const loader = document.getElementById('liveContactsLoader');

        // Remove old live contacts (keep community + loader)
        list.querySelectorAll('.live-contact').forEach(el => el.remove());

        if (chatState.contacts.length === 0) {
            if (loader) loader.innerHTML = '<div style="padding: 12px; color: #9ca3af; font-size: 12px;">No other members yet</div>';
            return;
        }

        if (loader) loader.remove();

        let html = '';
        chatState.contacts.forEach(c => {
            // Filter check
            if (chatState.filter === 'admin' && !c.is_admin) return;
            if (chatState.filter === 'friends' && c.is_admin) return;

            let avatarHtml = '';
            if (c.avatar) {
                avatarHtml = `<img src="${c.avatar}" alt="${escapeHtml(c.name)}">`;
            } else {
                avatarHtml = `<span class="initials ${c.is_admin ? 'admin' : ''}">${escapeHtml(c.initials)}</span>`;
            }

            const onlineDot = c.is_online ? '<span class="online-dot"></span>' : '';
            const unreadBadge = c.unread_count > 0 
                ? `<span class="unread-tag">${c.unread_count}</span>` 
                : '';
            const adminBadge = c.is_admin 
                ? '<i class="bi bi-patch-check-fill" style="color: var(--umak-yellow); font-size: 11px; margin-left: 4px;"></i>' 
                : '';

            html += `
                <div class="chat-contact-item live-contact" 
                     onclick="selectChatContact(this, 'private', '${escapeHtml(c.name)}', ${c.id})"
                     data-type="private"
                     data-user-id="${c.id}"
                     data-name="${escapeHtml(c.name.toLowerCase())}"
                     data-is-admin="${c.is_admin ? '1' : '0'}">
                    <span class="contact-avatar">${avatarHtml}</span>
                    <span class="contact-name">${escapeHtml(c.name)}${adminBadge}</span>
                    ${onlineDot}
                    ${unreadBadge}
                </div>
            `;
        });

        list.insertAdjacentHTML('beforeend', html);
        applyFilter();
    }

    // ============================================
    // FILTERS
    // ============================================
    function selectChatFilter(el, filter) {
        document.querySelectorAll('.chat-filter-tab').forEach(t => t.classList.remove('active'));
        el.classList.add('active');
        chatState.filter = filter;
        applyFilter();
    }

    function applyFilter() {
        document.querySelectorAll('.live-contact').forEach(el => {
            const isAdmin = el.dataset.isAdmin === '1';
            let show = true;
            if (chatState.filter === 'admin' && !isAdmin) show = false;
            if (chatState.filter === 'friends' && isAdmin) show = false;
            el.style.display = show ? '' : 'none';
        });
    }

    function filterContacts(query) {
        query = query.toLowerCase().trim();
        document.querySelectorAll('.chat-contact-item').forEach(el => {
            const name = el.dataset.name || '';
            el.style.display = (!query || name.includes(query)) ? '' : 'none';
        });
    }

    // ============================================
    // SELECT CONTACT / ROOM
    // ============================================
    function selectChatContact(el, type, name, userId = 0) {
        // Update active state
        document.querySelectorAll('.chat-contact-item').forEach(c => c.classList.remove('active'));
        el.classList.add('active');

        // Update state
        chatState.currentRoom = (type === 'community') ? 'community' : 'private';
        chatState.currentReceiverId = userId || 0;
        chatState.currentReceiverName = name || 'Chat';
        chatState.lastMessageId = 0;

        // Update header
        document.getElementById('chatRoomTitle').textContent = name;
        document.getElementById('chatRoomSubtitle').textContent = 
            (type === 'community') 
                ? 'Public chat room - All members'
                : 'Private conversation';

        // Load messages
        loadMessages(true);

        // Focus input
        setTimeout(() => {
            document.getElementById('chatMessageInput').focus();
        }, 200);
    }

    // ============================================
    // LOAD MESSAGES
    // ============================================
    async function loadMessages(reset = false) {
        const container = document.getElementById('chatMessages');

        if (reset) {
            chatState.lastMessageId = 0;
            container.innerHTML = `
                <div style="text-align: center; padding: 30px; color: #9ca3af; font-size: 13px;">
                    <i class="bi bi-arrow-clockwise" style="font-size: 24px; display: block; margin-bottom: 8px; animation: spin 1s linear infinite;"></i>
                    Loading messages...
                </div>
            `;
        }

        try {
            const params = new URLSearchParams({
                action: 'get_messages',
                room: chatState.currentRoom,
                last_id: chatState.lastMessageId
            });
            if (chatState.currentRoom === 'private') {
                params.set('receiver_id', chatState.currentReceiverId);
            }

            const res = await fetch('chat_api.php?' + params.toString());
            const data = await res.json();

            if (!data.success) {
                console.error('Failed to load messages:', data.error);
                return;
            }

            if (reset) container.innerHTML = '';

            if (data.messages.length === 0 && reset) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 40px 20px; color: #9ca3af; font-size: 13px;">
                        <i class="bi bi-chat-dots" style="font-size: 40px; opacity: 0.4; display: block; margin-bottom: 10px;"></i>
                        <p style="margin: 0; font-weight: 600;">No messages yet</p>
                        <p style="margin: 6px 0 0; font-size: 12px;">Be the first to say something!</p>
                    </div>
                `;
                return;
            }

            let lastDate = '';
            data.messages.forEach(msg => {
                // Date separator
                if (msg.date !== lastDate) {
                    lastDate = msg.date;
                    const sep = document.createElement('div');
                    sep.className = 'chat-date-separator';
                    sep.innerHTML = `<span>${escapeHtml(msg.date)}</span>`;
                    container.appendChild(sep);
                }

                // Skip if already rendered
                if (chatState.lastMessageId >= msg.id) return;

                container.appendChild(buildMessageElement(msg));
                chatState.lastMessageId = Math.max(chatState.lastMessageId, msg.id);
            });

            container.scrollTop = container.scrollHeight;
        } catch (err) {
            console.error('Load messages error:', err);
        }
    }

    function buildMessageElement(msg) {
        const el = document.createElement('div');
        el.className = 'chat-message-item' + (msg.is_self ? ' self' : '');
        el.dataset.msgId = msg.id;

        let avatarHtml = '';
        if (msg.is_self) {
            avatarHtml = '<i class="bi bi-person-fill" style="font-size: 14px;"></i>';
        } else if (msg.sender_avatar) {
            avatarHtml = `<img src="${msg.sender_avatar}" class="msg-avatar" alt="">`;
        } else {
            avatarHtml = `<span class="msg-avatar-placeholder ${msg.is_admin ? 'admin' : ''}">${escapeHtml(msg.sender_initials)}</span>`;
        }

        const adminIcon = msg.is_admin && !msg.is_self 
            ? '<i class="bi bi-patch-check-fill" style="color: var(--umak-yellow);"></i>' 
            : '';

        el.innerHTML = `
            <div class="chat-message-sender">
                ${avatarHtml}
                ${adminIcon}
                ${escapeHtml(msg.is_self ? 'You' : msg.sender)}
            </div>
            <div class="chat-message-text">${escapeHtml(msg.message)}</div>
            <div class="chat-message-time">${escapeHtml(msg.time)}</div>
        `;

        return el;
    }

    // ============================================
    // SEND MESSAGE
    // ============================================
    async function sendChatMessage() {
        const input = document.getElementById('chatMessageInput');
        const btn = document.getElementById('chatSendBtn');
        const message = input.value.trim();

        if (!message) return;

        // Disable button
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';

        try {
            const res = await fetch('chat_api.php?action=send_message', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    message: message,
                    room: chatState.currentRoom,
                    receiver_id: chatState.currentReceiverId
                })
            });

            const data = await res.json();

            if (data.success) {
                input.value = '';
                // Immediately refresh to show new message
                await loadMessages(false);
            } else {
                alert('Failed to send: ' + (data.error || 'Unknown error'));
            }
        } catch (err) {
            console.error('Send error:', err);
            alert('Network error — please try again.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send-fill"></i>';
            input.focus();
        }
    }

    function insertEmoji(emoji) {
        const input = document.getElementById('chatMessageInput');
        input.value += emoji;
        input.focus();
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ============================================
    // WEEKLY ACTIVITY CHART (unchanged)
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('weeklyChart');
        if (!ctx) return;

        const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 260);
        gradient.addColorStop(0, 'rgba(0, 91, 159, 0.3)');
        gradient.addColorStop(1, 'rgba(0, 91, 159, 0.02)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Gym Visits',
                    data: <?= json_encode($weeklyData) ?>,
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
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.05)', drawBorder: false },
                        ticks: { font: { size: 11, weight: '600' }, color: '#9ca3af', stepSize: 1 }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11, weight: '600' }, color: '#9ca3af' }
                    }
                }
            }
        });
    });
     (function() {
        const state = {
            lastValue: <?= (int)$currentOccupancy ?>,
            pollTimer: null,
            broadcastChannel: null,
            refreshInProgress: false,
        };

        // --------------------------------------------
        // REFRESH FROM SERVER
        // --------------------------------------------
        async function refreshCapacity() {
            if (state.refreshInProgress) return;
            state.refreshInProgress = true;

            const updatedEl = document.getElementById('liveUpdated');
            if (updatedEl) updatedEl.classList.add('updating');

            try {
                // Add cache-buster to prevent stale responses
                const res = await fetch('capacity_api.php?t=' + Date.now(), {
                    method: 'GET',
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                if (!res.ok) {
                    // If 401, user session expired — silently ignore
                    if (res.status === 401) return;
                    throw new Error('HTTP ' + res.status);
                }

                const data = await res.json();
                if (data.success && data.capacity) {
                    applyCapacity(data.capacity);
                }
            } catch (err) {
                console.warn('Capacity refresh failed:', err.message);
            } finally {
                state.refreshInProgress = false;
                if (updatedEl) updatedEl.classList.remove('updating');
            }
        }

        // --------------------------------------------
        // APPLY CAPACITY TO UI
        // --------------------------------------------
        function applyCapacity(cap) {
            const newValue = parseInt(cap.current, 10);
            const cardEl = document.getElementById('liveCapacityCard');
            const valueEl = cardEl ? cardEl.querySelector('.stat-value') : null;

            // Flash animation if value changed
            if (valueEl && state.lastValue !== newValue) {
                valueEl.classList.add('flash-update');
                setTimeout(() => valueEl.classList.remove('flash-update'), 800);
            }

            // Update text
            const occEl = document.getElementById('liveOccupancy');
            const maxEl = document.getElementById('liveMax');
            const pctEl = document.getElementById('livePercentText');
            const availEl = document.getElementById('liveAvailableText');
            const barEl = document.getElementById('liveProgressBar');
            const updEl = document.getElementById('liveUpdated');

            if (occEl) occEl.textContent = cap.current;
            if (maxEl) maxEl.textContent = cap.max;
            if (pctEl) pctEl.textContent = cap.percentage + '% Full';
            if (availEl) {
                availEl.textContent = cap.available + ' slot' + (cap.available !== 1 ? 's' : '') + ' available';
            }
            if (updEl) {
                const span = updEl.querySelector('span');
                if (span) span.textContent = 'Updated: ' + cap.timestamp;
            }

            // Progress bar
            if (barEl) {
                barEl.style.setProperty('--progress-width', cap.percentage + '%');
                barEl.classList.remove('warning', 'danger');
                if (cap.percentage > 80) barEl.classList.add('danger');
                else if (cap.percentage > 50) barEl.classList.add('warning');
            }

            // Card status border
            if (cardEl) {
                cardEl.classList.remove('status-low', 'status-medium', 'status-high');
                cardEl.classList.add('status-' + cap.status);
            }

            state.lastValue = newValue;
        }

        // --------------------------------------------
        // BROADCAST CHANNEL (same-device instant sync)
        // --------------------------------------------
        function initBroadcastChannel() {
            if (typeof BroadcastChannel === 'undefined') return;
            try {
                state.broadcastChannel = new BroadcastChannel('fitsense_capacity');
                state.broadcastChannel.addEventListener('message', function(e) {
                    if (e.data && e.data.type === 'capacity_updated') {
                        if (e.data.capacity) {
                            applyCapacity(e.data.capacity);
                        } else {
                            refreshCapacity();
                        }
                    }
                });
            } catch (err) {
                console.warn('BroadcastChannel not supported:', err);
            }
        }

        // --------------------------------------------
        // LOCALSTORAGE FALLBACK (older browsers)
        // --------------------------------------------
        function initLocalStorageListener() {
            window.addEventListener('storage', function(e) {
                if (e.key !== 'fitsense_capacity_update' || !e.newValue) return;
                try {
                    const data = JSON.parse(e.newValue);
                    if (!data.timestamp || (Date.now() - data.timestamp) > 5000) return;
                    if (data.capacity) {
                        applyCapacity(data.capacity);
                    } else {
                        refreshCapacity();
                    }
                } catch (err) { /* ignore */ }
            });
        }

        // --------------------------------------------
        // POLLING (cross-device fallback)
        // --------------------------------------------
        function startPolling() {
            if (state.pollTimer) clearInterval(state.pollTimer);

            // Initial fetch (in case server value was stale)
            refreshCapacity();

            // Poll every 15 seconds
            state.pollTimer = setInterval(refreshCapacity, 15000);

            // Refresh when tab becomes visible again
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) refreshCapacity();
            });

            // Refresh when window gains focus
            window.addEventListener('focus', refreshCapacity);

            // Refresh when returning to page (back button)
            window.addEventListener('pageshow', function(e) {
                if (e.persisted) refreshCapacity();
            });
        }

        // --------------------------------------------
        // BOOT
        // --------------------------------------------
        document.addEventListener('DOMContentLoaded', function() {
            initBroadcastChannel();
            initLocalStorageListener();
            startPolling();
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (state.pollTimer) clearInterval(state.pollTimer);
            if (state.broadcastChannel) {
                try { state.broadcastChannel.close(); } catch (e) {}
            }
        });
    })();
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>