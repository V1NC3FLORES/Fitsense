<?php
session_start();
include 'conn.php';
require_once __DIR__ . '/config/session_security.php';

// ============================================
// AUTHENTICATION CHECK
// ============================================
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userRole = $_SESSION['role'] ?? $_SESSION['role_id'] ?? null;
if ($userRole !== 'user' && $userRole !== 2 && $userRole !== '2') {
    if ($userRole !== 'admin' && $userRole !== 1 && $userRole !== '1') {
        header("Location: login.php");
        exit();
    }
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

// ============================================
// HANDLE ACTIONS
// ============================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_message'] = 'This form has expired or is invalid. Please refresh the page and try again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: notifications.php');
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'mark_read' && isset($_POST['notification_id'])) {
        $notifId = (int)$_POST['notification_id'];
        safeQuery($conn,
            "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?",
            "ii", [$notifId, $userId]
        );
        $message = 'Notification marked as read.';
        $messageType = 'success';
    } elseif ($action === 'mark_all_read') {
        safeQuery($conn,
            "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
            "i", [$userId]
        );
        $message = 'All notifications marked as read!';
        $messageType = 'success';
    } elseif ($action === 'delete' && isset($_POST['notification_id'])) {
        $notifId = (int)$_POST['notification_id'];
        safeQuery($conn,
            "DELETE FROM notifications WHERE id = ? AND user_id = ?",
            "ii", [$notifId, $userId]
        );
        $message = 'Notification deleted.';
        $messageType = 'success';
    } elseif ($action === 'delete_all_read') {
        safeQuery($conn,
            "DELETE FROM notifications WHERE user_id = ? AND is_read = 1",
            "i", [$userId]
        );
        $message = 'All read notifications deleted.';
        $messageType = 'success';
    }
}

// ============================================
// FETCH USER DATA
// ============================================
$userData = null;
$stmtUser = $conn->prepare("SELECT id, fullname, username, email, profile_image FROM users WHERE id = ? LIMIT 1");
if ($stmtUser) {
    $stmtUser->bind_param("i", $userId);
    $stmtUser->execute();
    $userData = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();
}

$displayName = $userData['fullname'] ?? $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'User';
$firstName = explode(' ', trim($displayName))[0];
$userInitial = strtoupper(substr($displayName, 0, 1));

// ============================================
// FILTERS
// ============================================
$filter = $_GET['filter'] ?? 'all'; // all | unread | read

// ============================================
// FETCH NOTIFICATIONS
// ============================================
$notifications = [];
$unreadNotifCount = 0;
$totalCount = 0;
$readCount = 0;

if (tableExists($conn, 'notifications')) {
    // Count stats
    $result = safeQuery($conn,
        "SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread,
            SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read_count
         FROM notifications WHERE user_id = ?",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $totalCount = (int)$row['total'];
        $unreadNotifCount = (int)$row['unread'];
        $readCount = (int)$row['read_count'];
    }

    // Build query
    $whereClause = "";
    if ($filter === 'unread') $whereClause = " AND is_read = 0";
    elseif ($filter === 'read') $whereClause = " AND is_read = 1";

    $result = safeQuery($conn,
        "SELECT id, title, message, type, is_read, link, created_at 
         FROM notifications 
         WHERE user_id = ?" . $whereClause . "
         ORDER BY is_read ASC, created_at DESC
         LIMIT 100",
        "i", [$userId]
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }
    }
}

