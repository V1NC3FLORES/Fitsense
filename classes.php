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
$isAdmin = in_array($userRole, ['admin', 'super_admin', 1, '1'], true);
if (!$isAdmin && $userRole !== 'user' && $userRole !== 2 && $userRole !== '2') {
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
// HANDLE BOOKING ACTIONS
// ============================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $message = 'This form has expired or is invalid. Please refresh the page and try again.';
        $messageType = 'error';
    } else {
    $classId = (int)($_POST['class_id'] ?? 0);

    if ($classId > 0) {
        // Verify class exists
        $classRow = null;
        $r = safeQuery($conn, "SELECT * FROM classes WHERE id = ? AND is_active = 1 LIMIT 1", "i", [$classId]);
        if ($r && $row = $r->fetch_assoc()) $classRow = $row;

        if (!$classRow) {
            $message = 'This class no longer exists.';
            $messageType = 'error';
        } elseif ($_POST['action'] === 'book_class') {
            // Check current active booking
            $r = safeQuery($conn,
                "SELECT id, status FROM class_bookings 
                 WHERE user_id = ? AND class_id = ? AND status IN ('Confirmed','Waitlist')
                 LIMIT 1",
                "ii", [$userId, $classId]
            );
            if ($r && $r->num_rows > 0) {
                $existing = $r->fetch_assoc();
                $message = 'You already have an active ' . strtolower($existing['status']) . ' booking.';
                $messageType = 'error';
            } else {
                // Count confirmed bookings
                $r = safeQuery($conn,
                    "SELECT COUNT(*) AS booked FROM class_bookings 
                     WHERE class_id = ? AND status = 'Confirmed'",
                    "i", [$classId]
                );
                $booked = 0;
                if ($r && $row = $r->fetch_assoc()) $booked = (int)$row['booked'];

                $capacity = (int)($classRow['capacity'] ?? 15);

                if ($booked < $capacity) {
                    if (executeQuery($conn,
                        "INSERT INTO class_bookings (user_id, class_id, status, booked_at) 
                         VALUES (?, ?, 'Confirmed', NOW())",
                        "ii", [$userId, $classId]
                    )) {
                        $message = 'Successfully booked! See you at the class.';
                        $messageType = 'success';

                        if (tableExists($conn, 'notifications')) {
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at)
                                 VALUES (?, 'session', 'Class Booked', ?, NOW())",
                                "is", [$userId, 'You are confirmed for ' . $classRow['name'] . ' on ' . date('M d', strtotime($classRow['class_date'])) . '.']
                            );
                        }
                    } else {
                        $message = 'Could not complete booking. Please try again.';
                        $messageType = 'error';
                    }
                } else {
                    if (executeQuery($conn,
                        "INSERT INTO class_bookings (user_id, class_id, status, booked_at) 
                         VALUES (?, ?, 'Waitlist', NOW())",
                        "ii", [$userId, $classId]
                    )) {
                        $message = 'Class is full. You joined the waitlist!';
                        $messageType = 'success';
                    } else {
                        $message = 'Could not join waitlist. Please try again.';
                        $messageType = 'error';
                    }
                }
            }
        } elseif ($_POST['action'] === 'cancel_booking') {
            $wasConfirmed = ($_POST['was_confirmed'] ?? '0') === '1';

            if (executeQuery($conn,
                "UPDATE class_bookings 
                 SET status = 'Cancelled', cancelled_at = NOW() 
                 WHERE user_id = ? AND class_id = ? AND status IN ('Confirmed','Waitlist')",
                "ii", [$userId, $classId]
            )) {
                $message = 'Booking cancelled.';
                $messageType = 'success';

                // Promote waitlist if a confirmed slot freed up
                if ($wasConfirmed && $classRow) {
                    $r = safeQuery($conn,
                        "SELECT id, user_id FROM class_bookings 
                         WHERE class_id = ? AND status = 'Waitlist' 
                         ORDER BY booked_at ASC LIMIT 1",
                        "i", [$classId]
                    );
                    if ($r && $row = $r->fetch_assoc()) {
                        executeQuery($conn,
                            "UPDATE class_bookings SET status = 'Confirmed' WHERE id = ?",
                            "i", [(int)$row['id']]
                        );
                        if (tableExists($conn, 'notifications')) {
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at)
                                 VALUES (?, 'session', 'Waitlist Confirmed', ?, NOW())",
                                "is", [(int)$row['user_id'], 'A slot opened up for ' . $classRow['name'] . '. You are now confirmed!']
                            );
                        }
                    }
                }
            } else {
                $message = 'Could not cancel booking.';
                $messageType = 'error';
            }
        }
    }
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
$userAvatar = $userData['profile_image'] ?? '';

