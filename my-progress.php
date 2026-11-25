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

function columnExists($conn, $tableName, $columnName) {
    $tableName = $conn->real_escape_string($tableName);
    $columnName = $conn->real_escape_string($columnName);
    $result = $conn->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
    return $result && $result->num_rows > 0;
}

// ============================================
// CREATE NOTIFICATION HELPER (PHP)
// ============================================
function createNotification($conn, $userId, $title, $message, $type = 'general', $link = null) {
    try {
        if (!tableExists($conn, 'notifications')) return false;
        
        $hasLink = columnExists($conn, 'notifications', 'link');
        
        if ($hasLink) {
            $stmt = $conn->prepare(
                "INSERT INTO notifications (user_id, title, message, type, link, created_at) 
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param("issss", $userId, $title, $message, $type, $link);
                $result = $stmt->execute();
                $stmt->close();
                return $result;
            }
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO notifications (user_id, title, message, type, created_at) 
                 VALUES (?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param("isss", $userId, $title, $message, $type);
                $result = $stmt->execute();
                $stmt->close();
                return $result;
            }
        }
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
    }
    return false;
}

// ============================================
// HANDLE ADD MEASUREMENT
// ============================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_message'] = 'This form has expired or is invalid. Please refresh the page and try again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: my-progress.php');
        exit;
    }

    if ($_POST['action'] === 'add_measurement') {
        $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
        $bodyFat = !empty($_POST['body_fat']) ? (float)$_POST['body_fat'] : null;
        $chest = !empty($_POST['chest']) ? (float)$_POST['chest'] : null;
        $waist = !empty($_POST['waist']) ? (float)$_POST['waist'] : null;
        $hips = !empty($_POST['hips']) ? (float)$_POST['hips'] : null;
        $arms = !empty($_POST['arms']) ? (float)$_POST['arms'] : null;
        $thighs = !empty($_POST['thighs']) ? (float)$_POST['thighs'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $measureDate = $_POST['measure_date'] ?? date('Y-m-d');

        if (tableExists($conn, 'body_measurements')) {
            $stmt = $conn->prepare(
                "INSERT INTO body_measurements 
                 (user_id, weight, body_fat, chest, waist, hips, arms, thighs, notes, measure_date, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param("idddddddss",
                    $userId, $weight, $bodyFat, $chest, $waist, $hips, $arms, $thighs, $notes, $measureDate
                );
                if ($stmt->execute()) {
                    $message = 'Measurement recorded successfully! 📊';
                    $messageType = 'success';
                    
                    // Update user's weight if weight was provided
                    if ($weight !== null) {
                        $updateStmt = $conn->prepare("UPDATE users SET weight = ? WHERE id = ?");
                        if ($updateStmt) {
                            $updateStmt->bind_param("di", $weight, $userId);
                            $updateStmt->execute();
                            $updateStmt->close();
                        }
                    }
                    
                    // Create notification
                    createNotification(
                        $conn, 
                        $userId, 
                        'Measurement Recorded', 
                        'Your body measurements have been saved successfully.', 
                        'general',
                        'my-progress.php'
                    );
                } else {
                    $message = 'Failed to save measurement.';
                    $messageType = 'error';
                }
                $stmt->close();
            }
        } else {
            $message = 'Measurements table not set up yet.';
            $messageType = 'error';
        }
    }
}

// ============================================
// FETCH USER DATA (including height/weight)
// ============================================
$userData = null;
$stmtUser = $conn->prepare("SELECT id, fullname, username, email, profile_image, height, weight, fitness_goal, gender FROM users WHERE id = ? LIMIT 1");
if ($stmtUser) {
    $stmtUser->bind_param("i", $userId);
    $stmtUser->execute();
    $userData = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();
}

$displayName = $userData['fullname'] ?? $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'User';
$firstName = explode(' ', trim($displayName))[0];
$userInitial = strtoupper(substr($displayName, 0, 1));

// Get user's height (default to 170cm if not set)
$userHeight = null;
if ($userData && !empty($userData['height'])) {
    $userHeight = (float)$userData['height']; // in cm
} 

// ============================================
// FETCH UNREAD NOTIFICATIONS
// ============================================
$unreadNotifCount = 0;
if (tableExists($conn, 'notifications')) {
    $result = safeQuery($conn,
        "SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $unreadNotifCount = (int)$row['total'];
    }
}

// ============================================
// FETCH BODY MEASUREMENTS
// ============================================
$measurements = [];

if (tableExists($conn, 'body_measurements')) {
    $result = safeQuery($conn,
        "SELECT id, weight, body_fat, chest, waist, hips, arms, thighs, notes, measure_date
         FROM body_measurements
         WHERE user_id = ?
         ORDER BY measure_date ASC",
        "i", [$userId]
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $measurements[] = $row;
        }
    }
}

// Latest measurement
$latest = !empty($measurements) ? end($measurements) : null;
$previous = count($measurements) >= 2 ? $measurements[count($measurements) - 2] : null;

// Calculate changes
function calcChange($current, $previous, $unit = '') {
    if ($current === null || $previous === null || $previous == 0) return null;
    $diff = $current - $previous;
    if (abs($diff) < 0.01) return null;
    return [
        'value' => round($diff, 1),
        'direction' => $diff > 0 ? 'up' : 'down',
        'unit' => $unit,
    ];
}

