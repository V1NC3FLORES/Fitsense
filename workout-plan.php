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
// HANDLE COMPLETE WORKOUT ACTION
// ============================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    if ($_POST['action'] === 'complete_exercise' && isset($_POST['exercise_id'])) {
        $exerciseId = (int)$_POST['exercise_id'];
        $today = date('Y-m-d');

        if (tableExists($conn, 'workout_completions')) {
            // Check if already completed
            $result = safeQuery($conn,
                "SELECT id FROM workout_completions 
                 WHERE user_id = ? AND exercise_id = ? AND completion_date = ?",
                "iis", [$userId, $exerciseId, $today]
            );

            if ($result && $result->num_rows > 0) {
                // Already completed — un-complete (toggle)
                safeQuery($conn,
                    "DELETE FROM workout_completions 
                     WHERE user_id = ? AND exercise_id = ? AND completion_date = ?",
                    "iis", [$userId, $exerciseId, $today]
                );
                $message = 'Exercise marked as incomplete.';
                $messageType = 'info';
            } else {
                // Mark as complete
                safeQuery($conn,
                    "INSERT INTO workout_completions (user_id, exercise_id, completion_date, completed_at) 
                     VALUES (?, ?, ?, NOW())",
                    "iis", [$userId, $exerciseId, $today]
                );
                $message = 'Great job! Exercise completed. 💪';
                $messageType = 'success';
            }
        } else {
            $message = 'Workout tracking table not set up yet.';
            $messageType = 'error';
        }
    }
}

// ============================================
// FETCH WORKOUT PLAN
// ============================================
$workoutPlan = [];
$coach = null;
$planName = 'My Workout Plan';

if (tableExists($conn, 'workout_plans')) {
    // Get active plan for this user
    $result = safeQuery($conn,
        "SELECT wp.id, wp.plan_name, wp.description, wp.coach_id, wp.assigned_date,
                u.fullname AS coach_name, u.profile_image AS coach_image
         FROM workout_plans wp
         LEFT JOIN users u ON wp.coach_id = u.id
         WHERE wp.user_id = ? AND wp.is_active = 1
         ORDER BY wp.assigned_date DESC
         LIMIT 1",
        "i", [$userId]
    );

    if ($result && $row = $result->fetch_assoc()) {
        $planName = $row['plan_name'];
        $coach = [
            'id' => $row['coach_id'],
            'name' => $row['coach_name'] ?? 'Coach',
            'image' => $row['coach_image'] ?? '',
            'initial' => strtoupper(substr($row['coach_name'] ?? 'C', 0, 1)),
        ];
        $planId = (int)$row['id'];

        // Fetch exercises grouped by day
        $result = safeQuery($conn,
            "SELECT id, day_of_week, exercise_name, muscle_group, sets, reps, rest_seconds, notes, order_num
             FROM workout_exercises
             WHERE plan_id = ?
             ORDER BY FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), order_num",
            "i", [$planId]
        );

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $day = $row['day_of_week'];
                if (!isset($workoutPlan[$day])) $workoutPlan[$day] = [];
                $workoutPlan[$day][] = $row;
            }
        }
    }
}