// ============================================
// FETCH UNREAD NOTIFICATIONS
// ============================================
$unreadNotifCount = 0;
if (tableExists($conn, 'notifications')) {
    $r = safeQuery($conn,
        "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0",
        "i", [$userId]
    );
    if ($r && $row = $r->fetch_assoc()) $unreadNotifCount = (int)$row['total'];
}

// ============================================
// FETCH CLASSES (NO COACH DATA)
// ============================================
$classes = [];
if (tableExists($conn, 'classes')) {
    $r = safeQuery($conn,
        "SELECT c.id, c.name, c.description, c.class_date, c.start_time, c.end_time,
                c.duration_minutes, c.capacity, c.location, c.difficulty, c.category,
                (SELECT COUNT(*) FROM class_bookings 
                 WHERE class_id = c.id AND status = 'Confirmed') AS booked_count,
                (SELECT COUNT(*) FROM class_bookings 
                 WHERE class_id = c.id AND status = 'Waitlist') AS waitlist_count
         FROM classes c
         WHERE c.class_date >= CURDATE() AND c.is_active = 1
         ORDER BY c.class_date ASC, c.start_time ASC
         LIMIT 100"
    );
    if ($r) {
        while ($row = $r->fetch_assoc()) $classes[] = $row;
    }
}

// ============================================
// FETCH USER'S BOOKINGS
// ============================================
$userBookings = [];
if (tableExists($conn, 'class_bookings')) {
    $r = safeQuery($conn,
        "SELECT class_id, status FROM class_bookings 
         WHERE user_id = ? AND status IN ('Confirmed','Waitlist')",
        "i", [$userId]
    );
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $userBookings[(int)$row['class_id']] = $row['status'];
        }
    }
}

// ============================================
// FILTER + GROUP CLASSES BY DATE
// ============================================
$filter = $_GET['filter'] ?? 'all';
$groupedClasses = [];
$today = date('Y-m-d');

foreach ($classes as $class) {
    if ($filter === 'my-bookings' && !isset($userBookings[(int)$class['id']])) continue;
    if (in_array($filter, ['strength', 'cardio', 'yoga'], true) 
        && stripos($class['category'], $filter) === false) continue;

    $date = $class['class_date'];
    if (!isset($groupedClasses[$date])) $groupedClasses[$date] = [];
    $groupedClasses[$date][] = $class;
}

ksort($groupedClasses);

// ============================================
// HELPER FUNCTIONS
// ============================================
function getDateLabel($date) {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($date === $today) return 'Today';
    if ($date === $tomorrow) return 'Tomorrow';
    if ($date === $yesterday) return 'Yesterday';
    return date('l, M j', strtotime($date));
}

function getCategoryIcon($category) {
    $icons = [
        'Strength' => 'bi-lightning-charge-fill',
        'Cardio' => 'bi-heart-pulse-fill',
        'Yoga' => 'bi-flower1',
        'Dance' => 'bi-music-note-beamed',
        'Combat' => 'bi-shield-fill',
        'Pilates' => 'bi-universal-access',
    ];
    return $icons[$category] ?? 'bi-trophy-fill';
}