$weightChange = $latest && $previous ? calcChange($latest['weight'], $previous['weight'], 'kg') : null;
$bodyFatChange = $latest && $previous ? calcChange($latest['body_fat'], $previous['body_fat'], '%') : null;
$waistChange = $latest && $previous ? calcChange($latest['waist'], $previous['waist'], 'cm') : null;

// Starting measurements (first record)
$starting = !empty($measurements) ? $measurements[0] : null;
$totalWeightChange = $latest && $starting ? calcChange($latest['weight'], $starting['weight'], 'kg') : null;

// ============================================
// FETCH WEIGHT GOAL
// ============================================
$weightGoal = null;
if (tableExists($conn, 'fitness_goals')) {
    $result = safeQuery($conn,
        "SELECT * FROM fitness_goals 
         WHERE user_id = ? AND goal_type = 'weight' AND is_active = 1 
         ORDER BY created_at DESC LIMIT 1",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $weightGoal = $row;
    }
}

// Fallback: derive from measurements if no explicit goal
if (!$weightGoal && $latest && $starting && $starting['weight'] > 0) {
    $weightGoal = [
        'start_value' => (float)$starting['weight'],
        'target_value' => max(30, (float)$starting['weight'] - 5), // default -5kg, min 30kg
        'current_value' => (float)$latest['weight'],
    ];
}

// Calculate goal progress
$goalProgress = 0;
$goalRemaining = 0;
$goalStart = 0;
$goalTarget = 0;

if ($weightGoal && !empty($weightGoal['start_value']) && !empty($weightGoal['target_value'])) {
    $goalStart = (float)$weightGoal['start_value'];
    $goalTarget = (float)$weightGoal['target_value'];
    $current = (float)($latest['weight'] ?? $goalStart);

    $totalDiff = abs($goalStart - $goalTarget);
    $achievedDiff = abs($goalStart - $current);

    if ($totalDiff > 0) {
        $goalProgress = min(100, round(($achievedDiff / $totalDiff) * 100));
    }
    $goalRemaining = round(abs($current - $goalTarget), 1);
}

// ============================================
// CALCULATE BMI (using user's actual height)
// ============================================
$bmi = null;
$bmiCategory = '';
$bmiCategoryColor = '';
$heightM = null;

// Use user's height from profile, fallback to 1.70m if not set
if ($userHeight && $userHeight > 0) {
    $heightM = $userHeight / 100; // convert cm to meters
} else {
    $heightM = 1.70; // default 170cm
}

if ($latest && !empty($latest['weight']) && $heightM > 0) {
    $bmi = round($latest['weight'] / ($heightM * $heightM), 1);
    if ($bmi < 18.5) {
        $bmiCategory = 'Underweight';
        $bmiCategoryColor = '#f59e0b';
    } elseif ($bmi < 25) {
        $bmiCategory = 'Normal';
        $bmiCategoryColor = '#10b981';
    } elseif ($bmi < 30) {
        $bmiCategory = 'Overweight';
        $bmiCategoryColor = '#f97316';
    } else {
        $bmiCategory = 'Obese';
        $bmiCategoryColor = '#ef4444';
    }
}

// ============================================
// FETCH WORKOUT STATS
// ============================================
$workoutsThisMonth = 0;
$workoutsThisWeek = 0;
$totalWorkouts = 0;