// ============================================
// FALLBACK SAMPLE PLAN (if no DB data)
// ============================================
if (empty($workoutPlan)) {
    $coach = ['id' => 0, 'name' => 'Coach John', 'image' => '', 'initial' => 'J'];

    $workoutPlan = [
        'Monday' => [
            ['id' => 1, 'exercise_name' => 'Bench Press', 'muscle_group' => 'Chest', 'sets' => 4, 'reps' => 10, 'rest_seconds' => 60, 'notes' => 'Keep elbows at 45°'],
            ['id' => 2, 'exercise_name' => 'Incline Dumbbell Press', 'muscle_group' => 'Upper Chest', 'sets' => 3, 'reps' => 12, 'rest_seconds' => 60, 'notes' => ''],
            ['id' => 3, 'exercise_name' => 'Tricep Pushdown', 'muscle_group' => 'Triceps', 'sets' => 3, 'reps' => 12, 'rest_seconds' => 45, 'notes' => ''],
            ['id' => 4, 'exercise_name' => 'Cable Flyes', 'muscle_group' => 'Chest', 'sets' => 3, 'reps' => 15, 'rest_seconds' => 45, 'notes' => ''],
            ['id' => 5, 'exercise_name' => 'Overhead Tricep Extension', 'muscle_group' => 'Triceps', 'sets' => 3, 'reps' => 12, 'rest_seconds' => 45, 'notes' => ''],
        ],
        'Tuesday' => [
            ['id' => 6, 'exercise_name' => 'Pull-Ups', 'muscle_group' => 'Back', 'sets' => 4, 'reps' => 8, 'rest_seconds' => 90, 'notes' => 'Use assisted if needed'],
            ['id' => 7, 'exercise_name' => 'Barbell Rows', 'muscle_group' => 'Back', 'sets' => 4, 'reps' => 10, 'rest_seconds' => 60, 'notes' => ''],
            ['id' => 8, 'exercise_name' => 'Barbell Curl', 'muscle_group' => 'Biceps', 'sets' => 3, 'reps' => 12, 'rest_seconds' => 45, 'notes' => ''],
        ],
        'Wednesday' => [
            ['id' => 9, 'exercise_name' => 'Rest Day', 'muscle_group' => 'Recovery', 'sets' => 0, 'reps' => 0, 'rest_seconds' => 0, 'notes' => 'Light stretching & hydration'],
        ],
        'Thursday' => [
            ['id' => 10, 'exercise_name' => 'Squats', 'muscle_group' => 'Legs', 'sets' => 4, 'reps' => 10, 'rest_seconds' => 90, 'notes' => ''],
            ['id' => 11, 'exercise_name' => 'Leg Press', 'muscle_group' => 'Legs', 'sets' => 3, 'reps' => 12, 'rest_seconds' => 60, 'notes' => ''],
            ['id' => 12, 'exercise_name' => 'Calf Raises', 'muscle_group' => 'Calves', 'sets' => 4, 'reps' => 15, 'rest_seconds' => 45, 'notes' => ''],
        ],
        'Friday' => [
            ['id' => 13, 'exercise_name' => 'Overhead Press', 'muscle_group' => 'Shoulders', 'sets' => 4, 'reps' => 10, 'rest_seconds' => 60, 'notes' => ''],
            ['id' => 14, 'exercise_name' => 'Lateral Raises', 'muscle_group' => 'Shoulders', 'sets' => 3, 'reps' => 15, 'rest_seconds' => 45, 'notes' => ''],
            ['id' => 15, 'exercise_name' => 'Face Pulls', 'muscle_group' => 'Rear Delts', 'sets' => 3, 'reps' => 15, 'rest_seconds' => 45, 'notes' => ''],
        ],
        'Saturday' => [
            ['id' => 16, 'exercise_name' => 'Deadlifts', 'muscle_group' => 'Back/Legs', 'sets' => 4, 'reps' => 6, 'rest_seconds' => 120, 'notes' => 'Focus on form'],
            ['id' => 17, 'exercise_name' => 'Romanian Deadlifts', 'muscle_group' => 'Hamstrings', 'sets' => 3, 'reps' => 10, 'rest_seconds' => 60, 'notes' => ''],
            ['id' => 18, 'exercise_name' => 'Planks', 'muscle_group' => 'Core', 'sets' => 3, 'reps' => 60, 'rest_seconds' => 30, 'notes' => 'Hold for 60 seconds'],
        ],
        'Sunday' => [
            ['id' => 19, 'exercise_name' => 'Rest Day', 'muscle_group' => 'Recovery', 'sets' => 0, 'reps' => 0, 'rest_seconds' => 0, 'notes' => 'Full recovery day'],
        ],
    ];
}

// ============================================
// FETCH TODAY'S COMPLETIONS
// ============================================
$completedToday = [];
$today = date('Y-m-d');

if (tableExists($conn, 'workout_completions')) {
    $result = safeQuery($conn,
        "SELECT exercise_id FROM workout_completions 
         WHERE user_id = ? AND completion_date = ?",
        "is", [$userId, $today]
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $completedToday[(int)$row['exercise_id']] = true;
        }
    }
}

