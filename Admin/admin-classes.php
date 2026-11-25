<?php
// Admin/admin-classes.php — Manage classes (single file, all CRUD)
session_start();
include __DIR__ . '/../conn.php';
require_once __DIR__ . '/../config/session_security.php';

// ============================================
// AUTH CHECK (ADMIN ONLY)
// ============================================
$userSession = app_require_session_user($conn, ['admin', 'super_admin'], 'admin-login.php');
$role = $_SESSION['role'];
$adminId = (int)$_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

// ============================================
// HELPERS
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
        error_log("SafeQuery: " . $e->getMessage());
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
        error_log("ExecuteQuery: " . $e->getMessage());
        return false;
    }
}

function tableExists($conn, $t) {
    $t = $conn->real_escape_string($t);
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    return $r && $r->num_rows > 0;
}

function flash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// ============================================
// HANDLE POST ACTIONS
// ============================================
$validDifficulties = ['Beginner', 'Intermediate', 'Advanced'];
$defaultCategories = ['Strength', 'Cardio', 'Yoga', 'Dance', 'Combat', 'Pilates', 'CrossFit', 'General'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        flash('error', 'This form has expired or is invalid. Please refresh the page and try again.');
        header('Location: admin-classes.php');
        exit;
    }

    // -------- CREATE CLASS --------
    if ($_POST['action'] === 'create_class') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $classDate = $_POST['class_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        $duration = (int)($_POST['duration_minutes'] ?? 60);
        $capacity = (int)($_POST['capacity'] ?? 15);
        $location = trim($_POST['location'] ?? '');
        $difficulty = $_POST['difficulty'] ?? 'Beginner';
        $category = trim($_POST['category'] ?? 'General');

        if (!in_array($difficulty, $validDifficulties)) $difficulty = 'Beginner';
        if ($capacity < 1) $capacity = 15;
        if ($capacity > 100) $capacity = 100;

        if ($startTime && $endTime) {
            $start = strtotime($startTime);
            $end = strtotime($endTime);
            if ($end > $start) $duration = (int)(($end - $start) / 60);
        }

        if (empty($name) || empty($classDate) || empty($startTime) || empty($endTime)) {
            flash('error', 'Please fill in all required fields.');
        } else {
            $sql = "INSERT INTO classes 
                    (name, description, class_date, start_time, end_time, duration_minutes, 
                     capacity, location, difficulty, category, is_active, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
            if (executeQuery($conn, $sql, "sssssiisss",
                [$name, $description, $classDate, $startTime, $endTime, $duration,
                 $capacity, $location, $difficulty, $category]
            )) {
                flash('success', 'Class "' . htmlspecialchars($name) . '" created successfully.');
            } else {
                flash('error', 'Failed to create class. Please check your input.');
            }
        }
        header("Location: admin-classes.php");
        exit;
    }

    // -------- UPDATE CLASS --------
    if ($_POST['action'] === 'update_class') {
        $classId = (int)($_POST['class_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $classDate = $_POST['class_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        $duration = (int)($_POST['duration_minutes'] ?? 60);
        $capacity = (int)($_POST['capacity'] ?? 15);
        $location = trim($_POST['location'] ?? '');
        $difficulty = $_POST['difficulty'] ?? 'Beginner';
        $category = trim($_POST['category'] ?? 'General');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($difficulty, $validDifficulties)) $difficulty = 'Beginner';
        if ($capacity < 1) $capacity = 15;
        if ($capacity > 100) $capacity = 100;

        if ($startTime && $endTime) {
            $start = strtotime($startTime);
            $end = strtotime($endTime);
            if ($end > $start) $duration = (int)(($end - $start) / 60);
        }

        if ($classId <= 0 || empty($name) || empty($classDate) || empty($startTime) || empty($endTime)) {
            flash('error', 'Please fill in all required fields.');
        } else {
            $sql = "UPDATE classes SET 
                        name = ?, description = ?, class_date = ?, start_time = ?, end_time = ?,
                        duration_minutes = ?, capacity = ?, location = ?, difficulty = ?, 
                        category = ?, is_active = ?
                    WHERE id = ?";
            if (executeQuery($conn, $sql, "sssssiisssii",
                [$name, $description, $classDate, $startTime, $endTime, $duration,
                 $capacity, $location, $difficulty, $category, $isActive, $classId]
            )) {
                flash('success', 'Class updated successfully.');
            } else {
                flash('error', 'Failed to update class.');
            }
        }
        header("Location: admin-classes.php?tab=list");
        exit;
    }

    // -------- TOGGLE ACTIVE --------
    if ($_POST['action'] === 'toggle_active') {
        $classId = (int)($_POST['class_id'] ?? 0);
        if ($classId > 0) {
            executeQuery($conn, "UPDATE classes SET is_active = NOT is_active WHERE id = ?", "i", [$classId]);
            flash('success', 'Class status updated.');
        }
        header("Location: admin-classes.php");
        exit;
    }

    // -------- DELETE CLASS --------
    if ($_POST['action'] === 'delete_class') {
        $classId = (int)($_POST['class_id'] ?? 0);
        if ($classId > 0) {
            $r = safeQuery($conn, "SELECT COUNT(*) AS c FROM class_bookings WHERE class_id = ?", "i", [$classId]);
            $bookingCount = 0;
            if ($r && $row = $r->fetch_assoc()) $bookingCount = (int)$row['c'];

            if ($bookingCount > 0) {
                flash('error', "Cannot delete: {$bookingCount} booking(s) exist. Deactivate it instead.");
            } else {
                if (executeQuery($conn, "DELETE FROM classes WHERE id = ?", "i", [$classId])) {
                    flash('success', 'Class deleted.');
                } else {
                    flash('error', 'Failed to delete class.');
                }
            }
        }
        header("Location: admin-classes.php");
        exit;
    }

    // -------- UPDATE BOOKING STATUS --------
    if ($_POST['action'] === 'update_booking_status') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        $classId = (int)($_POST['class_id'] ?? 0);
        $validStatuses = ['Confirmed', 'Waitlist', 'Cancelled', 'Attended', 'No-Show'];

        if ($bookingId > 0 && in_array($newStatus, $validStatuses)) {
            executeQuery($conn, "UPDATE class_bookings SET status = ? WHERE id = ?", "si", [$newStatus, $bookingId]);
            flash('success', 'Booking status updated.');
        }
        header("Location: admin-classes.php?tab=bookings&class_id=" . $classId);
        exit;
    }
}