if (tableExists($conn, 'workout_completions')) {
    $result = safeQuery($conn,
        "SELECT COUNT(DISTINCT completion_date) AS total 
         FROM workout_completions 
         WHERE user_id = ? AND MONTH(completion_date) = MONTH(CURDATE()) 
         AND YEAR(completion_date) = YEAR(CURDATE())",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) $workoutsThisMonth = (int)$row['total'];

    $result = safeQuery($conn,
        "SELECT COUNT(DISTINCT completion_date) AS total 
         FROM workout_completions 
         WHERE user_id = ? AND YEARWEEK(completion_date, 1) = YEARWEEK(CURDATE(), 1)",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) $workoutsThisWeek = (int)$row['total'];

    $result = safeQuery($conn,
        "SELECT COUNT(DISTINCT completion_date) AS total 
         FROM workout_completions WHERE user_id = ?",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) $totalWorkouts = (int)$row['total'];
}

// ============================================
// FETCH ATTENDANCE STATS
// ============================================
$monthlyVisits = 0;
if (tableExists($conn, 'attendance')) {
    $result = safeQuery($conn,
        "SELECT COUNT(*) AS total FROM attendance 
         WHERE user_id = ? AND MONTH(check_in_time) = MONTH(CURDATE()) 
         AND YEAR(check_in_time) = YEAR(CURDATE())",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) $monthlyVisits = (int)$row['total'];
}

// ============================================
// PREPARE CHART DATA
// ============================================
$chartLabels = [];
$chartWeights = [];
$chartBodyFat = [];
$chartWaist = [];

foreach (array_slice($measurements, -12) as $m) {
    $chartLabels[] = date('M j', strtotime($m['measure_date']));
    $chartWeights[] = $m['weight'] !== null ? (float)$m['weight'] : null;
    $chartBodyFat[] = $m['body_fat'] !== null ? (float)$m['body_fat'] : null;
    $chartWaist[] = $m['waist'] !== null ? (float)$m['waist'] : null;
}

$hasChartData = count($chartLabels) >= 2;

// ============================================
// ACHIEVEMENTS
// ============================================
$achievements = [];

if ($totalWorkouts >= 1) {
    $achievements[] = ['icon' => 'bi-flag-fill', 'name' => 'First Workout', 'desc' => 'Completed your first workout', 'color' => '#10b981'];
}
if ($totalWorkouts >= 10) {
    $achievements[] = ['icon' => 'bi-lightning-charge-fill', 'name' => 'Consistent', 'desc' => '10 workouts completed', 'color' => '#f59e0b'];
}
if ($totalWorkouts >= 50) {
    $achievements[] = ['icon' => 'bi-trophy-fill', 'name' => 'Dedicated', 'desc' => '50 workouts completed', 'color' => '#8b5cf6'];
}
if ($monthlyVisits >= 12) {
    $achievements[] = ['icon' => 'bi-fire', 'name' => 'On Fire', 'desc' => '12+ visits this month', 'color' => '#ef4444'];
}
if (count($measurements) >= 5) {
    $achievements[] = ['icon' => 'bi-graph-up-arrow', 'name' => 'Tracker', 'desc' => '5+ measurements logged', 'color' => '#005b9f'];
}
if ($goalProgress >= 50) {
    $achievements[] = ['icon' => 'bi-star-fill', 'name' => 'Halfway There', 'desc' => '50% of goal achieved', 'color' => '#ffce00'];
}
if ($goalProgress >= 100) {
    $achievements[] = ['icon' => 'bi-award-fill', 'name' => 'Goal Crusher', 'desc' => 'Reached your goal!', 'color' => '#10b981'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Progress - UMak Gym: Fitsense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            background-clip: text;
        }

        .page-subtitle { color: var(--text-muted); font-size: 14px; margin-bottom: 28px; }

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
        .alert-error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border-left: 4px solid var(--umak-red); }

        /* ============================================
           STATS ROW
           ============================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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
        .stat-box-icon.orange { background: linear-gradient(135deg, rgba(245,158,11,0.12), rgba(251,191,36,0.18)); color: #b45309; }
        .stat-box-icon.purple { background: linear-gradient(135deg, rgba(139,92,246,0.12), rgba(167,139,250,0.18)); color: #7c3aed; }

        .stat-box-info { flex: 1; min-width: 0; }
        .stat-box-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .stat-box-value { font-size: 24px; font-weight: 800; color: #111827; line-height: 1.1; letter-spacing: -0.5px; display: flex; align-items: baseline; gap: 6px; }
        .stat-box-value .unit { font-size: 13px; font-weight: 600; color: var(--text-muted); }
        .stat-box-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; gap: 4px; }

        .trend-up { color: #ef4444; font-weight: 800; }
        .trend-down { color: #10b981; font-weight: 800; }
        .trend-neutral { color: var(--text-muted); font-weight: 700; }

        /* ============================================
           GOAL CARD
           ============================================ */
        .goal-card {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 20px;
            padding: 30px;
            color: white;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,91,159,0.2);
        }

        .goal-card::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(255,206,0,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }

        .goal-card::after {
            content: '';
            position: absolute;
            bottom: -60px; left: -60px;
            width: 180px; height: 180px;
            background: radial-gradient(circle, rgba(255,255,255,0.06) 0%, transparent 70%);
            border-radius: 50%;
        }

        .goal-content { position: relative; z-index: 2; }

        .goal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .goal-header-left {
            display: flex; align-items: center; gap: 14px;
        }

        .goal-icon {
            width: 56px; height: 56px;
            border-radius: 16px;
            background: rgba(255,206,0,0.2);
            color: var(--umak-yellow);
            display: flex; align-items: center; justify-content: center;
            font-size: 26px;
        }

        .goal-header h3 {
            margin: 0 0 4px;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.3px;
        }

        .goal-header p {
            margin: 0;
            font-size: 13px;
            color: rgba(255,255,255,0.7);
        }

        .goal-percentage {
            text-align: right;
        }

        .goal-percentage .big {
            font-size: 42px;
            font-weight: 800;
            color: var(--umak-yellow);
            line-height: 1;
            letter-spacing: -1.5px;
        }

        .goal-percentage .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.7);
            font-weight: 700;
            margin-top: 4px;
        }

        .goal-progress-track {
            height: 14px;
            background: rgba(255,255,255,0.15);
            border-radius: 7px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .goal-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #ffce00, #ffb800);
            border-radius: 7px;
            width: 0%;
            transition: width 1s ease;
            position: relative;
        }

        .goal-progress-fill::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .goal-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
        }

        .goal-stat {
            padding: 14px;
            background: rgba(255,255,255,0.08);
            border-radius: 12px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .goal-stat-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.7);
            font-weight: 700;
            margin-bottom: 6px;
        }

        .goal-stat-value {
            font-size: 20px;
            font-weight: 800;
            color: white;
            letter-spacing: -0.5px;
        }

        .goal-stat-value .unit {
            font-size: 12px;
            font-weight: 600;
            color: rgba(255,255,255,0.7);
            margin-left: 3px;
        }

        /* ============================================
           CHART CARDS
           ============================================ */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .chart-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 26px 28px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }

        .chart-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
            gap: 12px;
            flex-wrap: wrap;
        }

        .chart-card-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-legend {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .chart-legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }

        .chart-legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* BMI Card */
        .bmi-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 26px 28px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }

        .bmi-header {
            margin-bottom: 20px;
        }

        .bmi-header h3 {
            margin: 0 0 4px;
            font-size: 16px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bmi-header p {
            margin: 0;
            font-size: 12.5px;
            color: var(--text-muted);
        }

        .bmi-display {
            text-align: center;
            margin-bottom: 20px;
        }

        .bmi-value {
            font-size: 56px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -2px;
            margin-bottom: 8px;
        }

        .bmi-category {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .bmi-scale {
            margin-top: 25px;
        }

        .bmi-scale-bar {
            height: 10px;
            border-radius: 5px;
            background: linear-gradient(90deg,
                #f59e0b 0%, #f59e0b 18.5%,
                #10b981 18.5%, #10b981 25%,
                #f97316 25%, #f97316 30%,
                #ef4444 30%, #ef4444 100%);
            position: relative;
            margin-bottom: 8px;
        }

        .bmi-scale-marker {
            position: absolute;
            top: -5px;
            width: 4px;
            height: 20px;
            background: #111827;
            border-radius: 2px;
            transform: translateX(-50%);
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
        }

        .bmi-scale-labels {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 700;
        }

        .bmi-height-note {
            margin-top: 15px;
            padding: 10px 14px;
            background: #f9fafb;
            border-radius: 10px;
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--border);
        }

        /* ============================================
           MEASUREMENTS GRID
           ============================================ */
        .measurements-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 26px 28px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
        }

        .measurements-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
        }

        .measurements-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-add-measure {
            padding: 10px 20px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(0,91,159,0.25);
        }

        .btn-add-measure:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,91,159,0.35);
        }

        .measurements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px;
        }

        .measurement-tile {
            padding: 18px;
            border-radius: 14px;
            background: #f9fafb;
            border: 1px solid var(--border);
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .measurement-tile:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
            border-color: rgba(0,91,159,0.2);
        }

        .measurement-tile-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .measurement-tile-label i { font-size: 13px; }

        .measurement-tile-value {
            font-size: 24px;
            font-weight: 800;
            color: #111827;
            line-height: 1.1;
            letter-spacing: -0.5px;
            display: flex;
            align-items: baseline;
            gap: 4px;
        }

        .measurement-tile-value .unit {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .measurement-tile-change {
            font-size: 11.5px;
            font-weight: 700;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .measurement-tile-icon {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            opacity: 0.6;
        }

        .tile-weight { border-left: 4px solid #005b9f; }
        .tile-weight .measurement-tile-icon { background: rgba(0,91,159,0.12); color: #005b9f; }
        .tile-bodyfat { border-left: 4px solid #ef4444; }
        .tile-bodyfat .measurement-tile-icon { background: rgba(239,68,68,0.12); color: #ef4444; }
        .tile-chest { border-left: 4px solid #8b5cf6; }
        .tile-chest .measurement-tile-icon { background: rgba(139,92,246,0.12); color: #8b5cf6; }
        .tile-waist { border-left: 4px solid #f59e0b; }
        .tile-waist .measurement-tile-icon { background: rgba(245,158,11,0.12); color: #f59e0b; }
        .tile-hips { border-left: 4px solid #ec4899; }
        .tile-hips .measurement-tile-icon { background: rgba(236,72,153,0.12); color: #ec4899; }
        .tile-arms { border-left: 4px solid #10b981; }
        .tile-arms .measurement-tile-icon { background: rgba(16,185,129,0.12); color: #10b981; }
        .tile-thighs { border-left: 4px solid #06b6d4; }
        .tile-thighs .measurement-tile-icon { background: rgba(6,182,212,0.12); color: #06b6d4; }

        /* ============================================
           ACHIEVEMENTS
           ============================================ */
        .achievements-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 26px 28px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
        }

        .achievements-header {
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
        }

        .achievements-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .achievements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 14px;
        }

        .achievement {
            padding: 18px;
            border-radius: 14px;
            background: linear-gradient(135deg, #f9fafb, #ffffff);
            border: 2px solid var(--border);
            text-align: center;
            transition: all 0.25s ease;
        }

        .achievement:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        .achievement-icon {
            width: 50px;
            height: 50px;
            margin: 0 auto 10px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .achievement-name {
            font-size: 13px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 4px;
        }

        .achievement-desc {
            font-size: 11px;
            color: var(--text-muted);
            line-height: 1.4;
        }

        /* ============================================
           MEASUREMENT HISTORY TABLE
           ============================================ */
        .history-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 26px 28px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
        }

        .history-header {
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .history-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }

        .history-table th,
        .history-table td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .history-table th {
            color: var(--text-muted);
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: #f9fafb;
        }

        .history-table tbody tr { transition: background 0.2s; }
        .history-table tbody tr:hover { background: #f9fafb; }

        .history-table tbody tr:first-child {
            background: rgba(0,91,159,0.04);
            font-weight: 600;
        }

        /* ============================================
           MODAL
           ============================================ */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 2000;
            padding: 20px;
        }

        .modal-overlay.open {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: white;
            border-radius: 20px;
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: transform 0.3s ease;
            box-shadow: 0 25px 70px rgba(0,0,0,0.3);
        }

        .modal-overlay.open .modal { transform: scale(1); }

        .modal-header {
            padding: 22px 28px;
            border-bottom: 2px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 2;
            border-radius: 20px 20px 0 0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: #f3f4f6;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: var(--text-muted);
            transition: all 0.2s;
        }

        .modal-close:hover { background: #e5e7eb; transform: rotate(90deg); }

        .modal-body { padding: 24px 28px; }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
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

        .form-control {
            padding: 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            font-family: inherit;
            width: 100%;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--umak-blue);
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        .input-with-unit {
            position: relative;
        }

        .input-with-unit .unit-badge {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            background: #f3f4f6;
            padding: 3px 8px;
            border-radius: 6px;
            pointer-events: none;
        }

        .input-with-unit .form-control { padding-right: 55px; }

        .modal-footer {
            padding: 18px 28px 24px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            border-top: 1px solid var(--border);
        }

        .btn-cancel {
            padding: 12px 24px;
            background: #f3f4f6;
            color: var(--text-muted);
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-cancel:hover { background: #e5e7eb; }

        .btn-save {
            padding: 12px 28px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0,91,159,0.3);
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,91,159,0.4);
        }

        /* ============================================
           EMPTY STATE
           ============================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            width: 90px;
            height: 90px;
            margin: 0 auto 20px;
            background: rgba(0,91,159,0.08);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            color: var(--umak-blue);
        }

        .empty-state h3 {
            margin: 0 0 10px;
            color: var(--umak-blue);
            font-size: 20px;
            font-weight: 800;
        }

        .empty-state p {
            color: var(--text-muted);
            font-size: 14px;
            margin: 0 0 24px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
            .charts-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .btn-chat-open span.label { display: none; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .goal-card { padding: 22px; }
            .goal-header h3 { font-size: 18px; }
            .goal-percentage .big { font-size: 32px; }
            .chart-card, .bmi-card, .measurements-card, .achievements-card, .history-card { padding: 20px 18px; }
            .bmi-value { font-size: 44px; }
            .form-grid { grid-template-columns: 1fr; }
            .history-table { font-size: 12px; }
            .history-table th, .history-table td { padding: 10px 6px; }
        }

        @media (max-width: 576px) {
            .stats-row { grid-template-columns: 1fr; }
            .goal-stats { grid-template-columns: 1fr 1fr; }
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
        <a href="my-progress.php" class="nav-item active">
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
                <h2>My Progress</h2>
                <p class="subtitle">Track your fitness journey over time</p>
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

        <h1 class="page-title">
            <i class="bi bi-graph-up-arrow"></i>
            My Progress
        </h1>
        <p class="page-subtitle">Monitor your body measurements, track your fitness goals, and celebrate your achievements.</p>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>" style="font-size: 20px;"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- ============================================
             STATS ROW
             ============================================ -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-box-icon blue">
                    <i class="bi bi-speedometer2"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Current Weight</div>
                    <div class="stat-box-value">
                        <?= $latest && $latest['weight'] ? number_format($latest['weight'], 1) : '—' ?>
                        <?php if ($latest && $latest['weight']): ?><span class="unit">kg</span><?php endif; ?>
                    </div>
                    <?php if ($totalWeightChange): ?>
                        <div class="stat-box-sub">
                            <i class="bi bi-arrow-<?= $totalWeightChange['direction'] === 'up' ? 'up' : 'down' ?>-right"></i>
                            <span class="trend-<?= $totalWeightChange['direction'] === 'up' ? 'up' : 'down' ?>">
                                <?= ($totalWeightChange['value'] > 0 ? '+' : '') . $totalWeightChange['value'] ?> kg
                            </span>
                            from start
                        </div>
                    <?php else: ?>
                        <div class="stat-box-sub">Baseline record</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon orange">
                    <i class="bi bi-fire"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Body Fat</div>
                    <div class="stat-box-value">
                        <?= $latest && $latest['body_fat'] ? number_format($latest['body_fat'], 1) : '—' ?>
                        <?php if ($latest && $latest['body_fat']): ?><span class="unit">%</span><?php endif; ?>
                    </div>
                    <?php if ($bodyFatChange): ?>
                        <div class="stat-box-sub">
                            <i class="bi bi-arrow-<?= $bodyFatChange['direction'] === 'up' ? 'up' : 'down' ?>-right"></i>
                            <span class="trend-<?= $bodyFatChange['direction'] === 'up' ? 'up' : 'down' ?>">
                                <?= ($bodyFatChange['value'] > 0 ? '+' : '') . $bodyFatChange['value'] ?>%
                            </span>
                            vs last
                        </div>
                    <?php else: ?>
                        <div class="stat-box-sub">Latest reading</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon green">
                    <i class="bi bi-activity"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Workouts</div>
                    <div class="stat-box-value"><?= $workoutsThisMonth ?><span class="unit">this month</span></div>
                    <div class="stat-box-sub">
                        <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                        <?= $totalWorkouts ?> total completed
                    </div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon purple">
                    <i class="bi bi-trophy-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Achievements</div>
                    <div class="stat-box-value"><?= count($achievements) ?></div>
                    <div class="stat-box-sub">badges earned</div>
                </div>
            </div>
        </div>

        <!-- ============================================
             GOAL CARD
             ============================================ -->
        <?php if ($weightGoal && $latest && $latest['weight']): ?>
        <div class="goal-card">
            <div class="goal-content">
                <div class="goal-header">
                    <div class="goal-header-left">
                        <div class="goal-icon">
                            <i class="bi bi-bullseye"></i>
                        </div>
                        <div>
                            <h3>Weight Goal Progress</h3>
                            <p>
                                From <?= number_format($goalStart, 1) ?> kg
                                → Target <?= number_format($goalTarget, 1) ?> kg
                            </p>
                        </div>
                    </div>
                    <div class="goal-percentage">
                        <div class="big"><?= $goalProgress ?>%</div>
                        <div class="label">Complete</div>
                    </div>
                </div>

                <div class="goal-progress-track">
                    <div class="goal-progress-fill" id="goalFill" style="width: 0%" data-target="<?= $goalProgress ?>%"></div>
                </div>

                <div class="goal-stats">
                    <div class="goal-stat">
                        <div class="goal-stat-label">Starting</div>
                        <div class="goal-stat-value">
                            <?= number_format($goalStart, 1) ?>
                            <span class="unit">kg</span>
                        </div>
                    </div>
                    <div class="goal-stat">
                        <div class="goal-stat-label">Current</div>
                        <div class="goal-stat-value">
                            <?= number_format($latest['weight'], 1) ?>
                            <span class="unit">kg</span>
                        </div>
                    </div>
                    <div class="goal-stat">
                        <div class="goal-stat-label">Target</div>
                        <div class="goal-stat-value">
                            <?= number_format($goalTarget, 1) ?>
                            <span class="unit">kg</span>
                        </div>
                    </div>
                    <div class="goal-stat">
                        <div class="goal-stat-label">Remaining</div>
                        <div class="goal-stat-value">
                            <?= $goalRemaining ?>
                            <span class="unit">kg to go</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================
             CHARTS
             ============================================ -->
        <div class="charts-grid">
            <!-- Weight Chart -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <h3><i class="bi bi-graph-up"></i> Weight Progress</h3>
                    <div class="chart-legend">
                        <div class="chart-legend-item">
                            <span class="chart-legend-dot" style="background: #005b9f;"></span>
                            Weight (kg)
                        </div>
                    </div>
                </div>
                <div class="chart-container">
                    <?php if ($hasChartData): ?>
                        <canvas id="weightChart"></canvas>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 30px 20px;">
                            <div class="empty-state-icon" style="width: 60px; height: 60px; font-size: 26px;">
                                <i class="bi bi-graph-up"></i>
                            </div>
                            <h3 style="font-size: 15px;">Not Enough Data</h3>
                            <p style="font-size: 13px; margin-bottom: 0;">Log at least 2 measurements to see your chart</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- BMI Card -->
            <div class="bmi-card">
                <div class="bmi-header">
                    <h3><i class="bi bi-calculator"></i> Body Mass Index</h3>
                    <p>Based on your latest weight and height</p>
                </div>

                <?php if ($bmi): ?>
                    <div class="bmi-display">
                        <div class="bmi-value" style="color: <?= $bmiCategoryColor ?>;">
                            <?= $bmi ?>
                        </div>
                        <div class="bmi-category" style="background: <?= $bmiCategoryColor ?>20; color: <?= $bmiCategoryColor ?>;">
                            <?= $bmiCategory ?>
                        </div>
                    </div>

                    <div class="bmi-scale">
                        <div class="bmi-scale-bar">
                            <div class="bmi-scale-marker" style="left: <?= min(100, max(0, ($bmi / 40) * 100)) ?>%;"></div>
                        </div>
                        <div class="bmi-scale-labels">
                            <span>15</span>
                            <span>18.5</span>
                            <span>25</span>
                            <span>30</span>
                            <span>40</span>
                        </div>
                    </div>

                    <div class="bmi-height-note">
                        <i class="bi bi-rulers" style="color: var(--umak-blue);"></i>
                        <span>
                            Height used: <strong><?= number_format($userHeight ?: 170, 0) ?> cm</strong>
                            <?php if (!$userHeight): ?>
                                <a href="my-profile.php" style="color: var(--umak-blue); font-weight: 700;">(Update profile)</a>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding: 30px 20px;">
                        <p style="font-size: 13px; margin: 0;">No weight data available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================
             BODY MEASUREMENTS
             ============================================ -->
        <div class="measurements-card">
            <div class="measurements-header">
                <h3><i class="bi bi-rulers"></i> Body Measurements</h3>
                <button class="btn-add-measure" onclick="openMeasureModal()">
                    <i class="bi bi-plus-circle-fill"></i> Add Measurement
                </button>
            </div>

            <?php if ($latest): ?>
                <div class="measurements-grid">
                    <div class="measurement-tile tile-weight">
                        <div class="measurement-tile-icon"><i class="bi bi-speedometer2"></i></div>
                        <div class="measurement-tile-label">Weight</div>
                        <div class="measurement-tile-value">
                            <?= $latest['weight'] ? number_format($latest['weight'], 1) : '—' ?>
                            <?php if ($latest['weight']): ?><span class="unit">kg</span><?php endif; ?>
                        </div>
                        <?php if ($weightChange): ?>
                            <div class="measurement-tile-change trend-<?= $weightChange['direction'] === 'up' ? 'up' : 'down' ?>">
                                <i class="bi bi-arrow-<?= $weightChange['direction'] === 'up' ? 'up' : 'down' ?>-right"></i>
                                <?= ($weightChange['value'] > 0 ? '+' : '') . $weightChange['value'] ?> kg
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="measurement-tile tile-bodyfat">
                        <div class="measurement-tile-icon"><i class="bi bi-fire"></i></div>
                        <div class="measurement-tile-label">Body Fat</div>
                        <div class="measurement-tile-value">
                            <?= $latest['body_fat'] ? number_format($latest['body_fat'], 1) : '—' ?>
                            <?php if ($latest['body_fat']): ?><span class="unit">%</span><?php endif; ?>
                        </div>
                        <?php if ($bodyFatChange): ?>
                            <div class="measurement-tile-change trend-<?= $bodyFatChange['direction'] === 'up' ? 'up' : 'down' ?>">
                                <i class="bi bi-arrow-<?= $bodyFatChange['direction'] === 'up' ? 'up' : 'down' ?>-right"></i>
                                <?= ($bodyFatChange['value'] > 0 ? '+' : '') . $bodyFatChange['value'] ?>%
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="measurement-tile tile-chest">
                        <div class="measurement-tile-icon"><i class="bi bi-heart"></i></div>
                        <div class="measurement-tile-label">Chest</div>
                        <div class="measurement-tile-value">
                            <?= $latest['chest'] ? number_format($latest['chest'], 1) : '—' ?>
                            <?php if ($latest['chest']): ?><span class="unit">cm</span><?php endif; ?>
                        </div>
                    </div>

                    <div class="measurement-tile tile-waist">
                        <div class="measurement-tile-icon"><i class="bi bi-circle"></i></div>
                        <div class="measurement-tile-label">Waist</div>
                        <div class="measurement-tile-value">
                            <?= $latest['waist'] ? number_format($latest['waist'], 1) : '—' ?>
                            <?php if ($latest['waist']): ?><span class="unit">cm</span><?php endif; ?>
                        </div>
                        <?php if ($waistChange): ?>
                            <div class="measurement-tile-change trend-<?= $waistChange['direction'] === 'up' ? 'up' : 'down' ?>">
                                <i class="bi bi-arrow-<?= $waistChange['direction'] === 'up' ? 'up' : 'down' ?>-right"></i>
                                <?= ($waistChange['value'] > 0 ? '+' : '') . $waistChange['value'] ?> cm
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="measurement-tile tile-hips">
                        <div class="measurement-tile-icon"><i class="bi bi-circle-fill"></i></div>
                        <div class="measurement-tile-label">Hips</div>
                        <div class="measurement-tile-value">
                            <?= $latest['hips'] ? number_format($latest['hips'], 1) : '—' ?>
                            <?php if ($latest['hips']): ?><span class="unit">cm</span><?php endif; ?>
                        </div>
                    </div>

                    <div class="measurement-tile tile-arms">
                        <div class="measurement-tile-icon"><i class="bi bi-hand-index-thumb"></i></div>
                        <div class="measurement-tile-label">Arms</div>
                        <div class="measurement-tile-value">
                            <?= $latest['arms'] ? number_format($latest['arms'], 1) : '—' ?>
                            <?php if ($latest['arms']): ?><span class="unit">cm</span><?php endif; ?>
                        </div>
                    </div>

                    <div class="measurement-tile tile-thighs">
                        <div class="measurement-tile-icon"><i class="bi bi-universal-access"></i></div>
                        <div class="measurement-tile-label">Thighs</div>
                        <div class="measurement-tile-value">
                            <?= $latest['thighs'] ? number_format($latest['thighs'], 1) : '—' ?>
                            <?php if ($latest['thighs']): ?><span class="unit">cm</span><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-rulers"></i>
                    </div>
                    <h3>No Measurements Yet</h3>
                    <p>Start tracking your body measurements to see your progress over time!</p>
                    <button class="btn-add-measure" onclick="openMeasureModal()">
                        <i class="bi bi-plus-circle-fill"></i> Add First Measurement
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================
             ACHIEVEMENTS
             ============================================ -->
        <?php if (!empty($achievements)): ?>
        <div class="achievements-card">
            <div class="achievements-header">
                <h3><i class="bi bi-trophy-fill" style="color: var(--umak-yellow);"></i> Achievements</h3>
            </div>
            <div class="achievements-grid">
                <?php foreach ($achievements as $achievement): ?>
                    <div class="achievement">
                        <div class="achievement-icon" style="background: <?= $achievement['color'] ?>;">
                            <i class="bi <?= $achievement['icon'] ?>"></i>
                        </div>
                        <div class="achievement-name"><?= htmlspecialchars($achievement['name']) ?></div>
                        <div class="achievement-desc"><?= htmlspecialchars($achievement['desc']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================
             MEASUREMENT HISTORY
             ============================================ -->
        <?php if (count($measurements) > 1): ?>
        <div class="history-card">
            <div class="history-header">
                <h3><i class="bi bi-clock-history"></i> Measurement History</h3>
                <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;">
                    <?= count($measurements) ?> record<?= count($measurements) != 1 ? 's' : '' ?>
                </span>
            </div>
            <div style="overflow-x: auto;">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Weight</th>
                            <th>Body Fat</th>
                            <th>Waist</th>
                            <th>Chest</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($measurements) as $m): ?>
                            <tr>
                                <td><strong><?= date('M d, Y', strtotime($m['measure_date'])) ?></strong></td>
                                <td><?= $m['weight'] ? number_format($m['weight'], 1) . ' kg' : '—' ?></td>
                                <td><?= $m['body_fat'] ? number_format($m['body_fat'], 1) . '%' : '—' ?></td>
                                <td><?= $m['waist'] ? number_format($m['waist'], 1) . ' cm' : '—' ?></td>
                                <td><?= $m['chest'] ? number_format($m['chest'], 1) . ' cm' : '—' ?></td>
                                <td style="color: var(--text-muted); font-size: 12.5px;"><?= htmlspecialchars($m['notes'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<!-- ============================================
     MEASUREMENT MODAL
     ============================================ -->
<div class="modal-overlay" id="measureModal" onclick="closeMeasureModal(event)">
    <div class="modal" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3><i class="bi bi-plus-circle-fill"></i> Add Measurement</h3>
            <button class="modal-close" onclick="closeMeasureModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="add_measurement">
            <?php echo app_csrf_field(); ?>

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label"><i class="bi bi-calendar3"></i> Date</label>
                        <input type="date" name="measure_date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="bi bi-speedometer2"></i> Weight</label>
                        <div class="input-with-unit">
                            <input type="number" step="0.1" name="weight" class="form-control" placeholder="e.g. 72.5" value="<?= $latest && $latest['weight'] ? $latest['weight'] : '' ?>">
                            <span class="unit-badge">kg</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="bi bi-fire"></i> Body Fat</label>
                        <div class="input-with-unit">
                            <input type="number" step="0.1" name="body_fat" class="form-control" placeholder="e.g. 18.5" value="<?= $latest && $latest['body_fat'] ? $latest['body_fat'] : '' ?>">
                            <span class="unit-badge">%</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="bi bi-heart"></i> Chest</label>
                        <div class="input-with-unit">
                            <input type="number" step="0.1" name="chest" class="form-control" placeholder="e.g. 100" value="<?= $latest && $latest['chest'] ? $latest['chest'] : '' ?>">
                            <span class="unit-badge">cm</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="bi bi-circle"></i> Waist</label>
                        <div class="input-with-unit">
                            <input type="number" step="0.1" name="waist" class="form-control" placeholder="e.g. 80" value="<?= $latest && $latest['waist'] ? $latest['waist'] : '' ?>">
                            <span class="unit-badge">cm</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="bi bi-circle-fill"></i> Hips</label>
                        <div class="input-with-unit">
                            <input type="number" step="0.1" name="hips" class="form-control" placeholder="e.g. 95" value="<?= $latest && $latest['hips'] ? $latest['hips'] : '' ?>">
                            <span class="unit-badge">cm</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="bi bi-hand-index-thumb"></i> Arms</label>
                        <div class="input-with-unit">
                            <input type="number" step="0.1" name="arms" class="form-control" placeholder="e.g. 35" value="<?= $latest && $latest['arms'] ? $latest['arms'] : '' ?>">
                            <span class="unit-badge">cm</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="bi bi-universal-access"></i> Thighs</label>
                        <div class="input-with-unit">
                            <input type="number" step="0.1" name="thighs" class="form-control" placeholder="e.g. 55" value="<?= $latest && $latest['thighs'] ? $latest['thighs'] : '' ?>">
                            <span class="unit-badge">cm</span>
                        </div>
                    </div>

                    <div class="form-group full">
                        <label class="form-label"><i class="bi bi-pencil"></i> Notes (Optional)</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. Morning measurement, after fast">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeMeasureModal()">Cancel</button>
                <button type="submit" class="btn-save">
                    <i class="bi bi-check-circle-fill"></i> Save Measurement
                </button>
            </div>
        </form>
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
    // ANIMATE PROGRESS BAR
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const goalFill = document.getElementById('goalFill');
        if (goalFill) {
            setTimeout(() => {
                goalFill.style.width = goalFill.dataset.target;
            }, 300);
        }
    });

    // ============================================
    // MODAL CONTROLS
    // ============================================
    function openMeasureModal() {
        document.getElementById('measureModal').classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeMeasureModal(e) {
        if (e && e.target !== document.getElementById('measureModal')) return;
        document.getElementById('measureModal').classList.remove('open');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.getElementById('measureModal').classList.remove('open');
            document.body.style.overflow = '';
        }
    });

    // ============================================
    // WEIGHT CHART
    // ============================================
    <?php if ($hasChartData): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('weightChart').getContext('2d');

        // Create gradient
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(0, 91, 159, 0.25)');
        gradient.addColorStop(1, 'rgba(0, 91, 159, 0.02)');

        const weightData = <?= json_encode($chartWeights) ?>;
        const labels = <?= json_encode($chartLabels) ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Weight (kg)',
                    data: weightData,
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
                        bodyFont: { size: 14, weight: '600' },
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y + ' kg';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)',
                            drawBorder: false
                        },
                        ticks: {
                            font: { size: 12, weight: '600' },
                            color: '#9ca3af',
                            callback: function(value) {
                                return value + ' kg';
                            }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { size: 11, weight: '600' },
                            color: '#9ca3af'
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });
    });
    <?php endif; ?>
</script>
<?php include 'chat_widget.php'; ?>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>