// ============================================
// CALCULATE PROGRESS
// ============================================
$todayName = date('l'); // e.g., "Monday"
$todayExercises = $workoutPlan[$todayName] ?? [];

// If today is a rest day, use Monday
if (empty($todayExercises) || (count($todayExercises) === 1 && stripos($todayExercises[0]['exercise_name'], 'rest') !== false)) {
    // Find next workout day
    $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $todayIdx = array_search($todayName, $days);
    for ($i = 1; $i <= 7; $i++) {
        $checkDay = $days[($todayIdx + $i) % 7];
        if (!empty($workoutPlan[$checkDay])) {
            $firstEx = $workoutPlan[$checkDay][0];
            if (stripos($firstEx['exercise_name'], 'rest') === false) {
                $todayExercises = $workoutPlan[$checkDay];
                $todayName = $checkDay . ' (Next)';
                break;
            }
        }
    }
}

$totalExercises = count($todayExercises);
$completedCount = 0;
foreach ($todayExercises as $ex) {
    if (isset($completedToday[(int)$ex['id']])) $completedCount++;
}
$todayProgress = $totalExercises > 0 ? round(($completedCount / $totalExercises) * 100) : 0;

// Total weekly exercises
$totalWeekExercises = 0;
$totalWeekCompleted = 0;
foreach ($workoutPlan as $day => $exercises) {
    foreach ($exercises as $ex) {
        if (stripos($ex['exercise_name'], 'rest') === false) {
            $totalWeekExercises++;
            if (isset($completedToday[(int)$ex['id']])) $totalWeekCompleted++;
        }
    }
}
$weekProgress = $totalWeekExercises > 0 ? round(($totalWeekCompleted / $totalWeekExercises) * 100) : 0;