// ============================================
// FALLBACK SAMPLE DATA (if no notifications)
// ============================================
if (empty($notifications) && $totalCount === 0 && $filter === 'all') {
    $now = time();
    $notifications = [
        [
            'id' => 1,
            'title' => 'Welcome to FitSense! 🎉',
            'message' => 'Your account is now active. Start your fitness journey today! Explore workout plans, book classes, and track your progress.',
            'type' => 'success',
            'is_read' => 0,
            'link' => 'workout-plan.php',
            'created_at' => date('Y-m-d H:i:s', $now - 300),
        ],
        [
            'id' => 2,
            'title' => 'Membership Renewal Reminder',
            'message' => 'Your Premium membership will expire in 7 days. Renew now to keep uninterrupted access to all gym facilities.',
            'type' => 'warning',
            'is_read' => 0,
            'link' => 'my-membership.php',
            'created_at' => date('Y-m-d H:i:s', $now - 3600),
        ],
        [
            'id' => 3,
            'title' => 'New Class Available: HIIT Training',
            'message' => 'A new HIIT class has been added on Saturday 7:00 PM with Coach Maria. Limited slots available!',
            'type' => 'info',
            'is_read' => 0,
            'link' => 'classes.php',
            'created_at' => date('Y-m-d H:i:s', $now - 7200),
        ],
        [
            'id' => 4,
            'title' => 'Payment Received ✓',
            'message' => 'We received your payment of ₱1,000.00. Your transaction has been processed successfully.',
            'type' => 'success',
            'is_read' => 1,
            'link' => 'payments.php',
            'created_at' => date('Y-m-d H:i:s', $now - 86400),
        ],
        [
            'id' => 5,
            'title' => 'Workout Completed! 💪',
            'message' => 'Great job completing your Monday workout! You did 5 of 5 exercises. Keep up the momentum!',
            'type' => 'achievement',
            'is_read' => 1,
            'link' => 'workout-plan.php',
            'created_at' => date('Y-m-d H:i:s', $now - 172800),
        ],
        [
            'id' => 6,
            'title' => 'New Message from Coach John',
            'message' => '"That\'s great to hear! Keep up with the mobility work." — Coach John',
            'type' => 'message',
            'is_read' => 1,
            'link' => 'my-trainer.php',
            'created_at' => date('Y-m-d H:i:s', $now - 259200),
        ],
    ];
    $totalCount = count($notifications);
    $unreadNotifCount = 3;
    $readCount = 3;
}

// ============================================
// HELPER FUNCTIONS
// ============================================
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
        'success' => ['bg' => 'linear-gradient(135deg, #2e7d32, #4ade80)', 'light' => 'rgba(46,125,50,0.12)', 'text' => '#065f46'],
        'warning' => ['bg' => 'linear-gradient(135deg, #f59e0b, #fbbf24)', 'light' => 'rgba(245,158,11,0.12)', 'text' => '#92400e'],
        'info' => ['bg' => 'linear-gradient(135deg, #005b9f, #0084d1)', 'light' => 'rgba(0,91,159,0.12)', 'text' => '#1e40af'],
        'error' => ['bg' => 'linear-gradient(135deg, #d32f2f, #f87171)', 'light' => 'rgba(211,47,47,0.12)', 'text' => '#991b1b'],
        'achievement' => ['bg' => 'linear-gradient(135deg, #ffce00, #ffb800)', 'light' => 'rgba(255,206,0,0.18)', 'text' => '#92400e'],
        'message' => ['bg' => 'linear-gradient(135deg, #8b5cf6, #a78bfa)', 'light' => 'rgba(139,92,246,0.12)', 'text' => '#5b21b6'],
        'payment' => ['bg' => 'linear-gradient(135deg, #635bff, #818cf8)', 'light' => 'rgba(99,91,255,0.12)', 'text' => '#3730a3'],
        'class' => ['bg' => 'linear-gradient(135deg, #ec4899, #f472b6)', 'light' => 'rgba(236,72,153,0.12)', 'text' => '#9d174d'],
        'system' => ['bg' => 'linear-gradient(135deg, #6b7280, #9ca3af)', 'light' => 'rgba(107,114,128,0.12)', 'text' => '#374151'],
    ];
    return $colors[$type] ?? $colors['info'];
}

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) != 1 ? 's' : '') . ' ago';
    if ($diff < 2592000) return floor($diff / 604800) . ' week' . (floor($diff / 604800) != 1 ? 's' : '') . ' ago';
    return date('M j, Y', $timestamp);
}