// ============================================
// FETCH ADMIN DATA
// ============================================
$adminData = null;
$stmt = $conn->prepare("SELECT id, fullname, username, email, profile_image, role FROM users WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $adminData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$adminName = $adminData['fullname'] ?? 'Administrator';
$adminFirstName = explode(' ', trim($adminName))[0];
$adminInitial = strtoupper(substr($adminName, 0, 1));
$adminAvatar = $adminData['profile_image'] ?? '';

$unreadNotifCount = 0;
if (tableExists($conn, 'notifications')) {
    $r = safeQuery($conn, "SELECT COUNT(*) AS t FROM notifications WHERE user_id = ? AND is_read = 0", "i", [$adminId]);
    if ($r && $row = $r->fetch_assoc()) $unreadNotifCount = (int)$row['t'];
}

// ============================================
// TABS
// ============================================
$activeTab = $_GET['tab'] ?? 'list';
$editId = (int)($_GET['edit'] ?? 0);
$viewBookingsClassId = (int)($_GET['class_id'] ?? 0);

if ($editId > 0) $activeTab = 'edit';
if ($viewBookingsClassId > 0 && $activeTab === 'list') $activeTab = 'bookings';

// ============================================
// FETCH CLASSES
// ============================================
$search = trim($_GET['search'] ?? '');
$filterCategory = $_GET['category'] ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$filterWhen = $_GET['when'] ?? 'all';

$sql = "SELECT c.id, c.name, c.description, c.class_date, c.start_time, c.end_time,
               c.duration_minutes, c.capacity, c.location, c.difficulty, c.category,
               c.is_active, c.created_at,
               (SELECT COUNT(*) FROM class_bookings WHERE class_id = c.id AND status = 'Confirmed') AS booked_count,
               (SELECT COUNT(*) FROM class_bookings WHERE class_id = c.id AND status = 'Waitlist') AS waitlist_count
        FROM classes c WHERE 1=1";
$params = []; $types = '';

if ($search !== '') {
    $sql .= " AND (c.name LIKE ? OR c.description LIKE ? OR c.location LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s;
    $types .= 'sss';
}
if ($filterCategory !== 'all') {
    $sql .= " AND c.category = ?";
    $params[] = $filterCategory;
    $types .= 's';
}
if ($filterStatus !== 'all') {
    $sql .= " AND c.is_active = ?";
    $params[] = ($filterStatus === 'active' ? 1 : 0);
    $types .= 'i';
}
if ($filterWhen === 'today') $sql .= " AND c.class_date = CURDATE()";
elseif ($filterWhen === 'upcoming') $sql .= " AND c.class_date >= CURDATE()";
elseif ($filterWhen === 'past') $sql .= " AND c.class_date < CURDATE()";

$sql .= " ORDER BY c.class_date DESC, c.start_time DESC LIMIT 200";

$classes = [];
$r = safeQuery($conn, $sql, $types, $params);
if ($r) while ($row = $r->fetch_assoc()) $classes[] = $row;

// ============================================
// STATS
// ============================================
$stats = ['total' => 0, 'active' => 0, 'upcoming' => 0, 'today' => 0, 'bookings' => 0];
$r = safeQuery($conn, "SELECT COUNT(*) AS total, SUM(is_active) AS active,
    SUM(class_date >= CURDATE()) AS upcoming, SUM(class_date = CURDATE()) AS today FROM classes");
if ($r && $row = $r->fetch_assoc()) {
    $stats['total'] = (int)$row['total'];
    $stats['active'] = (int)$row['active'];
    $stats['upcoming'] = (int)$row['upcoming'];
    $stats['today'] = (int)$row['today'];
}
$r = safeQuery($conn, "SELECT COUNT(*) AS c FROM class_bookings WHERE status = 'Confirmed'");
if ($r && $row = $r->fetch_assoc()) $stats['bookings'] = (int)$row['c'];

// ============================================
// CATEGORIES
// ============================================
$categories = [];
$r = safeQuery($conn, "SELECT DISTINCT category FROM classes WHERE category IS NOT NULL AND category != '' ORDER BY category");
if ($r) while ($row = $r->fetch_assoc()) $categories[] = $row['category'];
if (empty($categories)) $categories = $defaultCategories;

// ============================================
// FETCH CLASS FOR EDIT
// ============================================
$editClass = null;
if ($editId > 0) {
    $r = safeQuery($conn, "SELECT * FROM classes WHERE id = ? LIMIT 1", "i", [$editId]);
    if ($r && $row = $r->fetch_assoc()) $editClass = $row;
}

// ============================================
// FETCH BOOKINGS FOR VIEW
// ============================================
$bookings = [];
$viewClass = null;
if ($viewBookingsClassId > 0) {
    $r = safeQuery($conn, "SELECT * FROM classes WHERE id = ?", "i", [$viewBookingsClassId]);
    if ($r && $row = $r->fetch_assoc()) $viewClass = $row;

    if ($viewClass) {
        $r = safeQuery($conn,
            "SELECT cb.id, cb.status, cb.booked_at, cb.cancelled_at, cb.notes,
                    u.id AS user_id, u.fullname, u.username, u.email, u.profile_image
             FROM class_bookings cb
             INNER JOIN users u ON cb.user_id = u.id
             WHERE cb.class_id = ?
             ORDER BY cb.booked_at DESC",
            "i", [$viewBookingsClassId]
        );
        if ($r) while ($row = $r->fetch_assoc()) $bookings[] = $row;
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Classes - FitSense Admin</title>
    <link rel="icon" type="image/png" href="../uploads/umak-gym-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --umak-blue: #005b9f; --umak-blue-dark: #00467a; --umak-blue-light: #0084d1;
            --umak-yellow: #ffce00; --umak-red: #d32f2f; --umak-green: #2e7d32;
            --umak-orange: #f59e0b;
            --sidebar-width: 260px; --bg: #f4f6f9; --card-bg: #fff;
            --text: #1f2937; --text-muted: #6b7280; --border: #e5e7eb;
        }
        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { margin: 0; background: var(--bg); color: var(--text); }

        /* SIDEBAR (same as admin-dashboard) */
        .sidebar {
            position: fixed; left: 0; top: 0; width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, #005b9f 0%, #003a6b 100%);
            color: white; overflow-y: auto; z-index: 1000;
            display: flex; flex-direction: column;
            box-shadow: 4px 0 15px rgba(0,0,0,0.08);
        }
        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
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
            padding: 4px 10px; border-radius: 12px; font-size: 10px;
            font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase;
            margin-top: 6px;
        }
        .admin-badge.super { background: rgba(255,206,0,0.2); color: var(--umak-yellow); border: 1px solid rgba(255,206,0,0.3); }
        .admin-badge.regular { background: rgba(74,222,128,0.2); color: #4ade80; border: 1px solid rgba(74,222,128,0.3); }
        .sidebar-nav { flex: 1; padding: 15px 10px; overflow-y: auto; }
        .nav-section-title { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: rgba(255,255,255,0.4); padding: 15px 15px 8px; font-weight: 700; }
        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 15px; color: rgba(255,255,255,0.85);
            text-decoration: none; border-radius: 8px; margin-bottom: 2px;
            font-size: 14px; font-weight: 500; transition: all 0.2s ease;
        }
        .nav-item:hover { background: rgba(255,255,255,0.1); color: white; transform: translateX(3px); }
        .nav-item.active { background: rgba(255,206,0,0.15); color: var(--umak-yellow); font-weight: 600; border-left: 3px solid var(--umak-yellow); padding-left: 12px; }
        .nav-item i { font-size: 18px; width: 22px; text-align: center; flex-shrink: 0; }
        .nav-item .badge-count { margin-left: auto; background: var(--umak-red); color: white; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px; min-width: 18px; text-align: center; }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 12px 15px; }
        .nav-logout { color: #ff8a80 !important; }
        .nav-logout:hover { background: rgba(211,47,47,0.2) !important; }

        /* MAIN */
        .main-wrapper { margin-left: var(--sidebar-width); min-height: 100vh; padding-bottom: 40px; }
        .top-header {
            background: white; padding: 15px 30px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04); position: sticky; top: 0;
            z-index: 100; border-bottom: 3px solid var(--umak-yellow);
        }
        .header-left { display: flex; align-items: center; gap: 15px; }
        .sidebar-toggle { background: none; border: none; font-size: 22px; color: var(--umak-blue); cursor: pointer; display: none; padding: 5px; }
        .header-left h2 { margin: 0; font-size: 20px; color: var(--umak-blue); font-weight: 800; }
        .header-left .subtitle { font-size: 12px; color: var(--text-muted); margin: 0; }
        .header-right { display: flex; align-items: center; gap: 12px; }
        .header-btn { background: #f4f6f9; border: none; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; position: relative; font-size: 18px; color: var(--umak-blue); transition: all 0.2s; text-decoration: none; }
        .header-btn:hover { background: #e5eef5; }
        .notif-badge { position: absolute; top: 0; right: 0; background: var(--umak-red); color: white; font-size: 10px; font-weight: bold; border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; border: 2px solid white; }
        .avatar-btn { background: none; border: none; padding: 0; cursor: pointer; }
        .avatar { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #005b9f, #0084d1); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 16px; overflow: hidden; transition: transform 0.2s; }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar:hover { transform: scale(1.05); }

        /* CONTENT */
        .container { max-width: 1400px; margin: 30px auto; padding: 0 25px; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin-bottom: 28px; flex-wrap: wrap; }
        .page-title { font-size: 26px; font-weight: 800; color: var(--umak-blue); margin: 0 0 6px; display: flex; align-items: center; gap: 12px; }
        .page-title i {
            font-size: 28px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .page-subtitle { color: var(--text-muted); font-size: 14px; margin: 0; }
        .page-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .alert { padding: 14px 20px; border-radius: 12px; font-size: 14px; font-weight: 500; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; animation: slideDown 0.4s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .alert-success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border-left: 4px solid var(--umak-green); }
        .alert-error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border-left: 4px solid var(--umak-red); }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; margin-bottom: 25px; }
        .stat-card { background: white; border-radius: 16px; padding: 22px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid var(--border); transition: all 0.25s; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px rgba(0,0,0,0.08); }
        .stat-icon { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white; margin-bottom: 16px; }
        .stat-icon.blue { background: linear-gradient(135deg, #005b9f, #0084d1); box-shadow: 0 8px 20px rgba(0,91,159,0.3); }
        .stat-icon.green { background: linear-gradient(135deg, #2e7d32, #4ade80); box-shadow: 0 8px 20px rgba(46,125,50,0.3); }
        .stat-icon.yellow { background: linear-gradient(135deg, #ffce00, #ffb800); color: #333; box-shadow: 0 8px 20px rgba(255,206,0,0.4); }
        .stat-icon.purple { background: linear-gradient(135deg, #8b5cf6, #a78bfa); box-shadow: 0 8px 20px rgba(139,92,246,0.3); }
        .stat-icon.red { background: linear-gradient(135deg, #d32f2f, #f87171); box-shadow: 0 8px 20px rgba(211,47,47,0.3); }
        .stat-label { font-size: 11.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: var(--text-muted); margin-bottom: 8px; }
        .stat-value { font-size: 30px; font-weight: 800; color: #111827; line-height: 1.1; letter-spacing: -1px; }

        .main-tabs { display: flex; gap: 6px; margin-bottom: 22px; background: white; padding: 6px; border-radius: 14px; border: 1px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.03); overflow-x: auto; }
        .main-tab { flex: 1; min-width: 120px; padding: 11px 20px; border-radius: 10px; border: none; background: transparent; font-size: 13px; font-weight: 700; color: var(--text-muted); cursor: pointer; transition: 0.25s; display: flex; align-items: center; justify-content: center; gap: 8px; font-family: inherit; text-decoration: none; }
        .main-tab:hover { background: #f3f4f6; color: var(--umak-blue); }
        .main-tab.active { background: linear-gradient(135deg, #005b9f, #0084d1); color: white; box-shadow: 0 6px 18px rgba(0,91,159,0.3); }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .panel { background: white; border-radius: 16px; padding: 24px 26px; border: 1px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 22px; }
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid var(--border); gap: 12px; flex-wrap: wrap; }
        .panel-title { margin: 0; font-size: 17px; font-weight: 800; color: var(--umak-blue); display: flex; align-items: center; gap: 10px; }
        .panel-link { font-size: 13px; color: var(--umak-blue); text-decoration: none; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; transition: 0.2s; }
        .panel-link:hover { color: var(--umak-blue-dark); gap: 8px; }

        .btn { padding: 11px 20px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; border: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.25s ease; text-decoration: none; font-family: inherit; letter-spacing: 0.3px; }
        .btn-primary { background: linear-gradient(135deg, #005b9f, #0084d1); color: white; box-shadow: 0 4px 12px rgba(0,91,159,0.25); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,91,159,0.35); color: white; }
        .btn-outline { background: white; color: var(--umak-blue); border: 2px solid var(--umak-blue); }
        .btn-outline:hover { background: var(--umak-blue); color: white; }
        .btn-success { background: linear-gradient(135deg, #2e7d32, #4ade80); color: white; }
        .btn-success:hover { transform: translateY(-2px); color: white; }

        .toolbar { background: white; border-radius: 14px; padding: 16px 20px; border: 1px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 18px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .search-box { position: relative; flex: 1; min-width: 200px; }
        .search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 15px; }
        .search-box input { width: 100%; padding: 10px 14px 10px 40px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 14px; outline: none; transition: 0.2s; font-family: inherit; }
        .search-box input:focus { border-color: var(--umak-blue); box-shadow: 0 0 0 4px rgba(0,91,159,0.1); }
        .filter-select { padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 13.5px; font-weight: 600; background: white; cursor: pointer; outline: none; font-family: inherit; }
        .filter-select:focus { border-color: var(--umak-blue); }

        .table-card { background: white; border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.03); overflow: hidden; }
        .table-responsive { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 1000px; }
        .data-table th { padding: 14px 16px; text-align: left; color: var(--text-muted); font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; background: #f9fafb; border-bottom: 1px solid var(--border); white-space: nowrap; }
        .data-table td { padding: 14px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .data-table tbody tr { transition: background 0.2s; }
        .data-table tbody tr:hover { background: #f9fafb; }
        .data-table tbody tr:last-child td { border-bottom: none; }

        .class-name-cell { display: flex; flex-direction: column; gap: 4px; min-width: 220px; }
        .class-name-cell .name { font-weight: 800; color: #111827; font-size: 14px; }
        .class-name-cell .desc { font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 250px; }

        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 8px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .badge.blue { background: rgba(0,91,159,0.1); color: var(--umak-blue); }
        .badge.green { background: #dcfce7; color: #166534; }
        .badge.orange { background: #fef3c7; color: #92400e; }
        .badge.red { background: #fee2e2; color: #b91c1c; }
        .badge.gray { background: #f3f4f6; color: #4b5563; }

        .status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .status-pill.active { background: #dcfce7; color: #166534; }
        .status-pill.inactive { background: #f3f4f6; color: #6b7280; }
        .status-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        .capacity-mini { display: flex; flex-direction: column; gap: 4px; min-width: 90px; }
        .capacity-mini-text { font-size: 12px; font-weight: 800; color: #111827; }
        .capacity-mini-bar { height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden; }
        .capacity-mini-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #2e7d32, #4ade80); transition: width 0.5s ease; }
        .capacity-mini-fill.medium { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .capacity-mini-fill.high { background: linear-gradient(90deg, #ef4444, #f87171); }

        .actions-cell { display: flex; gap: 6px; justify-content: flex-end; }
        .icon-btn { width: 32px; height: 32px; border-radius: 8px; border: none; background: #f3f4f6; color: #6b7280; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; font-size: 14px; text-decoration: none; padding: 0; }
        .icon-btn:hover { background: #e5e7eb; color: #111827; transform: scale(1.05); }
        .icon-btn.blue:hover { background: rgba(0,91,159,0.15); color: var(--umak-blue); }
        .icon-btn.green:hover { background: #dcfce7; color: #166534; }
        .icon-btn.orange:hover { background: #fef3c7; color: #92400e; }
        .icon-btn.red:hover { background: #fee2e2; color: #b91c1c; }

        .form-card { background: white; border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.03); padding: 28px 32px; max-width: 900px; margin: 0 auto; }
        .form-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 18px; border-bottom: 2px solid var(--border); }
        .form-header h2 { margin: 0; font-size: 20px; font-weight: 800; color: var(--umak-blue); display: flex; align-items: center; gap: 10px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-label { font-size: 12px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.8px; display: flex; align-items: center; gap: 6px; }
        .form-label .required { color: var(--umak-red); }
        .form-control { padding: 11px 14px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 14px; font-weight: 500; transition: all 0.2s; font-family: inherit; width: 100%; background: white; color: var(--text); }
        .form-control:focus { outline: none; border-color: var(--umak-blue); box-shadow: 0 0 0 4px rgba(0,91,159,0.1); }
        textarea.form-control { resize: vertical; min-height: 80px; }
        .form-hint { font-size: 11.5px; color: var(--text-muted); font-weight: 500; }
        .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border); }
        .checkbox-label { display: flex; align-items: center; gap: 10px; padding: 12px 14px; background: #f9fafb; border-radius: 10px; cursor: pointer; font-size: 13.5px; font-weight: 600; color: var(--text); transition: background 0.2s; }
        .checkbox-label:hover { background: #f3f4f6; }
        .checkbox-label input { width: 18px; height: 18px; accent-color: var(--umak-blue); cursor: pointer; }

        .view-header { background: linear-gradient(135deg, #005b9f, #0084d1); color: white; padding: 24px 28px; border-radius: 16px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; gap: 20px; flex-wrap: wrap; }
        .view-header h2 { margin: 0 0 6px; font-size: 22px; font-weight: 800; }
        .view-header p { margin: 0; font-size: 13.5px; opacity: 0.9; font-weight: 500; }
        .view-header-stats { display: flex; gap: 20px; flex-wrap: wrap; }
        .view-header-stat { text-align: center; }
        .view-header-stat .value { font-size: 26px; font-weight: 900; line-height: 1; }
        .view-header-stat .label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; font-weight: 700; margin-top: 4px; }

        .empty-state { text-align: center; padding: 60px 20px; background: white; border-radius: 16px; border: 2px dashed var(--border); }
        .empty-state-icon { width: 80px; height: 80px; margin: 0 auto 18px; background: rgba(0,91,159,0.08); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 36px; color: var(--umak-blue); }
        .empty-state h3 { margin: 0 0 8px; color: var(--umak-blue); font-size: 18px; font-weight: 800; }
        .empty-state p { color: var(--text-muted); font-size: 14px; margin: 0 0 20px; line-height: 1.6; max-width: 400px; margin-left: auto; margin-right: auto; }

        .status-select { padding: 6px 10px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 12px; font-weight: 700; background: white; cursor: pointer; font-family: inherit; }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
        }
        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .form-grid { grid-template-columns: 1fr; }
            .form-card { padding: 20px; }
            .view-header { padding: 18px 20px; }
            .view-header h2 { font-size: 18px; }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css">
<link rel="stylesheet" href="/PBL/css/admin-content-icons.css"></head>
<body>

<!-- SIDEBAR -->
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
        <a href="admin-classes.php" class="nav-item active">
            <i class="bi bi-calendar-event-fill"></i><span>Classes</span>
        </a>
        <a href="admin-sessions.php" class="nav-item">
            <i class="bi bi-clock-history"></i><span>Sessions</span>
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

<!-- MAIN -->
<div class="main-wrapper">
    <header class="top-header">
        <div class="header-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
            <div>
                <h2>Manage Classes</h2>
                <p class="subtitle">Create, edit, and manage class schedules</p>
            </div>
        </div>
        <div class="header-right">
            <a href="admin-notifications.php" class="header-btn" title="Notifications">
                <i class="bi bi-bell-fill"></i>
                <?php if ($unreadNotifCount > 0): ?><span class="notif-badge"><?= $unreadNotifCount ?></span><?php endif; ?>
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

        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="bi bi-calendar-event-fill"></i> Manage Classes</h1>
                <p class="page-subtitle">Full control over gym class schedules, capacity, and bookings.</p>
            </div>
            <div class="page-actions">
                <a href="admin-classes.php?tab=create" class="btn btn-primary">
                    <i class="bi bi-plus-circle-fill"></i> New Class
                </a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?>">
                <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>" style="font-size: 20px;"></i>
                <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="bi bi-calendar-event-fill"></i></div>
                <div class="stat-label">Total Classes</div>
                <div class="stat-value"><?= $stats['total'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
                <div class="stat-label">Active</div>
                <div class="stat-value"><?= $stats['active'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="bi bi-clock-history"></i></div>
                <div class="stat-label">Upcoming</div>
                <div class="stat-value"><?= $stats['upcoming'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon yellow"><i class="bi bi-sun-fill"></i></div>
                <div class="stat-label">Today</div>
                <div class="stat-value"><?= $stats['today'] ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="bi bi-people-fill"></i></div>
                <div class="stat-label">Total Bookings</div>
                <div class="stat-value"><?= $stats['bookings'] ?></div>
            </div>
        </div>

        <!-- TABS -->
        <div class="main-tabs">
            <a href="admin-classes.php?tab=list" class="main-tab <?= $activeTab === 'list' ? 'active' : '' ?>">
                <i class="bi bi-list-ul"></i> All Classes
            </a>
            <a href="admin-classes.php?tab=create" class="main-tab <?= $activeTab === 'create' ? 'active' : '' ?>">
                <i class="bi bi-plus-circle-fill"></i> Create Class
            </a>
            <?php if ($editClass): ?>
                <a href="admin-classes.php?edit=<?= (int)$editClass['id'] ?>" class="main-tab active">
                    <i class="bi bi-pencil-square"></i> Editing
                </a>
            <?php endif; ?>
            <?php if ($viewClass): ?>
                <a href="admin-classes.php?tab=bookings&class_id=<?= (int)$viewClass['id'] ?>" class="main-tab active">
                    <i class="bi bi-people-fill"></i> Bookings
                </a>
            <?php endif; ?>
        </div>

        <!-- TAB: LIST -->
        <div class="tab-content <?= ($activeTab === 'list' && !$editClass && !$viewClass) ? 'active' : '' ?>">
            <div class="toolbar">
                <form method="GET" style="padding: 0; border: none; box-shadow: none; background: none; margin: 0; flex: 1; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                    <input type="hidden" name="tab" value="list">
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" placeholder="Search classes..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <select name="category" class="filter-select" onchange="this.form.submit()">
                        <option value="all">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="all">All Status</option>
                        <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <select name="when" class="filter-select" onchange="this.form.submit()">
                        <option value="all">All Time</option>
                        <option value="today" <?= $filterWhen === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="upcoming" <?= $filterWhen === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                        <option value="past" <?= $filterWhen === 'past' ? 'selected' : '' ?>>Past</option>
                    </select>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filter</button>
                </form>
                <a href="admin-classes.php?tab=create" class="btn btn-success">
                    <i class="bi bi-plus-circle-fill"></i> New Class
                </a>
            </div>

            <?php if (empty($classes)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-calendar-x"></i></div>
                    <h3>No Classes Found</h3>
                    <p>Try adjusting your filters or create your first class.</p>
                    <a href="admin-classes.php?tab=create" class="btn btn-primary"><i class="bi bi-plus-circle-fill"></i> Create Class</a>
                </div>
            <?php else: ?>
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Class</th>
                                    <th>Date & Time</th>
                                    <th>Category</th>
                                    <th>Difficulty</th>
                                    <th>Capacity</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $c):
                                    $booked = (int)$c['booked_count'];
                                    $cap = (int)$c['capacity'];
                                    $pct = $cap > 0 ? round(($booked / $cap) * 100) : 0;
                                    $fillClass = $pct >= 90 ? 'high' : ($pct >= 70 ? 'medium' : '');
                                ?>
                                    <tr>
                                        <td>
                                            <div class="class-name-cell">
                                                <span class="name"><?= htmlspecialchars($c['name']) ?></span>
                                                <?php if (!empty($c['location'])): ?>
                                                    <span class="desc"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($c['location']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 700; color: #111827;"><?= date('M j, Y', strtotime($c['class_date'])) ?></div>
                                            <div style="font-size: 12px; color: var(--text-muted);">
                                                <?= date('g:i A', strtotime($c['start_time'])) ?> – <?= date('g:i A', strtotime($c['end_time'])) ?>
                                            </div>
                                        </td>
                                        <td><span class="badge blue"><i class="bi bi-tag-fill"></i> <?= htmlspecialchars($c['category']) ?></span></td>
                                        <td>
                                            <span class="badge <?= $c['difficulty'] === 'Beginner' ? 'green' : ($c['difficulty'] === 'Intermediate' ? 'orange' : 'red') ?>">
                                                <?= htmlspecialchars($c['difficulty']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="capacity-mini">
                                                <div class="capacity-mini-text"><?= $booked ?> / <?= $cap ?></div>
                                                <div class="capacity-mini-bar">
                                                    <div class="capacity-mini-fill <?= $fillClass ?>" style="width: <?= $pct ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-pill <?= $c['is_active'] ? 'active' : 'inactive' ?>">
                                                <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="actions-cell">
                                                <a href="admin-classes.php?tab=bookings&class_id=<?= (int)$c['id'] ?>" class="icon-btn blue" title="View Bookings">
                                                    <i class="bi bi-people-fill"></i>
                                                </a>
                                                <a href="admin-classes.php?edit=<?= (int)$c['id'] ?>" class="icon-btn green" title="Edit">
                                                    <i class="bi bi-pencil-fill"></i>
                                                </a>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <?php echo app_csrf_field(); ?>
                                                    <input type="hidden" name="class_id" value="<?= (int)$c['id'] ?>">
                                                    <button type="submit" class="icon-btn orange" title="Toggle Active">
                                                        <i class="bi bi-<?= $c['is_active'] ? 'toggle-on' : 'toggle-off' ?>"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this class? This cannot be undone.');">
                                                    <input type="hidden" name="action" value="delete_class">
                                                    <?php echo app_csrf_field(); ?>
                                                    <input type="hidden" name="class_id" value="<?= (int)$c['id'] ?>">
                                                    <button type="submit" class="icon-btn red" title="Delete">
                                                        <i class="bi bi-trash3-fill"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB: CREATE -->
        <div class="tab-content <?= $activeTab === 'create' ? 'active' : '' ?>">
            <div class="form-card">
                <div class="form-header">
                    <h2><i class="bi bi-plus-circle-fill"></i> Create New Class</h2>
                    <a href="admin-classes.php?tab=list" class="btn btn-outline"><i class="bi bi-arrow-left"></i> Back</a>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="create_class">
                    <?php echo app_csrf_field(); ?>
                    <div class="form-grid">
                        <div class="form-group full">
                            <label class="form-label"><i class="bi bi-type"></i> Class Name <span class="required">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="150" placeholder="e.g., Morning HIIT Blast">
                        </div>

                        <div class="form-group full">
                            <label class="form-label"><i class="bi bi-file-text"></i> Description</label>
                            <textarea name="description" class="form-control" maxlength="500" placeholder="Brief description of the class..."></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-calendar3"></i> Class Date <span class="required">*</span></label>
                            <input type="date" name="class_date" class="form-control" required value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-clock"></i> Start Time <span class="required">*</span></label>
                            <input type="time" name="start_time" class="form-control" required value="07:00">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-clock-fill"></i> End Time <span class="required">*</span></label>
                            <input type="time" name="end_time" class="form-control" required value="08:00">
                            <span class="form-hint">Duration auto-calculated</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-hourglass-split"></i> Duration (min)</label>
                            <input type="number" name="duration_minutes" class="form-control" value="60" min="15" max="480">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-people-fill"></i> Capacity <span class="required">*</span></label>
                            <input type="number" name="capacity" class="form-control" value="15" min="1" max="100" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-geo-alt-fill"></i> Location</label>
                            <input type="text" name="location" class="form-control" maxlength="100" placeholder="e.g., Studio A">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-speedometer"></i> Difficulty</label>
                            <select name="difficulty" class="form-control">
                                <option value="Beginner">Beginner</option>
                                <option value="Intermediate">Intermediate</option>
                                <option value="Advanced">Advanced</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-tag-fill"></i> Category</label>
                            <input type="text" name="category" class="form-control" list="catList" value="General" maxlength="50">
                            <datalist id="catList">
                                <?php foreach ($defaultCategories as $c): ?><option value="<?= $c ?>"><?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="admin-classes.php?tab=list" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle-fill"></i> Create Class</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- TAB: EDIT -->
        <?php if ($editClass): ?>
        <div class="tab-content active">
            <div class="form-card">
                <div class="form-header">
                    <h2><i class="bi bi-pencil-square"></i> Edit Class #<?= (int)$editClass['id'] ?></h2>
                    <a href="admin-classes.php?tab=list" class="btn btn-outline"><i class="bi bi-arrow-left"></i> Back</a>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_class">
                    <?php echo app_csrf_field(); ?>
                    <input type="hidden" name="class_id" value="<?= (int)$editClass['id'] ?>">

                    <div class="form-grid">
                        <div class="form-group full">
                            <label class="form-label"><i class="bi bi-type"></i> Class Name <span class="required">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="150" value="<?= htmlspecialchars($editClass['name']) ?>">
                        </div>

                        <div class="form-group full">
                            <label class="form-label"><i class="bi bi-file-text"></i> Description</label>
                            <textarea name="description" class="form-control" maxlength="500"><?= htmlspecialchars($editClass['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-calendar3"></i> Class Date <span class="required">*</span></label>
                            <input type="date" name="class_date" class="form-control" required value="<?= htmlspecialchars($editClass['class_date']) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-clock"></i> Start Time <span class="required">*</span></label>
                            <input type="time" name="start_time" class="form-control" required value="<?= htmlspecialchars(substr($editClass['start_time'], 0, 5)) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-clock-fill"></i> End Time <span class="required">*</span></label>
                            <input type="time" name="end_time" class="form-control" required value="<?= htmlspecialchars(substr($editClass['end_time'], 0, 5)) ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-hourglass-split"></i> Duration (min)</label>
                            <input type="number" name="duration_minutes" class="form-control" value="<?= (int)$editClass['duration_minutes'] ?>" min="15" max="480">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-people-fill"></i> Capacity <span class="required">*</span></label>
                            <input type="number" name="capacity" class="form-control" value="<?= (int)$editClass['capacity'] ?>" min="1" max="100" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-geo-alt-fill"></i> Location</label>
                            <input type="text" name="location" class="form-control" maxlength="100" value="<?= htmlspecialchars($editClass['location'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-speedometer"></i> Difficulty</label>
                            <select name="difficulty" class="form-control">
                                <?php foreach ($validDifficulties as $d): ?>
                                    <option value="<?= $d ?>" <?= $editClass['difficulty'] === $d ? 'selected' : '' ?>><?= $d ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="bi bi-tag-fill"></i> Category</label>
                            <input type="text" name="category" class="form-control" list="catList2" value="<?= htmlspecialchars($editClass['category']) ?>" maxlength="50">
                            <datalist id="catList2">
                                <?php foreach ($defaultCategories as $c): ?><option value="<?= $c ?>"><?php endforeach; ?>
                            </datalist>
                        </div>

                        <div class="form-group full">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active" value="1" <?= $editClass['is_active'] ? 'checked' : '' ?>>
                                <span>Active (visible to members and bookable)</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="admin-classes.php?tab=list" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle-fill"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB: BOOKINGS -->
        <?php if ($viewClass): ?>
        <div class="tab-content active">
            <div class="view-header">
                <div>
                    <h2><i class="bi bi-people-fill"></i> <?= htmlspecialchars($viewClass['name']) ?></h2>
                    <p><?= date('l, F j, Y', strtotime($viewClass['class_date'])) ?> · <?= date('g:i A', strtotime($viewClass['start_time'])) ?> – <?= date('g:i A', strtotime($viewClass['end_time'])) ?></p>
                </div>
                <div class="view-header-stats">
                    <div class="view-header-stat">
                        <div class="value"><?= count($bookings) ?></div>
                        <div class="label">Bookings</div>
                    </div>
                    <div class="view-header-stat">
                        <div class="value"><?= (int)$viewClass['capacity'] ?></div>
                        <div class="label">Capacity</div>
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 16px; display: flex; gap: 10px;">
                <a href="admin-classes.php?tab=list" class="btn btn-outline"><i class="bi bi-arrow-left"></i> Back to Classes</a>
                <a href="admin-classes.php?edit=<?= (int)$viewClass['id'] ?>" class="btn btn-primary"><i class="bi bi-pencil-fill"></i> Edit Class</a>
            </div>

            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-inbox"></i></div>
                    <h3>No Bookings Yet</h3>
                    <p>When members book this class, their info will appear here.</p>
                </div>
            <?php else: ?>
                <div class="table-card">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Email</th>
                                    <th>Booked At</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Change Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $b):
                                    $statusClass = 'gray';
                                    if ($b['status'] === 'Confirmed') $statusClass = 'green';
                                    elseif ($b['status'] === 'Waitlist') $statusClass = 'orange';
                                    elseif ($b['status'] === 'Cancelled') $statusClass = 'red';
                                ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 12px;">
                                                <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #005b9f, #0084d1); color: white; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0; overflow: hidden;">
                                                    <?php if (!empty($b['profile_image']) && file_exists(__DIR__ . '/../' . $b['profile_image'])): ?>
                                                        <img src="../<?= htmlspecialchars($b['profile_image']) ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
                                                    <?php else: ?>
                                                        <?= strtoupper(substr($b['fullname'] ?? $b['username'], 0, 1)) ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 800; color: #111827;"><?= htmlspecialchars($b['fullname'] ?? $b['username']) ?></div>
                                                    <div style="font-size: 11.5px; color: var(--text-muted);">ID: <?= (int)$b['user_id'] ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($b['email'] ?? '—') ?></td>
                                        <td>
                                            <div style="font-weight: 600;"><?= date('M j, Y', strtotime($b['booked_at'])) ?></div>
                                            <div style="font-size: 11.5px; color: var(--text-muted);"><?= date('g:i A', strtotime($b['booked_at'])) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($b['status']) ?></span>
                                        </td>
                                        <td>
                                            <div class="actions-cell">
                                                <form method="POST" style="display: inline-flex; gap: 6px; align-items: center;">
                                                    <input type="hidden" name="action" value="update_booking_status">
                                                    <?php echo app_csrf_field(); ?>
                                                    <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                                                    <input type="hidden" name="class_id" value="<?= (int)$viewClass['id'] ?>">
                                                    <select name="status" class="status-select" onchange="this.form.submit()">
                                                        <option value="Confirmed" <?= $b['status'] === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                                        <option value="Waitlist" <?= $b['status'] === 'Waitlist' ? 'selected' : '' ?>>Waitlist</option>
                                                        <option value="Attended" <?= $b['status'] === 'Attended' ? 'selected' : '' ?>>Attended</option>
                                                        <option value="No-Show" <?= $b['status'] === 'No-Show' ? 'selected' : '' ?>>No-Show</option>
                                                        <option value="Cancelled" <?= $b['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                    </select>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
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
                alert.style.transition = 'opacity 0.5s, transform 0.5s';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function() { alert.style.display = 'none'; }, 500);
            }, 4000);
        });
    });
</script>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>