function getCategoryColor($category) {
    $colors = [
        'Strength' => ['bg' => 'rgba(0,91,159,0.12)', 'text' => '#005b9f'],
        'Cardio' => ['bg' => 'rgba(239,68,68,0.12)', 'text' => '#b91c1c'],
        'Yoga' => ['bg' => 'rgba(139,92,246,0.12)', 'text' => '#6b21a8'],
        'Dance' => ['bg' => 'rgba(236,72,153,0.12)', 'text' => '#9d174d'],
        'Combat' => ['bg' => 'rgba(245,158,11,0.12)', 'text' => '#b45309'],
        'Pilates' => ['bg' => 'rgba(16,185,129,0.12)', 'text' => '#065f46'],
    ];
    return $colors[$category] ?? ['bg' => 'rgba(107,114,128,0.12)', 'text' => '#374151'];
}

function getDifficultyBadge($difficulty) {
    $map = [
        'Beginner' => ['class' => 'beginner', 'icon' => 'bi-1-circle-fill'],
        'Intermediate' => ['class' => 'intermediate', 'icon' => 'bi-2-circle-fill'],
        'Advanced' => ['class' => 'advanced', 'icon' => 'bi-3-circle-fill'],
    ];
    return $map[$difficulty] ?? ['class' => 'beginner', 'icon' => 'bi-circle-fill'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classes & Booking - UMak Gym: Fitsense</title>
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

        /* SIDEBAR */
        .sidebar {
            position: fixed; left: 0; top: 0;
            width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, #005b9f 0%, #00467a 100%);
            color: white; overflow-y: auto; z-index: 1000;
            display: flex; flex-direction: column;
            box-shadow: 4px 0 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
        }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
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

        /* MAIN WRAPPER */
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
            overflow: hidden;
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar:hover { transform: scale(1.05); }

        /* CONTENT */
        .container { max-width: 1300px; margin: 30px auto; padding: 0 25px; }
        .page-title {
            font-size: 26px; font-weight: 800; color: var(--umak-blue);
            margin: 0 0 6px; display: flex; align-items: center; gap: 12px;
            letter-spacing: 0.5px;
        }
        .page-title i {
            font-size: 28px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .page-subtitle { color: var(--text-muted); font-size: 14px; margin-bottom: 28px; }

        /* ALERTS */
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

        /* STATS */
        .stats-row {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px; margin-bottom: 25px;
        }
        .stat-box {
            background: var(--card-bg); border-radius: 16px;
            padding: 20px 22px; border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            display: flex; align-items: center; gap: 15px;
            transition: all 0.25s ease;
        }
        .stat-box:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
        .stat-box-icon {
            width: 50px; height: 50px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
        }
        .stat-box-icon.blue { background: linear-gradient(135deg, rgba(0,91,159,0.12), rgba(0,132,209,0.18)); color: var(--umak-blue); }
        .stat-box-icon.green { background: linear-gradient(135deg, rgba(46,125,50,0.12), rgba(74,222,128,0.18)); color: var(--umak-green); }
        .stat-box-icon.orange { background: linear-gradient(135deg, rgba(245,158,11,0.12), rgba(251,191,36,0.18)); color: #b45309; }
        .stat-box-icon.purple { background: linear-gradient(135deg, rgba(139,92,246,0.12), rgba(167,139,250,0.18)); color: #7c3aed; }
        .stat-box-info { flex: 1; min-width: 0; }
        .stat-box-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .stat-box-value { font-size: 24px; font-weight: 800; color: #111827; line-height: 1.1; letter-spacing: -0.5px; }
        .stat-box-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }

        /* FILTER TABS */
        .filter-tabs { display: flex; gap: 8px; margin-bottom: 25px; overflow-x: auto; padding-bottom: 4px; }
        .filter-tabs::-webkit-scrollbar { height: 6px; }
        .filter-tabs::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
        .filter-tab {
            flex-shrink: 0; padding: 10px 20px;
            background: white; border: 2px solid var(--border);
            border-radius: 12px; font-size: 13px; font-weight: 700;
            color: var(--text-muted); cursor: pointer; text-decoration: none;
            transition: all 0.2s ease; display: inline-flex;
            align-items: center; gap: 8px;
        }
        .filter-tab:hover { border-color: rgba(0,91,159,0.3); color: var(--umak-blue); transform: translateY(-2px); }
        .filter-tab.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            border-color: transparent; color: white;
            box-shadow: 0 6px 18px rgba(0,91,159,0.3);
        }

        /* DATE HEADER */
        .date-header { display: flex; align-items: center; gap: 15px; margin: 30px 0 20px; position: relative; }
        .date-header:first-of-type { margin-top: 0; }
        .date-pill {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 12px 22px; border-radius: 30px;
            font-size: 15px; font-weight: 800; letter-spacing: 0.3px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; box-shadow: 0 6px 18px rgba(0,91,159,0.25);
            flex-shrink: 0;
        }
        .date-pill.today { background: linear-gradient(135deg, #ffce00, #ffb800); color: #333; box-shadow: 0 6px 18px rgba(255,206,0,0.4); }
        .date-pill.tomorrow { background: linear-gradient(135deg, #8b5cf6, #7c3aed); box-shadow: 0 6px 18px rgba(139,92,246,0.3); }
        .date-pill .count-badge {
            background: rgba(255,255,255,0.25); padding: 2px 10px;
            border-radius: 12px; font-size: 12px; font-weight: 800;
        }
        .date-line { flex: 1; height: 1px; background: linear-gradient(90deg, var(--border), transparent); }

        /* CLASS CARD */
        .classes-list { display: flex; flex-direction: column; gap: 14px; margin-bottom: 30px; }
        .class-card {
            background: var(--card-bg); border-radius: 18px;
            padding: 22px 26px; border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            display: flex; align-items: center; gap: 22px;
            transition: all 0.25s ease; position: relative; overflow: hidden;
        }
        .class-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.08);
            border-color: rgba(0,91,159,0.2);
        }
        .class-card.booked { border-left: 5px solid var(--umak-green); background: linear-gradient(90deg, rgba(46,125,50,0.03), #ffffff 30%); }
        .class-card.waitlisted { border-left: 5px solid var(--umak-orange); background: linear-gradient(90deg, rgba(245,158,11,0.03), #ffffff 30%); }
        .class-card.full { opacity: 0.85; }

        /* Time block */
        .class-time-block {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            min-width: 95px; padding: 14px 12px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(0,91,159,0.08), rgba(0,132,209,0.12));
            border: 1px solid rgba(0,91,159,0.12);
            flex-shrink: 0; gap: 4px;
        }
        .class-time-block .start-time {
            font-size: 20px; font-weight: 800;
            color: var(--umak-blue); line-height: 1; letter-spacing: -0.5px;
        }
        .class-time-block .duration {
            font-size: 11px; font-weight: 700;
            color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.8px;
        }
        .class-time-block .time-sep {
            width: 24px; height: 2px;
            background: rgba(0,91,159,0.2);
            border-radius: 1px; margin: 2px 0;
        }
        .class-time-block .end-time {
            font-size: 12px; font-weight: 700; color: var(--text-muted);
        }

        /* Class info */
        .class-info { flex: 1; min-width: 0; }
        .class-header-row {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 8px; flex-wrap: wrap;
        }
        .class-name {
            font-size: 18px; font-weight: 800;
            color: #111827; margin: 0; letter-spacing: -0.3px;
        }
        .class-category-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 8px;
            font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.8px;
        }
        .class-difficulty-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 8px;
            font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.8px;
        }
        .class-difficulty-badge.beginner { background: #dcfce7; color: #166534; }
        .class-difficulty-badge.intermediate { background: #fef3c7; color: #92400e; }
        .class-difficulty-badge.advanced { background: #fee2e2; color: #991b1b; }

        .class-meta-row {
            display: flex; align-items: center;
            gap: 18px; flex-wrap: wrap;
            font-size: 13px; color: var(--text-muted);
            font-weight: 600; margin-bottom: 10px;
        }
        .class-meta-item { display: inline-flex; align-items: center; gap: 6px; }
        .class-meta-item i { color: var(--umak-blue); font-size: 13px; }

        /* Capacity */
        .class-capacity { min-width: 200px; flex-shrink: 0; }
        .capacity-header {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 6px;
            font-size: 12px; font-weight: 700;
        }
        .capacity-label {
            color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.5px; font-size: 11px;
        }
        .capacity-value { font-size: 15px; font-weight: 800; color: #111827; }
        .capacity-value.low { color: #f59e0b; }
        .capacity-value.full { color: #ef4444; }
        .capacity-track {
            height: 8px; background: #f3f4f6;
            border-radius: 4px; overflow: hidden; position: relative;
        }
        .capacity-fill {
            height: 100%; border-radius: 4px;
            transition: width 0.8s ease;
            background: linear-gradient(90deg, #2e7d32, #4ade80);
        }
        .capacity-fill.medium { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .capacity-fill.high { background: linear-gradient(90deg, #ef4444, #f87171); }
        .capacity-hint {
            font-size: 11.5px; font-weight: 600;
            color: var(--text-muted); margin-top: 6px;
            display: flex; align-items: center; gap: 4px;
        }
        .capacity-hint.warning { color: #b45309; }
        .capacity-hint.danger { color: #b91c1c; }

        /* Booking button */
        .class-action {
            display: flex; flex-direction: column;
            align-items: flex-end; gap: 8px;
            min-width: 130px; flex-shrink: 0;
        }
        .btn-book {
            padding: 12px 24px; border-radius: 12px;
            font-size: 13px; font-weight: 800;
            cursor: pointer; border: none;
            display: inline-flex; align-items: center;
            justify-content: center; gap: 8px;
            transition: all 0.25s ease;
            letter-spacing: 0.5px; text-transform: uppercase;
            width: 100%; font-family: inherit;
        }
        .btn-book.primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; box-shadow: 0 4px 15px rgba(0,91,159,0.3);
        }
        .btn-book.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(0,91,159,0.4);
        }
        .btn-book.waitlist {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: #333; box-shadow: 0 4px 15px rgba(245,158,11,0.3);
        }
        .btn-book.waitlist:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(245,158,11,0.4);
        }
        .btn-book.booked {
            background: linear-gradient(135deg, #2e7d32, #4ade80);
            color: white; box-shadow: 0 4px 15px rgba(46,125,50,0.3);
            cursor: default;
        }
        .btn-book.cancel {
            background: white; color: #b91c1c;
            border: 2px solid #fecaca;
            font-size: 11.5px; padding: 8px 14px;
            text-transform: none; letter-spacing: 0.3px;
        }
        .btn-book.cancel:hover { background: #fee2e2; border-color: #ef4444; }

        .booking-status-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 8px;
            font-size: 10.5px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.8px;
        }
        .booking-status-badge.confirmed { background: #dcfce7; color: #166534; }
        .booking-status-badge.waitlist { background: #fef3c7; color: #92400e; }

        /* EMPTY STATE */
        .empty-state {
            text-align: center; padding: 70px 20px;
            background: var(--card-bg); border-radius: 20px;
            border: 2px dashed var(--border);
        }
        .empty-state-icon {
            width: 90px; height: 90px; margin: 0 auto 20px;
            background: rgba(0,91,159,0.08); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 42px; color: var(--umak-blue);
        }
        .empty-state h3 { margin: 0 0 10px; color: var(--umak-blue); font-size: 20px; font-weight: 800; }
        .empty-state p { color: var(--text-muted); font-size: 14px; margin: 0 auto; max-width: 400px; line-height: 1.6; }

        /* RESPONSIVE */
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
            .page-title { font-size: 20px; }
            .class-card { padding: 18px; gap: 14px; flex-wrap: wrap; }
            .class-time-block { min-width: 80px; padding: 10px 8px; }
            .class-time-block .start-time { font-size: 17px; }
            .class-name { font-size: 15px; }
            .class-capacity { min-width: 100%; }
            .class-action { width: 100%; min-width: 100%; flex-direction: row; }
            .class-action .btn-book { flex: 1; }
        }
        @media (max-width: 576px) {
            .class-header-row { gap: 6px; }
            .class-meta-row { gap: 12px; font-size: 12px; }
            .stats-row { grid-template-columns: 1fr; }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css"><link rel="stylesheet" href="/PBL/css/user-content-icons.css"></head>
<body>

<!-- SIDEBAR -->
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
        <a href="classes.php" class="nav-item active">
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

<!-- MAIN WRAPPER -->
<div class="main-wrapper">

    <header class="top-header">
        <div class="header-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h2>Classes & Booking</h2>
                <p class="subtitle">Reserve your spot in group fitness classes</p>
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
                    <?php if (!empty($userAvatar) && file_exists(__DIR__ . '/' . $userAvatar)): ?>
                        <img src="<?= htmlspecialchars($userAvatar) ?>" alt="">
                    <?php else: ?>
                        <?= htmlspecialchars($userInitial) ?>
                    <?php endif; ?>
                </div>
            </button>
        </div>
    </header>

    <main class="container">

        <h1 class="page-title">
            <i class="bi bi-calendar-event-fill"></i>
            Class Schedule
        </h1>
        <p class="page-subtitle">Browse upcoming classes and book your spot. Classes fill up fast!</p>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>" style="font-size: 20px;"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php
        $confirmedCount = 0;
        $waitlistedCount = 0;
        foreach ($userBookings as $status) {
            if ($status === 'Confirmed') $confirmedCount++;
            elseif ($status === 'Waitlist') $waitlistedCount++;
        }
        $todayCount = isset($groupedClasses[$today]) ? count($groupedClasses[$today]) : 0;
        $totalUpcoming = count($classes);
        ?>
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-box-icon blue"><i class="bi bi-calendar-event-fill"></i></div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Upcoming Classes</div>
                    <div class="stat-box-value"><?= $totalUpcoming ?></div>
                    <div class="stat-box-sub">available to book</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon green"><i class="bi bi-check-circle-fill"></i></div>
                <div class="stat-box-info">
                    <div class="stat-box-label">My Bookings</div>
                    <div class="stat-box-value"><?= $confirmedCount ?></div>
                    <div class="stat-box-sub">confirmed reservations</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon orange"><i class="bi bi-hourglass-split"></i></div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Waitlisted</div>
                    <div class="stat-box-value"><?= $waitlistedCount ?></div>
                    <div class="stat-box-sub">waiting for slot</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon purple"><i class="bi bi-sun-fill"></i></div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Today</div>
                    <div class="stat-box-value"><?= $todayCount ?></div>
                    <div class="stat-box-sub">classes scheduled</div>
                </div>
            </div>
        </div>

        <div class="filter-tabs">
            <a href="?filter=all" class="filter-tab <?= ($filter === 'all') ? 'active' : '' ?>">
                <i class="bi bi-collection-fill"></i> All Classes
            </a>
            <a href="?filter=my-bookings" class="filter-tab <?= ($filter === 'my-bookings') ? 'active' : '' ?>">
                <i class="bi bi-bookmark-check-fill"></i> My Bookings
            </a>
            <a href="?filter=strength" class="filter-tab <?= ($filter === 'strength') ? 'active' : '' ?>">
                <i class="bi bi-lightning-charge-fill"></i> Strength
            </a>
            <a href="?filter=cardio" class="filter-tab <?= ($filter === 'cardio') ? 'active' : '' ?>">
                <i class="bi bi-heart-pulse-fill"></i> Cardio
            </a>
            <a href="?filter=yoga" class="filter-tab <?= ($filter === 'yoga') ? 'active' : '' ?>">
                <i class="bi bi-flower1"></i> Yoga
            </a>
        </div>

        <?php if (empty($groupedClasses)): ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="bi bi-calendar-x"></i></div>
                <h3>No Classes Available</h3>
                <p>There are no upcoming classes at the moment. Check back soon for new schedules!</p>
            </div>
        <?php else: ?>
            <?php foreach ($groupedClasses as $date => $dateClasses):
                $dateLabel = getDateLabel($date);
                $isToday = $date === $today;
                $isTomorrow = $date === date('Y-m-d', strtotime('+1 day'));
                $pillClass = $isToday ? 'today' : ($isTomorrow ? 'tomorrow' : '');
            ?>
                <div class="date-header">
                    <div class="date-pill <?= $pillClass ?>">
                        <i class="bi bi-calendar3"></i>
                        <?= htmlspecialchars($dateLabel) ?>
                        <span class="count-badge"><?= count($dateClasses) ?></span>
                    </div>
                    <div class="date-line"></div>
                </div>

                <div class="classes-list">
                    <?php foreach ($dateClasses as $class):
                        $classId = (int)$class['id'];
                        $booked = (int)$class['booked_count'];
                        $capacity = (int)$class['capacity'];
                        $available = max(0, $capacity - $booked);
                        $percentFull = $capacity > 0 ? round(($booked / $capacity) * 100) : 0;
                        $isFull = $booked >= $capacity;
                        $isAlmostFull = !$isFull && $available <= 3;

                        $userStatus = $userBookings[$classId] ?? null;
                        $catColor = getCategoryColor($class['category']);
                        $catIcon = getCategoryIcon($class['category']);
                        $diffBadge = getDifficultyBadge($class['difficulty']);

                        $cardClass = '';
                        if ($userStatus === 'Confirmed') $cardClass = 'booked';
                        elseif ($userStatus === 'Waitlist') $cardClass = 'waitlisted';
                        elseif ($isFull) $cardClass = 'full';

                        $fillClass = '';
                        if ($percentFull >= 90) $fillClass = 'high';
                        elseif ($percentFull >= 70) $fillClass = 'medium';
                    ?>
                        <div class="class-card <?= $cardClass ?>">
                            <div class="class-time-block">
                                <div class="start-time"><?= date('g:i', strtotime($class['start_time'])) ?></div>
                                <div class="time-sep"></div>
                                <div class="end-time"><?= date('g:i A', strtotime($class['end_time'])) ?></div>
                                <div class="duration" style="margin-top: 4px;">
                                    <i class="bi bi-clock"></i> <?= (int)$class['duration_minutes'] ?>m
                                </div>
                            </div>

                            <div class="class-info">
                                <div class="class-header-row">
                                    <h3 class="class-name"><?= htmlspecialchars($class['name']) ?></h3>

                                    <span class="class-category-badge"
                                          style="background: <?= $catColor['bg'] ?>; color: <?= $catColor['text'] ?>;">
                                        <i class="bi <?= $catIcon ?>"></i>
                                        <?= htmlspecialchars($class['category']) ?>
                                    </span>

                                    <span class="class-difficulty-badge <?= $diffBadge['class'] ?>">
                                        <i class="bi <?= $diffBadge['icon'] ?>"></i>
                                        <?= htmlspecialchars($class['difficulty']) ?>
                                    </span>

                                    <?php if ($userStatus === 'Confirmed'): ?>
                                        <span class="booking-status-badge confirmed">
                                            <i class="bi bi-check-circle-fill"></i> Booked
                                        </span>
                                    <?php elseif ($userStatus === 'Waitlist'): ?>
                                        <span class="booking-status-badge waitlist">
                                            <i class="bi bi-hourglass-split"></i> Waitlisted
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($class['description'])): ?>
                                    <p style="margin: 0 0 10px; font-size: 13px; color: var(--text-muted); line-height: 1.5;">
                                        <?= htmlspecialchars($class['description']) ?>
                                    </p>
                                <?php endif; ?>

                                <div class="class-meta-row">
                                    <?php if (!empty($class['location'])): ?>
                                        <div class="class-meta-item">
                                            <i class="bi bi-geo-alt-fill"></i>
                                            <?= htmlspecialchars($class['location']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="class-meta-item">
                                        <i class="bi bi-people-fill"></i>
                                        <?= $capacity ?> max
                                    </div>
                                </div>
                            </div>

                            <div class="class-capacity">
                                <div class="capacity-header">
                                    <span class="capacity-label">Slots</span>
                                    <span class="capacity-value <?= $isFull ? 'full' : ($isAlmostFull ? 'low' : '') ?>">
                                        <?= $booked ?> / <?= $capacity ?>
                                    </span>
                                </div>
                                <div class="capacity-track">
                                    <div class="capacity-fill <?= $fillClass ?>"
                                         style="width: <?= $percentFull ?>%"></div>
                                </div>
                                <?php if ($isFull): ?>
                                    <div class="capacity-hint danger">
                                        <i class="bi bi-x-circle-fill"></i> Class is full
                                        <?php if ($class['waitlist_count'] > 0): ?>
                                            · <?= (int)$class['waitlist_count'] ?> on waitlist
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ($isAlmostFull): ?>
                                    <div class="capacity-hint warning">
                                        <i class="bi bi-exclamation-triangle-fill"></i>
                                        Only <?= $available ?> slot<?= $available != 1 ? 's' : '' ?> left!
                                    </div>
                                <?php else: ?>
                                    <div class="capacity-hint">
                                        <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                                        <?= $available ?> slots available
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="class-action">
                                <?php if ($userStatus === 'Confirmed'): ?>
                                    <button class="btn-book booked" disabled>
                                        <i class="bi bi-check-circle-fill"></i> Booked
                                    </button>
                                    <form method="POST" style="width: 100%;">
                                        <input type="hidden" name="action" value="cancel_booking">
                                        <?php echo app_csrf_field(); ?>
                                        <input type="hidden" name="class_id" value="<?= $classId ?>">
                                        <input type="hidden" name="was_confirmed" value="1">
                                        <button type="submit" class="btn-book cancel"
                                                onclick="return confirm('Cancel your booking for <?= htmlspecialchars(addslashes($class['name'])) ?>?')">
                                            <i class="bi bi-x-circle"></i> Cancel Booking
                                        </button>
                                    </form>
                                <?php elseif ($userStatus === 'Waitlist'): ?>
                                    <button class="btn-book waitlist" disabled>
                                        <i class="bi bi-hourglass-split"></i> Waitlisted
                                    </button>
                                    <form method="POST" style="width: 100%;">
                                        <input type="hidden" name="action" value="cancel_booking">
                                        <?php echo app_csrf_field(); ?>
                                        <input type="hidden" name="class_id" value="<?= $classId ?>">
                                        <input type="hidden" name="was_confirmed" value="0">
                                        <button type="submit" class="btn-book cancel"
                                                onclick="return confirm('Leave the waitlist?')">
                                            <i class="bi bi-x-circle"></i> Leave Waitlist
                                        </button>
                                    </form>
                                <?php elseif ($isFull): ?>
                                    <form method="POST" style="width: 100%;">
                                        <input type="hidden" name="action" value="book_class">
                                        <?php echo app_csrf_field(); ?>
                                        <input type="hidden" name="class_id" value="<?= $classId ?>">
                                        <button type="submit" class="btn-book waitlist">
                                            <i class="bi bi-hourglass-split"></i> Join Waitlist
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="width: 100%;">
                                        <input type="hidden" name="action" value="book_class">
                                        <?php echo app_csrf_field(); ?>
                                        <input type="hidden" name="class_id" value="<?= $classId ?>">
                                        <button type="submit" class="btn-book primary">
                                            <i class="bi bi-calendar-plus-fill"></i> Book
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

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