function groupByDate($notifications) {
    $groups = [];
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $weekAgo = date('Y-m-d', strtotime('-7 days'));

    foreach ($notifications as $notif) {
        $date = date('Y-m-d', strtotime($notif['created_at']));

        if ($date === $today) $group = 'Today';
        elseif ($date === $yesterday) $group = 'Yesterday';
        elseif ($date >= $weekAgo) $group = 'This Week';
        else $group = 'Earlier';

        if (!isset($groups[$group])) $groups[$group] = [];
        $groups[$group][] = $notif;
    }
    return $groups;
}

$groupedNotifs = groupByDate($notifications);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - UMak Gym: Fitsense</title>
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
           SIDEBAR NAVIGATION
           ============================================ */
        .sidebar {
            position: fixed; left: 0; top: 0;
            width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, #005b9f 0%, #00467a 100%);
            color: white; overflow-y: auto; z-index: 1000;
            transition: width 0.3s ease;
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
        .main-wrapper { margin-left: var(--sidebar-width); min-height: 100vh; transition: margin-left 0.3s ease; padding-bottom: 40px; }

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
        }

        .avatar:hover { transform: scale(1.05); }

        /* ============================================
           PAGE CONTENT
           ============================================ */
        .container { max-width: 1000px; margin: 30px auto; padding: 0 25px; }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .page-title {
            font-size: 26px; font-weight: 800; color: var(--umak-blue);
            margin: 0 0 6px; display: flex; align-items: center; gap: 12px;
            letter-spacing: 0.5px;
        }

        .page-title i {
            font-size: 28px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle { color: var(--text-muted); font-size: 14px; margin: 0; }

        .page-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

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

        /* ============================================
           STATS CARDS
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
            cursor: pointer;
            text-decoration: none;
            color: inherit;
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
        .stat-box-icon.red { background: linear-gradient(135deg, rgba(211,47,47,0.12), rgba(248,113,113,0.18)); color: var(--umak-red); }
        .stat-box-icon.green { background: linear-gradient(135deg, rgba(46,125,50,0.12), rgba(74,222,128,0.18)); color: var(--umak-green); }

        .stat-box-info { flex: 1; min-width: 0; }
        .stat-box-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .stat-box-value { font-size: 24px; font-weight: 800; color: #111827; line-height: 1.1; letter-spacing: -0.5px; }
        .stat-box-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }

        /* ============================================
           FILTER TABS
           ============================================ */
        .filter-tabs {
            display: flex;
            gap: 8px;
            background: white;
            padding: 6px;
            border-radius: 14px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
            overflow-x: auto;
        }

        .filter-tabs::-webkit-scrollbar { height: 6px; }
        .filter-tabs::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }

        .filter-tab {
            flex: 1;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap;
        }

        .filter-tab:hover {
            background: #f3f4f6;
            color: var(--umak-blue);
        }

        .filter-tab.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 12px rgba(0,91,159,0.3);
        }

        .filter-tab .count-pill {
            background: rgba(255,255,255,0.25);
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 800;
            min-width: 20px;
            text-align: center;
        }

        .filter-tab:not(.active) .count-pill {
            background: #e5e7eb;
            color: #374151;
        }

        /* ============================================
           NOTIFICATIONS LIST
           ============================================ */
        .notif-group {
            margin-bottom: 30px;
        }

        .notif-group-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            padding: 0 6px;
        }

        .notif-group-label {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notif-group-label i {
            font-size: 14px;
        }

        .notif-group-count {
            background: #f3f4f6;
            color: var(--text-muted);
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 800;
        }

        .notif-group-line {
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, var(--border), transparent);
        }

        .notif-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .notif-item {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            display: flex;
            gap: 16px;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .notif-item:hover {
            transform: translateX(4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border-color: rgba(0,91,159,0.2);
        }

        .notif-item.unread {
            background: linear-gradient(90deg, rgba(0,91,159,0.03), #ffffff 30%);
            border-left: 4px solid var(--umak-blue);
        }

        .notif-item.unread::before {
            content: '';
            position: absolute;
            top: 20px;
            right: 20px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--umak-blue);
            box-shadow: 0 0 0 4px rgba(0,91,159,0.15);
            animation: notifPulse 2s infinite;
        }

        @keyframes notifPulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 4px rgba(0,91,159,0.15); }
            50% { transform: scale(1.1); box-shadow: 0 0 0 8px rgba(0,91,159,0.08); }
        }

        .notif-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            flex-shrink: 0;
        }

        .notif-content {
            flex: 1;
            min-width: 0;
            padding-right: 20px;
        }

        .notif-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }

        .notif-title {
            font-size: 15px;
            font-weight: 800;
            color: #111827;
            margin: 0;
            letter-spacing: -0.2px;
            line-height: 1.3;
        }

        .notif-item.unread .notif-title {
            color: var(--umak-blue);
        }

        .notif-time {
            font-size: 11.5px;
            color: var(--text-muted);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .notif-message {
            font-size: 13.5px;
            line-height: 1.6;
            color: #4b5563;
            margin: 0 0 12px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .notif-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .notif-action-btn {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
            text-decoration: none;
            font-family: inherit;
            letter-spacing: 0.2px;
        }

        .notif-action-btn.view {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 2px 8px rgba(0,91,159,0.25);
        }

        .notif-action-btn.view:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,91,159,0.35);
        }

        .notif-action-btn.secondary {
            background: #f3f4f6;
            color: #4b5563;
        }

        .notif-action-btn.secondary:hover {
            background: #e5e7eb;
        }

        .notif-action-btn.danger {
            background: transparent;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .notif-action-btn.danger:hover {
            background: #fee2e2;
            border-color: #ef4444;
        }

        /* ============================================
           EMPTY STATE
           ============================================ */
        .empty-state {
            text-align: center;
            padding: 80px 30px;
            background: var(--card-bg);
            border-radius: 20px;
            border: 2px dashed var(--border);
        }

        .empty-state-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 24px;
            background: rgba(0,91,159,0.08);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 44px;
            color: var(--umak-blue);
        }

        .empty-state h3 {
            margin: 0 0 10px;
            color: var(--umak-blue);
            font-size: 22px;
            font-weight: 800;
        }

        .empty-state p {
            color: var(--text-muted);
            font-size: 14.5px;
            margin: 0 auto 24px;
            max-width: 400px;
            line-height: 1.6;
        }

        /* ============================================
           BUTTONS
           ============================================ */
        .btn {
            padding: 11px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
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

        .btn-danger-outline {
            background: white;
            color: #b91c1c;
            border: 2px solid #fecaca;
        }

        .btn-danger-outline:hover {
            background: #fee2e2;
            border-color: #ef4444;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .btn-chat-open span.label { display: none; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 22px; }
            .page-header { flex-direction: column; align-items: stretch; }
            .page-actions { width: 100%; }
            .page-actions .btn { flex: 1; justify-content: center; }
            .notif-item { padding: 16px; gap: 12px; }
            .notif-icon { width: 42px; height: 42px; font-size: 18px; border-radius: 12px; }
            .notif-content { padding-right: 0; }
            .notif-title { font-size: 14px; }
            .notif-message { font-size: 13px; }
            .notif-item.unread::before { top: 16px; right: 16px; width: 8px; height: 8px; }
        }

        @media (max-width: 576px) {
            .stats-row { grid-template-columns: 1fr; }
            .notif-header-row { flex-direction: column; align-items: flex-start; }
            .notif-time { font-size: 11px; }
            .filter-tabs { padding: 4px; }
            .filter-tab { padding: 8px 12px; font-size: 12px; }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css"><link rel="stylesheet" href="/PBL/css/user-content-icons.css"></head>
<body>

<!-- ============================================
     SIDEBAR NAVIGATION
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
        <a href="user-dashboard.php" class="nav-item">
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
        <a href="notifications.php" class="nav-item active">
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
                <h2>Notifications</h2>
                <p class="subtitle">Stay updated with your fitness journey</p>
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
                <div class="avatar"><?= htmlspecialchars($userInitial) ?></div>
            </button>
        </div>
    </header>

    <!-- PAGE CONTENT -->
    <main class="container">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="bi bi-bell-fill"></i>
                    Notifications
                </h1>
                <p class="page-subtitle">
                    <?php if ($unreadNotifCount > 0): ?>
                        You have <strong style="color: var(--umak-blue);"><?= $unreadNotifCount ?></strong> unread notification<?= $unreadNotifCount != 1 ? 's' : '' ?>.
                    <?php else: ?>
                        You're all caught up! 🎉
                    <?php endif; ?>
                </p>
            </div>

            <div class="page-actions">
                <?php if ($unreadNotifCount > 0): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="mark_all_read">
                        <?php echo app_csrf_field(); ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-all"></i> Mark All Read
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($readCount > 0): ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete all read notifications? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete_all_read">
                        <?php echo app_csrf_field(); ?>
                        <button type="submit" class="btn btn-danger-outline">
                            <i class="bi bi-trash3-fill"></i> Clear Read
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="bi bi-check-circle-fill" style="font-size: 20px;"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- ============================================
             STATS CARDS (also act as filters)
             ============================================ -->
        <div class="stats-row">
            <a href="?filter=all" class="stat-box <?= $filter === 'all' ? 'active' : '' ?>">
                <div class="stat-box-icon blue">
                    <i class="bi bi-collection-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">All</div>
                    <div class="stat-box-value"><?= $totalCount ?></div>
                    <div class="stat-box-sub">notifications</div>
                </div>
            </a>

            <a href="?filter=unread" class="stat-box <?= $filter === 'unread' ? 'active' : '' ?>">
                <div class="stat-box-icon red">
                    <i class="bi bi-envelope-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Unread</div>
                    <div class="stat-box-value"><?= $unreadNotifCount ?></div>
                    <div class="stat-box-sub">need attention</div>
                </div>
            </a>

            <a href="?filter=read" class="stat-box <?= $filter === 'read' ? 'active' : '' ?>">
                <div class="stat-box-icon green">
                    <i class="bi bi-envelope-open-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Read</div>
                    <div class="stat-box-value"><?= $readCount ?></div>
                    <div class="stat-box-sub">already seen</div>
                </div>
            </a>
        </div>

        <!-- ============================================
             FILTER TABS
             ============================================ -->
        <div class="filter-tabs">
            <a href="?filter=all" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
                <i class="bi bi-collection-fill"></i> All
                <span class="count-pill"><?= $totalCount ?></span>
            </a>
            <a href="?filter=unread" class="filter-tab <?= $filter === 'unread' ? 'active' : '' ?>">
                <i class="bi bi-envelope-fill"></i> Unread
                <span class="count-pill"><?= $unreadNotifCount ?></span>
            </a>
            <a href="?filter=read" class="filter-tab <?= $filter === 'read' ? 'active' : '' ?>">
                <i class="bi bi-envelope-open-fill"></i> Read
                <span class="count-pill"><?= $readCount ?></span>
            </a>
        </div>

        <!-- ============================================
             NOTIFICATIONS LIST
             ============================================ -->
        <?php if (empty($notifications)): ?>
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="bi bi-bell-slash"></i>
                </div>
                <h3>
                    <?php if ($filter === 'unread'): ?>
                        No Unread Notifications
                    <?php elseif ($filter === 'read'): ?>
                        No Read Notifications
                    <?php else: ?>
                        No Notifications Yet
                    <?php endif; ?>
                </h3>
                <p>
                    <?php if ($filter === 'unread'): ?>
                        You've read all your notifications. Great job staying on top of things!
                    <?php elseif ($filter === 'read'): ?>
                        You haven't read any notifications yet.
                    <?php else: ?>
                        When you receive updates about your membership, payments, classes, and fitness journey, they'll appear here.
                    <?php endif; ?>
                </p>
                <?php if ($filter !== 'all'): ?>
                    <a href="?filter=all" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i> View All Notifications
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Grouped Notifications -->
            <?php foreach ($groupedNotifs as $group => $groupNotifs): ?>
                <div class="notif-group">
                    <div class="notif-group-header">
                        <div class="notif-group-label">
                            <i class="bi <?= 
                                $group === 'Today' ? 'bi-sun-fill' : 
                                ($group === 'Yesterday' ? 'bi-moon-fill' : 
                                ($group === 'This Week' ? 'bi-calendar-week-fill' : 'bi-archive-fill')) 
                            ?>"></i>
                            <?= htmlspecialchars($group) ?>
                        </div>
                        <span class="notif-group-count"><?= count($groupNotifs) ?></span>
                        <div class="notif-group-line"></div>
                    </div>

                    <div class="notif-list">
                        <?php foreach ($groupNotifs as $notif):
                            $color = getNotifColor($notif['type']);
                            $icon = getNotifIcon($notif['type']);
                            $isUnread = !$notif['is_read'];
                        ?>
                            <div class="notif-item <?= $isUnread ? 'unread' : '' ?>">
                                <div class="notif-icon" style="background: <?= $color['bg'] ?>;">
                                    <i class="bi <?= $icon ?>"></i>
                                </div>

                                <div class="notif-content">
                                    <div class="notif-header-row">
                                        <h3 class="notif-title">
                                            <?= htmlspecialchars($notif['title']) ?>
                                        </h3>
                                        <span class="notif-time">
                                            <i class="bi bi-clock"></i>
                                            <?= timeAgo($notif['created_at']) ?>
                                        </span>
                                    </div>

                                    <p class="notif-message">
                                        <?= htmlspecialchars($notif['message']) ?>
                                    </p>

                                    <div class="notif-actions">
                                        <?php if (!empty($notif['link'])): ?>
                                            <a href="<?= htmlspecialchars($notif['link']) ?>" class="notif-action-btn view">
                                                <i class="bi bi-arrow-right-circle-fill"></i> View
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($isUnread): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="mark_read">
                                                <?php echo app_csrf_field(); ?>
                                                <input type="hidden" name="notification_id" value="<?= (int)$notif['id'] ?>">
                                                <button type="submit" class="notif-action-btn secondary">
                                                    <i class="bi bi-check2-circle"></i> Mark Read
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this notification?');">
                                            <input type="hidden" name="action" value="delete">
                                            <?php echo app_csrf_field(); ?>
                                            <input type="hidden" name="notification_id" value="<?= (int)$notif['id'] ?>">
                                            <button type="submit" class="notif-action-btn danger">
                                                <i class="bi bi-trash3"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

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
    // CLICK ITEM TO MARK AS READ
    // ============================================
    document.querySelectorAll('.notif-item').forEach(function(item) {
        item.addEventListener('click', function(e) {
            // Don't trigger if clicking a button or link
            if (e.target.closest('button') || e.target.closest('a')) return;

            // If unread, mark as read
            if (this.classList.contains('unread')) {
                const form = this.querySelector('form input[name="action"][value="mark_read"]');
                if (form) {
                    form.closest('form').submit();
                }
            }
        });
    });

    // ============================================
    // AUTO-DISMISS ALERTS
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert-success');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() { alert.style.display = 'none'; }, 500);
            }, 3000);
        });
    });
</script>
<?php include 'chat_widget.php'; ?>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>