// Day name shortener
function shortDay($day) {
    return substr($day, 0, 3);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Workout Plan - UMak Gym: Fitsense</title>
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
        .alert-info { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1e40af; border-left: 4px solid var(--umak-blue); }
        .alert-error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border-left: 4px solid var(--umak-red); }

        /* ============================================
           COACH CARD
           ============================================ */
        .coach-card {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 20px; padding: 30px;
            color: white; margin-bottom: 25px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 20px; flex-wrap: wrap;
            position: relative; overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,91,159,0.2);
        }

        .coach-card::before {
            content: ''; position: absolute; top: -80px; right: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(255,206,0,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }

        .coach-info { display: flex; align-items: center; gap: 20px; position: relative; z-index: 2; }

        .coach-avatar {
            width: 70px; height: 70px; border-radius: 50%;
            background: linear-gradient(135deg, #ffce00, #ffb800);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; font-weight: 900; color: #333;
            flex-shrink: 0; border: 3px solid rgba(255,255,255,0.2);
        }

        .coach-details .label {
            font-size: 11px; text-transform: uppercase; letter-spacing: 2px;
            color: rgba(255,255,255,0.7); font-weight: 700; margin-bottom: 6px;
        }

        .coach-details h3 {
            margin: 0 0 4px; font-size: 22px; font-weight: 800;
            letter-spacing: -0.3px;
        }

        .coach-details p {
            margin: 0; font-size: 13px; color: rgba(255,255,255,0.75);
            display: flex; align-items: center; gap: 6px;
        }

        .btn-contact-coach {
            background: var(--umak-yellow); color: #333;
            border: none; padding: 12px 24px;
            border-radius: 25px; font-size: 13px; font-weight: 700;
            cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px;
            transition: 0.2s; position: relative; z-index: 2;
            box-shadow: 0 6px 18px rgba(255,206,0,0.4);
        }

        .btn-contact-coach:hover { background: #e5b800; transform: translateY(-2px); }

        /* ============================================
           PROGRESS CARD
           ============================================ */
        .progress-card {
            background: var(--card-bg); border-radius: 16px;
            padding: 22px 25px; border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
        }

        .progress-header {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 15px;
            flex-wrap: wrap; gap: 10px;
        }

        .progress-header h3 {
            margin: 0; font-size: 16px; font-weight: 800;
            color: var(--umak-blue); display: flex; align-items: center; gap: 10px;
        }

        .progress-header .percentage {
            font-size: 24px; font-weight: 800; color: var(--umak-blue);
            letter-spacing: -0.5px;
        }

        .progress-track {
            height: 12px; background: #f3f4f6;
            border-radius: 6px; overflow: hidden; position: relative;
        }

        .progress-fill {
            height: 100%; background: linear-gradient(90deg, #005b9f, #0084d1);
            border-radius: 6px; width: 0%;
            transition: width 0.8s ease;
            position: relative;
        }

        .progress-fill.complete { background: linear-gradient(90deg, #2e7d32, #4ade80); }

        .progress-fill::after {
            content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .progress-info {
            display: flex; justify-content: space-between;
            margin-top: 10px; font-size: 13px; color: var(--text-muted);
            font-weight: 600;
        }

        /* ============================================
           DAY SELECTOR TABS
           ============================================ */
        .day-tabs {
            display: flex; gap: 8px; overflow-x: auto;
            padding-bottom: 5px; margin-bottom: 25px;
            scrollbar-width: thin;
        }

        .day-tabs::-webkit-scrollbar { height: 6px; }
        .day-tabs::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }

        .day-tab {
            flex-shrink: 0;
            padding: 12px 22px;
            background: white;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-muted);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            min-width: 85px;
            position: relative;
        }

        .day-tab:hover {
            border-color: rgba(0,91,159,0.3);
            color: var(--umak-blue);
            transform: translateY(-2px);
        }

        .day-tab.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            border-color: transparent;
            color: white;
            box-shadow: 0 8px 20px rgba(0,91,159,0.3);
        }

        .day-tab .day-label {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        .day-tab .day-sub {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.7;
        }

        .day-tab .day-dot {
            position: absolute;
            top: 8px; right: 8px;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--umak-green);
            border: 2px solid white;
        }

        .day-tab.today {
            border-color: var(--umak-yellow);
            background: linear-gradient(135deg, #fff8e1, #fff3c4);
            color: #b8860b;
        }

        .day-tab.today.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
        }

        /* ============================================
           WORKOUT DAY PANEL
           ============================================ */
        .workout-panel {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 30px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
        }

        .workout-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border);
        }

        .workout-panel-header h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 12px;
            letter-spacing: -0.3px;
        }

        .workout-panel-header h2 .day-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .workout-meta {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            font-size: 13px;
        }

        .workout-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .workout-meta-item i { color: var(--umak-blue); font-size: 14px; }

        /* ============================================
           EXERCISE LIST
           ============================================ */
        .exercise-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .exercise-card {
            background: #ffffff;
            border: 2px solid var(--border);
            border-radius: 14px;
            padding: 18px 22px;
            display: flex;
            align-items: center;
            gap: 18px;
            transition: all 0.25s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .exercise-card:hover {
            border-color: rgba(0,91,159,0.3);
            transform: translateX(4px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.06);
        }

        .exercise-card.completed {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border-color: rgba(46,125,50,0.3);
        }

        .exercise-card.completed .exercise-name {
            text-decoration: line-through;
            text-decoration-color: #86efac;
            color: #166534;
        }

        /* Custom Checkbox */
        .exercise-checkbox {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            border: 2.5px solid var(--border);
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            flex-shrink: 0;
            position: relative;
        }

        .exercise-checkbox:hover {
            border-color: var(--umak-blue);
            transform: scale(1.05);
        }

        .exercise-checkbox.checked {
            background: linear-gradient(135deg, #2e7d32, #4ade80);
            border-color: #2e7d32;
            color: white;
        }

        .exercise-checkbox.checked::after {
            content: '\2713';
            font-weight: 900;
            font-size: 18px;
            animation: checkPop 0.3s ease;
        }

        @keyframes checkPop {
            0% { transform: scale(0) rotate(-180deg); }
            100% { transform: scale(1) rotate(0); }
        }

        /* Exercise Info */
        .exercise-info { flex: 1; min-width: 0; }

        .exercise-name {
            font-size: 16px;
            font-weight: 800;
            color: #111827;
            margin: 0 0 6px;
            letter-spacing: -0.2px;
            transition: color 0.2s;
        }

        .exercise-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 12.5px;
            align-items: center;
        }

        .exercise-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            background: #f3f4f6;
            border-radius: 8px;
            font-weight: 700;
            color: #4b5563;
        }

        .exercise-meta-item i {
            font-size: 11px;
            color: var(--umak-blue);
        }

        .exercise-meta-item.muscle {
            background: rgba(0,91,159,0.1);
            color: var(--umak-blue);
        }

        .exercise-meta-item.rest {
            background: rgba(245,158,11,0.12);
            color: #b45309;
        }

        /* Notes */
        .exercise-notes {
            font-size: 12px;
            color: var(--text-muted);
            font-style: italic;
            margin-top: 8px;
            padding-left: 10px;
            border-left: 3px solid var(--umak-yellow);
        }

        /* Rest Day Special */
        .rest-day-card {
            text-align: center;
            padding: 50px 30px;
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-radius: 20px;
            border: 2px dashed rgba(245,158,11,0.4);
        }

        .rest-day-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: rgba(245,158,11,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: #b45309;
        }

        .rest-day-card h3 {
            margin: 0 0 8px;
            font-size: 24px;
            font-weight: 800;
            color: #92400e;
        }

        .rest-day-card p {
            margin: 0;
            color: #78350f;
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* ============================================
           COMPLETE BUTTON
           ============================================ */
        .complete-btn-wrapper {
            display: flex;
            justify-content: center;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 2px solid var(--border);
        }

        .btn-complete {
            padding: 16px 40px;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 8px 25px rgba(0,91,159,0.35);
        }

        .btn-complete:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 35px rgba(0,91,159,0.45);
        }

        .btn-complete.completed {
            background: linear-gradient(135deg, #2e7d32, #4ade80);
            box-shadow: 0 8px 25px rgba(46,125,50,0.35);
        }

        .btn-complete.completed:hover {
            box-shadow: 0 12px 35px rgba(46,125,50,0.45);
        }

        .btn-complete i { font-size: 20px; }

        /* ============================================
           WEEKLY OVERVIEW
           ============================================ */
        .weekly-overview {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 28px 30px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }

        .weekly-overview h3 {
            margin: 0 0 6px;
            font-size: 18px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .weekly-overview .subtitle {
            color: var(--text-muted);
            font-size: 13px;
            margin-bottom: 22px;
        }

        .week-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }

        .week-day-card {
            padding: 16px 14px;
            border-radius: 12px;
            background: #f9fafb;
            border: 2px solid var(--border);
            text-align: center;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .week-day-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            border-color: rgba(0,91,159,0.3);
        }

        .week-day-card.today {
            background: linear-gradient(135deg, #fff8e1, #fff3c4);
            border-color: var(--umak-yellow);
        }

        .week-day-card.rest {
            background: #f3f4f6;
            opacity: 0.7;
        }

        .week-day-name {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: var(--umak-blue);
            margin-bottom: 6px;
        }

        .week-day-focus {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .week-day-count {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .week-day-count.done {
            color: var(--umak-green);
            font-weight: 800;
        }

        /* ============================================
           EMPTY STATE
           ============================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: rgba(0,91,159,0.08);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: var(--umak-blue);
        }

        .empty-state h3 {
            margin: 0 0 10px;
            color: var(--umak-blue);
            font-size: 18px;
        }

        .empty-state p {
            color: var(--text-muted);
            font-size: 14px;
            margin: 0;
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
            .page-title { font-size: 20px; }
            .coach-card { padding: 22px; }
            .coach-avatar { width: 55px; height: 55px; font-size: 22px; }
            .coach-details h3 { font-size: 18px; }
            .workout-panel { padding: 20px 16px; }
            .workout-panel-header h2 { font-size: 18px; }
            .exercise-card { padding: 14px 16px; gap: 12px; }
            .exercise-name { font-size: 14px; }
            .exercise-meta { font-size: 11.5px; }
        }

        @media (max-width: 576px) {
            .day-tab { padding: 10px 14px; min-width: 70px; }
            .day-tab .day-label { font-size: 12px; }
            .day-tab .day-sub { font-size: 9px; }
            .btn-complete { padding: 14px 24px; font-size: 13px; }
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
        <a href="workout-plan.php" class="nav-item active">
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
                <h2>My Workout Plan</h2>
                <p class="subtitle">Your personalized training program</p>
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
            <i class="bi bi-heart-pulse-fill"></i>
            My Workout Plan
        </h1>
        <p class="page-subtitle">Follow your assigned routine and track your daily progress.</p>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType === 'success' ? 'success' : ($messageType === 'info' ? 'info' : 'error') ?>">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : ($messageType === 'info' ? 'info-circle-fill' : 'exclamation-triangle-fill') ?>" style="font-size: 20px;"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- ============================================
             TODAY'S PROGRESS
             ============================================ -->
        <div class="progress-card">
            <div class="progress-header">
                <h3>
                    <i class="bi bi-graph-up-arrow"></i>
                    Today's Progress — <?= htmlspecialchars($todayName) ?>
                </h3>
                <div class="percentage"><?= $todayProgress ?>%</div>
            </div>
            <div class="progress-track">
                <div class="progress-fill <?= $todayProgress === 100 ? 'complete' : '' ?>"
                     id="progressFill"
                     style="width: 0%"
                     data-target="<?= $todayProgress ?>%"></div>
            </div>
            <div class="progress-info">
                <span><?= $completedCount ?> of <?= $totalExercises ?> exercises completed</span>
                <span><?= $todayProgress === 100 ? '🎉 All done!' : ($totalExercises - $completedCount) . ' remaining' ?></span>
            </div>
        </div>

        <!-- ============================================
             DAY SELECTOR TABS
             ============================================ -->
        <div class="day-tabs">
            <?php
            $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
            $todayReal = date('l');
            foreach ($days as $day):
                if (empty($workoutPlan[$day])) continue;
                $dayExercises = $workoutPlan[$day];
                $isRest = count($dayExercises) === 1 && stripos($dayExercises[0]['exercise_name'], 'rest') !== false;
                $isToday = $day === $todayReal;
                $isActive = $day === str_replace(' (Next)', '', $todayName);

                // Check if day has any completions
                $hasCompletion = false;
                foreach ($dayExercises as $ex) {
                    if (isset($completedToday[(int)$ex['id']])) {
                        $hasCompletion = true;
                        break;
                    }
                }

                // Focus text
                if ($isRest) {
                    $focus = 'Rest Day';
                } else {
                    $groups = array_unique(array_column($dayExercises, 'muscle_group'));
                    $focus = implode(', ', array_slice($groups, 0, 2));
                    if (count($groups) > 2) $focus .= '...';
                }
            ?>
                <a href="?day=<?= urlencode($day) ?>"
                   class="day-tab <?= $isActive ? 'active' : '' ?> <?= $isToday ? 'today' : '' ?>">
                    <?php if ($hasCompletion): ?>
                        <span class="day-dot"></span>
                    <?php endif; ?>
                    <span class="day-label"><?= shortDay($day) ?></span>
                    <span class="day-sub"><?= $isToday ? 'Today' : ($isRest ? 'Rest' : count($dayExercises) . ' ex') ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- ============================================
             WORKOUT PANEL
             ============================================ -->
        <?php
        // Determine which day to show
        $displayDay = $_GET['day'] ?? null;
        if (!$displayDay || empty($workoutPlan[$displayDay])) {
            $displayDay = str_replace(' (Next)', '', $todayName);
        }
        $dayExercises = $workoutPlan[$displayDay] ?? [];
        $isRestDay = count($dayExercises) === 1 && stripos($dayExercises[0]['exercise_name'], 'rest') !== false;
        ?>

        <div class="workout-panel">
            <div class="workout-panel-header">
                <h2>
                    <span class="day-icon">
                        <i class="bi bi-calendar-event-fill"></i>
                    </span>
                    <?= htmlspecialchars($displayDay) ?>
                </h2>
                <div class="workout-meta">
                    <?php if (!$isRestDay): ?>
                        <span class="workout-meta-item">
                            <i class="bi bi-list-check"></i>
                            <?= count($dayExercises) ?> Exercise<?= count($dayExercises) != 1 ? 's' : '' ?>
                        </span>
                        <span class="workout-meta-item">
                            <i class="bi bi-clock-history"></i>
                            ~<?= count($dayExercises) * 8 ?> min
                        </span>
                        <span class="workout-meta-item">
                            <i class="bi bi-fire"></i>
                            ~<?= count($dayExercises) * 65 ?> cal
                        </span>
                    <?php else: ?>
                        <span class="workout-meta-item">
                            <i class="bi bi-cup-hot-fill"></i>
                            Recovery Day
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isRestDay): ?>
                <!-- Rest Day Display -->
                <div class="rest-day-card">
                    <div class="rest-day-icon">😴</div>
                    <h3>Rest & Recovery Day</h3>
                    <p>Take it easy today! Your muscles need time to recover and grow stronger. Stay hydrated, do light stretching, and get plenty of sleep.</p>
                </div>
            <?php elseif (empty($dayExercises)): ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-clipboard-x"></i>
                    </div>
                    <h3>No Exercises Assigned</h3>
                    <p>Your coach hasn't assigned exercises for this day yet.</p>
                </div>
            <?php else: ?>
                <!-- Exercise List -->
                <form method="POST" id="workoutForm">
                    <?php echo app_csrf_field(); ?>
                    <div class="exercise-list">
                        <?php foreach ($dayExercises as $idx => $ex):
                            $exerciseId = (int)$ex['id'];
                            $isCompleted = isset($completedToday[$exerciseId]);
                        ?>
                            <div class="exercise-card <?= $isCompleted ? 'completed' : '' ?>"
                                 data-exercise-id="<?= $exerciseId ?>"
                                 onclick="toggleExercise(<?= $exerciseId ?>)">

                                <div class="exercise-checkbox <?= $isCompleted ? 'checked' : '' ?>"
                                     id="checkbox-<?= $exerciseId ?>"></div>

                                <div class="exercise-info">
                                    <h4 class="exercise-name"><?= htmlspecialchars($ex['exercise_name']) ?></h4>
                                    <div class="exercise-meta">
                                        <?php if (!empty($ex['muscle_group'])): ?>
                                            <span class="exercise-meta-item muscle">
                                                <i class="bi bi-bullseye"></i>
                                                <?= htmlspecialchars($ex['muscle_group']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($ex['sets'] > 0): ?>
                                            <span class="exercise-meta-item">
                                                <i class="bi bi-stack"></i>
                                                <?= (int)$ex['sets'] ?> sets
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($ex['reps'] > 0): ?>
                                            <span class="exercise-meta-item">
                                                <i class="bi bi-arrow-repeat"></i>
                                                <?= (int)$ex['reps'] ?> reps
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($ex['rest_seconds']) && $ex['rest_seconds'] > 0): ?>
                                            <span class="exercise-meta-item">
                                                <i class="bi bi-hourglass-split"></i>
                                                <?= (int)$ex['rest_seconds'] ?>s rest
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($ex['notes'])): ?>
                                        <div class="exercise-notes">
                                            <i class="bi bi-info-circle me-1"></i>
                                            <?= htmlspecialchars($ex['notes']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </form>
            <?php endif; ?>

            <!-- Complete Button -->
            <?php if (!$isRestDay && !empty($dayExercises)): ?>
                <div class="complete-btn-wrapper">
                    <button type="button"
                            class="btn-complete <?= $todayProgress === 100 ? 'completed' : '' ?>"
                            onclick="completeWorkout()"
                            id="completeBtn">
                        <i class="bi bi-<?= $todayProgress === 100 ? 'check-circle-fill' : 'trophy-fill' ?>"></i>
                        <?= $todayProgress === 100 ? 'Workout Complete! 🎉' : 'Mark Workout Complete' ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================
             WEEKLY OVERVIEW
             ============================================ -->
        <div class="weekly-overview">
            <h3>
                <i class="bi bi-calendar-week-fill"></i>
                Weekly Overview
            </h3>
            <p class="subtitle">Your complete training split for the week.</p>

            <div class="week-grid">
                <?php foreach ($days as $day):
                    if (empty($workoutPlan[$day])) continue;
                    $dayEx = $workoutPlan[$day];
                    $isRest = count($dayEx) === 1 && stripos($dayEx[0]['exercise_name'], 'rest') !== false;
                    $isToday = $day === $todayReal;

                    if ($isRest) {
                        $focus = 'Rest Day';
                    } else {
                        $groups = array_unique(array_column($dayEx, 'muscle_group'));
                        $focus = implode(' & ', array_slice($groups, 0, 2));
                    }

                    $doneCount = 0;
                    foreach ($dayEx as $e) {
                        if (isset($completedToday[(int)$e['id']])) $doneCount++;
                    }
                ?>
                    <a href="?day=<?= urlencode($day) ?>"
                       class="week-day-card <?= $isToday ? 'today' : '' ?> <?= $isRest ? 'rest' : '' ?>">
                        <div class="week-day-name">
                            <?= $isToday ? '⭐ Today' : shortDay($day) ?>
                        </div>
                        <div class="week-day-focus" title="<?= htmlspecialchars($focus) ?>">
                            <?= htmlspecialchars($focus) ?>
                        </div>
                        <div class="week-day-count <?= ($doneCount > 0 && $doneCount === count($dayEx)) ? 'done' : '' ?>">
                            <?php if ($isRest): ?>
                                Recovery
                            <?php else: ?>
                                <?= count($dayEx) ?> exercise<?= count($dayEx) != 1 ? 's' : '' ?>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
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
    // ANIMATE PROGRESS BAR ON LOAD
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const fill = document.getElementById('progressFill');
        if (fill) {
            const target = fill.dataset.target;
            setTimeout(() => {
                fill.style.width = target;
            }, 200);
        }
    });

    const csrfToken = <?php echo json_encode(app_csrf_token()); ?>;

    // ============================================
    // TOGGLE EXERCISE COMPLETION
    // ============================================
    function toggleExercise(exerciseId) {
        const card = document.querySelector(`.exercise-card[data-exercise-id="${exerciseId}"]`);
        const checkbox = document.getElementById(`checkbox-${exerciseId}`);

        // Optimistic UI update
        const isChecked = checkbox.classList.contains('checked');
        if (isChecked) {
            checkbox.classList.remove('checked');
            card.classList.remove('completed');
        } else {
            checkbox.classList.add('checked');
            card.classList.add('completed');
        }

        // Submit via fetch (AJAX)
        const formData = new FormData();
        formData.append('action', 'complete_exercise');
        formData.append('exercise_id', exerciseId);
        formData.append('csrf_token', csrfToken);

        fetch('workout-plan.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            // Optionally reload to update progress
            setTimeout(() => window.location.reload(), 400);
        })
        .catch(err => {
            console.error('Error:', err);
            // Revert on error
            if (isChecked) {
                checkbox.classList.add('checked');
                card.classList.add('completed');
            } else {
                checkbox.classList.remove('checked');
                card.classList.remove('completed');
            }
        });
    }

    // ============================================
    // COMPLETE ENTIRE WORKOUT
    // ============================================
    function completeWorkout() {
        const cards = document.querySelectorAll('.exercise-card:not(.completed)');
        if (cards.length === 0) {
            alert('All exercises are already complete! 🎉');
            return;
        }

        if (!confirm(`Mark all ${cards.length} remaining exercises as complete?`)) return;

        // Complete all remaining exercises sequentially
        const ids = Array.from(cards).map(c => c.dataset.exerciseId);

        let completed = 0;
        ids.forEach((id, index) => {
            setTimeout(() => {
                toggleExercise(id);
                completed++;
            }, index * 200);
        });

        setTimeout(() => {
            setTimeout(() => window.location.reload(), 500);
        }, ids.length * 200 + 300);
    }
</script>
<?php include 'chat_widget.php'; ?>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>