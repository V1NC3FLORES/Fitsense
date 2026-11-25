<?php
// nutrition-plan.php
session_start();
include 'conn.php';
require_once __DIR__ . '/config/session_security.php';

// ============================================
// AUTHENTICATION
// ============================================
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

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
        if ($stmt === false) {
            error_log("Prepare failed: " . $conn->error . " | SQL: " . $sql);
            return false;
        }
        if (!empty($types)) $stmt->bind_param($types, ...$params);
        $result = $stmt->execute();
        if (!$result) error_log("Execute failed: " . $stmt->error);
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

function columnExists($conn, $table, $column) {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $r && $r->num_rows > 0;
}

// ============================================
// DETECT COMPLETION COLUMN NAME
// ============================================
function getCompletionColumn($conn) {
    if (!tableExists($conn, 'nutrition_completions')) return 'item_id';
    if (columnExists($conn, 'nutrition_completions', 'item_id')) return 'item_id';
    if (columnExists($conn, 'nutrition_completions', 'meal_item_id')) return 'meal_item_id';
    return 'item_id';
}

$completionCol = getCompletionColumn($conn);

// ============================================
// GET/CREATE USER'S PLAN
// ============================================
function getUserPlanId($conn, $userId) {
    if (!tableExists($conn, 'nutrition_plans')) return null;

    $r = safeQuery($conn, "SELECT id FROM nutrition_plans WHERE user_id = ? LIMIT 1", "i", [$userId]);
    if ($r && $row = $r->fetch_assoc()) {
        return (int)$row['id'];
    }

    // Create new plan (no assigned_date in this schema)
    $stmt = $conn->prepare(
        "INSERT INTO nutrition_plans (user_id, plan_name, target_calories, target_protein, target_carbs, target_fats) 
         VALUES (?, 'My Nutrition Plan', 2000, 150, 200, 60)"
    );
    if (!$stmt) {
        error_log("getUserPlanId prepare failed: " . $conn->error);
        return null;
    }
    $stmt->bind_param("i", $userId);
    if (!$stmt->execute()) {
        error_log("getUserPlanId execute failed: " . $stmt->error);
        $stmt->close();
        return null;
    }
    $newId = $conn->insert_id;
    $stmt->close();
    return $newId;
}

// ============================================
// UPDATE DAILY PROGRESS
// ============================================
function updateProgress($conn, $userId, $date) {
    if (!tableExists($conn, 'nutrition_progress')) return;

    $completionCol = getCompletionColumn($conn);
    $totalItems = 0; $loggedItems = 0;
    $cal = 0; $prot = 0; $carb = 0; $fat = 0;

    $r = safeQuery($conn, "
        SELECT ni.id, ni.calories, ni.protein, ni.carbs, ni.fats,
               (SELECT COUNT(*) FROM nutrition_completions nc 
                WHERE nc.`$completionCol` = ni.id AND nc.user_id = ? AND nc.completion_date = ?) AS done
        FROM nutrition_items ni
        INNER JOIN nutrition_plans np ON ni.plan_id = np.id
        WHERE np.user_id = ?
    ", "isi", [$userId, $date, $userId]);

    if ($r) {
        while ($item = $r->fetch_assoc()) {
            $totalItems++;
            if ((int)$item['done'] > 0) {
                $loggedItems++;
                $cal += (int)$item['calories'];
                $prot += (int)$item['protein'];
                $carb += (int)$item['carbs'];
                $fat += (int)$item['fats'];
            }
        }
    }

    $water = 0;
    if (tableExists($conn, 'water_intake')) {
        $r = safeQuery($conn, "SELECT glasses FROM water_intake WHERE user_id = ? AND intake_date = ?", "is", [$userId, $date]);
        if ($r && $row = $r->fetch_assoc()) $water = (int)$row['glasses'];
    }

    $pct = $totalItems > 0 ? (int)round(($loggedItems / $totalItems) * 100) : 0;

    $r = safeQuery($conn, "SELECT id FROM nutrition_progress WHERE user_id = ? AND progress_date = ?", "is", [$userId, $date]);
    if ($r && $r->num_rows > 0) {
        executeQuery($conn, "
            UPDATE nutrition_progress SET 
                calories_logged = ?, protein_logged = ?, carbs_logged = ?, fats_logged = ?,
                items_logged = ?, items_total = ?, water_glasses = ?, completion_percent = ?,
                updated_at = NOW()
            WHERE user_id = ? AND progress_date = ?
        ", "iiiiiiiisi", 
            [$cal, $prot, $carb, $fat, $loggedItems, $totalItems, $water, $pct, $userId, $date]
        );
    } else {
        executeQuery($conn, "
            INSERT INTO nutrition_progress 
            (user_id, progress_date, calories_logged, protein_logged, carbs_logged, fats_logged,
             items_logged, items_total, water_glasses, completion_percent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", "isiiiiiiii", 
            [$userId, $date, $cal, $prot, $carb, $fat, $loggedItems, $totalItems, $water, $pct]
        );
    }
}

// ============================================
// HANDLE POST ACTIONS
// ============================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    // -------- TOGGLE ITEM --------
    if ($_POST['action'] === 'toggle_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $r = safeQuery($conn, "SELECT id FROM nutrition_completions WHERE user_id = ? AND `$completionCol` = ? AND completion_date = ?", "iis", [$userId, $itemId, $today]);
            if ($r && $r->num_rows > 0) {
                executeQuery($conn, "DELETE FROM nutrition_completions WHERE user_id = ? AND `$completionCol` = ? AND completion_date = ?", "iis", [$userId, $itemId, $today]);
            } else {
                executeQuery($conn, "INSERT INTO nutrition_completions (user_id, `$completionCol`, completion_date) VALUES (?, ?, ?)", "iis", [$userId, $itemId, $today]);
            }
            updateProgress($conn, $userId, $today);
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
    }

    // -------- UPDATE WATER --------
    if ($_POST['action'] === 'update_water') {
        $glasses = max(0, min(20, (int)($_POST['glasses'] ?? 0)));
        if (tableExists($conn, 'water_intake')) {
            $r = safeQuery($conn, "SELECT id FROM water_intake WHERE user_id = ? AND intake_date = ?", "is", [$userId, $today]);
            if ($r && $r->num_rows > 0) {
                executeQuery($conn, "UPDATE water_intake SET glasses = ? WHERE user_id = ? AND intake_date = ?", "iis", [$glasses, $userId, $today]);
            } else {
                executeQuery($conn, "INSERT INTO water_intake (user_id, intake_date, glasses) VALUES (?, ?, ?)", "isi", [$userId, $today, $glasses]);
            }
            updateProgress($conn, $userId, $today);
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
    }

    // -------- ADD SUGGESTED MEAL --------
    if ($_POST['action'] === 'add_suggested') {
        $suggestedId = (int)($_POST['suggested_id'] ?? 0);
        
        if ($suggestedId <= 0) {
            $_SESSION['flash_message'] = '❌ Invalid meal ID.';
            $_SESSION['flash_type'] = 'error';
            header("Location: nutrition-plan.php#tab-suggested");
            exit;
        }
        
        if (!tableExists($conn, 'suggested_meals')) {
            $_SESSION['flash_message'] = '❌ Suggested meals table missing.';
            $_SESSION['flash_type'] = 'error';
            header("Location: nutrition-plan.php#tab-suggested");
            exit;
        }
        
        $r = safeQuery($conn, "SELECT * FROM suggested_meals WHERE id = ? LIMIT 1", "i", [$suggestedId]);
        if (!$r || $r->num_rows === 0) {
            $_SESSION['flash_message'] = '❌ Suggested meal not found.';
            $_SESSION['flash_type'] = 'error';
            header("Location: nutrition-plan.php#tab-suggested");
            exit;
        }
        
        $meal = $r->fetch_assoc();
        $planId = getUserPlanId($conn, $userId);
        
        if (!$planId) {
            $_SESSION['flash_message'] = '❌ Could not create nutrition plan.';
            $_SESSION['flash_type'] = 'error';
            header("Location: nutrition-plan.php#tab-suggested");
            exit;
        }
        
        // Prepare values
        $mealName = $meal['name'] ?? 'Unknown';
        $mealType = $meal['meal_type'] ?? 'Breakfast';
        $mealPortion = $meal['portion'] ?? '';
        $mealCal = (int)($meal['calories'] ?? 0);
        $mealProt = (int)($meal['protein'] ?? 0);
        $mealCarb = (int)($meal['carbs'] ?? 0);
        $mealFat = (int)($meal['fats'] ?? 0);
        $mealImg = $meal['image_url'] ?? '';
        
        $hasImageUrl = columnExists($conn, 'nutrition_items', 'image_url');
        
        // *** FIXED: Correct type string isssiiiis (9 chars for 9 values) ***
        if ($hasImageUrl) {
            $sql = "INSERT INTO nutrition_items 
                    (plan_id, meal_type, item_name, `portion`, calories, protein, carbs, fats, image_url, order_num) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 10)";
            $types = "isssiiiis";
            $params = [$planId, $mealType, $mealName, $mealPortion, $mealCal, $mealProt, $mealCarb, $mealFat, $mealImg];
        } else {
            $sql = "INSERT INTO nutrition_items 
                    (plan_id, meal_type, item_name, `portion`, calories, protein, carbs, fats, order_num) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 10)";
            $types = "isssiiii";
            $params = [$planId, $mealType, $mealName, $mealPortion, $mealCal, $mealProt, $mealCarb, $mealFat];
        }
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $_SESSION['flash_message'] = '❌ SQL prepare error: ' . htmlspecialchars($conn->error);
            $_SESSION['flash_type'] = 'error';
            header("Location: nutrition-plan.php#tab-suggested");
            exit;
        }
        
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            $_SESSION['flash_message'] = '❌ Insert failed: ' . htmlspecialchars($stmt->error);
            $_SESSION['flash_type'] = 'error';
            $stmt->close();
            header("Location: nutrition-plan.php#tab-suggested");
            exit;
        }
        
        $newItemId = $conn->insert_id;
        $stmt->close();
        
        // Auto-mark as logged for today
        executeQuery($conn, "INSERT INTO nutrition_completions (user_id, `$completionCol`, completion_date) VALUES (?, ?, ?)", "iis", [$userId, $newItemId, $today]);
        
        updateProgress($conn, $userId, $today);
        
        $_SESSION['flash_message'] = '✅ "' . htmlspecialchars($mealName) . '" added to your Today\'s Plan!';
        $_SESSION['flash_type'] = 'success';
        $_SESSION['active_tab'] = 'today';
        header("Location: nutrition-plan.php#tab-today");
        exit;
    }

    // -------- QUICK LOG --------
    if ($_POST['action'] === 'quick_log') {
        $name = trim($_POST['item_name'] ?? '');
        $mealType = $_POST['meal_type'] ?? 'Breakfast';
        $cal = (int)($_POST['calories'] ?? 0);
        $prot = (int)($_POST['protein'] ?? 0);
        $carb = (int)($_POST['carbs'] ?? 0);
        $fat = (int)($_POST['fats'] ?? 0);
        $portion = trim($_POST['portion'] ?? '');
        $imageUrl = trim($_POST['image_url'] ?? '');

        $validMealTypes = ['Breakfast', 'Morning Snack', 'Lunch', 'Afternoon Snack', 'Dinner', 'Evening Snack'];
        if (!in_array($mealType, $validMealTypes)) $mealType = 'Breakfast';

        if (!empty($name) && $cal > 0) {
            $planId = getUserPlanId($conn, $userId);
            $hasImageUrl = columnExists($conn, 'nutrition_items', 'image_url');
            
            // *** FIXED: Correct type string isssiiiis ***
            if ($hasImageUrl) {
                $sql = "INSERT INTO nutrition_items 
                        (plan_id, meal_type, item_name, `portion`, calories, protein, carbs, fats, image_url, order_num) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 5)";
                $types = "isssiiiis";
                $params = [$planId, $mealType, $name, $portion, $cal, $prot, $carb, $fat, $imageUrl];
            } else {
                $sql = "INSERT INTO nutrition_items 
                        (plan_id, meal_type, item_name, `portion`, calories, protein, carbs, fats, order_num) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 5)";
                $types = "isssiiii";
                $params = [$planId, $mealType, $name, $portion, $cal, $prot, $carb, $fat];
            }
            
            $stmt = $conn->prepare($sql);
            if ($stmt && $stmt->execute()) {
                $newId = $conn->insert_id;
                $stmt->close();
                executeQuery($conn, "INSERT INTO nutrition_completions (user_id, `$completionCol`, completion_date) VALUES (?, ?, ?)", "iis", [$userId, $newId, $today]);
                updateProgress($conn, $userId, $today);
                $_SESSION['flash_message'] = '✅ Food logged successfully!';
                $_SESSION['flash_type'] = 'success';
                $_SESSION['active_tab'] = 'today';
                header("Location: nutrition-plan.php#tab-today");
                exit;
            } else {
                $err = $stmt ? $stmt->error : $conn->error;
                $message = '❌ Failed to log food: ' . htmlspecialchars($err);
                $messageType = 'error';
            }
        } else {
            $message = '⚠️ Please enter a food name and calories.';
            $messageType = 'error';
        }
    }

    // -------- DELETE ITEM --------
    if ($_POST['action'] === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            executeQuery($conn, "
                DELETE ni FROM nutrition_items ni
                INNER JOIN nutrition_plans np ON ni.plan_id = np.id
                WHERE ni.id = ? AND np.user_id = ?
            ", "ii", [$itemId, $userId]);
            executeQuery($conn, "DELETE FROM nutrition_completions WHERE `$completionCol` = ? AND user_id = ?", "ii", [$itemId, $userId]);
            updateProgress($conn, $userId, $today);
            $_SESSION['flash_message'] = '🗑️ Item removed.';
            $_SESSION['flash_type'] = 'success';
            $_SESSION['active_tab'] = 'today';
            header("Location: nutrition-plan.php#tab-today");
            exit;
        }
    }

    // -------- CLEAR ALL --------
    if ($_POST['action'] === 'clear_plan') {
        $planId = getUserPlanId($conn, $userId);
        if ($planId) {
            executeQuery($conn, "DELETE FROM nutrition_items WHERE plan_id = ?", "i", [$planId]);
            executeQuery($conn, "DELETE FROM nutrition_completions WHERE user_id = ?", "i", [$userId]);
            updateProgress($conn, $userId, $today);
            $_SESSION['flash_message'] = '🗑️ All items cleared from your plan.';
            $_SESSION['flash_type'] = 'success';
            $_SESSION['active_tab'] = 'today';
            header("Location: nutrition-plan.php#tab-today");
            exit;
        }
    }
}

// Check for flash message
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

$activeTab = $_SESSION['active_tab'] ?? 'today';
unset($_SESSION['active_tab']);

// ============================================
// FETCH USER DATA
// ============================================
$userData = null;
$stmt = $conn->prepare("SELECT id, fullname, username, email, profile_image FROM users WHERE id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $userData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$displayName = $userData['fullname'] ?? $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'User';
$userInitial = strtoupper(substr($displayName, 0, 1));
$userAvatar = $userData['profile_image'] ?? '';

// ============================================
// UNREAD NOTIFICATIONS
// ============================================
$unreadNotifCount = 0;
if (tableExists($conn, 'notifications')) {
    $r = safeQuery($conn, "SELECT COUNT(*) AS t FROM notifications WHERE user_id = ? AND is_read = 0", "i", [$userId]);
    if ($r && $row = $r->fetch_assoc()) $unreadNotifCount = (int)$row['t'];
}

// ============================================
// LOAD PLAN + ITEMS
// ============================================
$planId = getUserPlanId($conn, $userId);
$nutritionPlan = [];
$dailyCalories = 2000; $dailyProtein = 150; $dailyCarbs = 200; $dailyFats = 60;

if ($planId) {
    $r = safeQuery($conn, "SELECT target_calories, target_protein, target_carbs, target_fats FROM nutrition_plans WHERE id = ?", "i", [$planId]);
    if ($r && $row = $r->fetch_assoc()) {
        $dailyCalories = (int)($row['target_calories'] ?: 2000);
        $dailyProtein = (int)($row['target_protein'] ?: 150);
        $dailyCarbs = (int)($row['target_carbs'] ?: 200);
        $dailyFats = (int)($row['target_fats'] ?: 60);
    }

    $hasImageUrl = columnExists($conn, 'nutrition_items', 'image_url');
    $imageCol = $hasImageUrl ? ', image_url' : '';
    
    $r = safeQuery($conn, "
        SELECT id, meal_type, item_name, `portion`, calories, protein, carbs, fats $imageCol
        FROM nutrition_items
        WHERE plan_id = ?
        ORDER BY FIELD(meal_type, 'Breakfast','Morning Snack','Lunch','Afternoon Snack','Dinner','Evening Snack'), order_num, id
    ", "i", [$planId]);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            if (!$hasImageUrl) $row['image_url'] = null;
            $mt = $row['meal_type'];
            if (!isset($nutritionPlan[$mt])) $nutritionPlan[$mt] = [];
            $nutritionPlan[$mt][] = $row;
        }
    }
}

// ============================================
// TODAY'S COMPLETIONS
// ============================================
$completedToday = [];
$r = safeQuery($conn, "SELECT `$completionCol` AS item_id FROM nutrition_completions WHERE user_id = ? AND completion_date = ?", "is", [$userId, $today]);
if ($r) {
    while ($row = $r->fetch_assoc()) $completedToday[(int)$row['item_id']] = true;
}

// ============================================
// WATER
// ============================================
$waterGlasses = 0;
if (tableExists($conn, 'water_intake')) {
    $r = safeQuery($conn, "SELECT glasses FROM water_intake WHERE user_id = ? AND intake_date = ?", "is", [$userId, $today]);
    if ($r && $row = $r->fetch_assoc()) $waterGlasses = (int)$row['glasses'];
}

// ============================================
// CALCULATE TOTALS
// ============================================
$totalItems = 0; $completedItems = 0;
$totalCal = 0; $totalProt = 0; $totalCarb = 0; $totalFat = 0;

$mealTotals = [];
foreach ($nutritionPlan as $mt => $items) {
    $mealTotals[$mt] = ['total' => count($items), 'done' => 0, 'cal' => 0, 'prot' => 0, 'carb' => 0, 'fat' => 0];
    foreach ($items as $item) {
        $totalItems++;
        $mealTotals[$mt]['cal'] += (int)$item['calories'];
        $mealTotals[$mt]['prot'] += (int)$item['protein'];
        $mealTotals[$mt]['carb'] += (int)$item['carbs'];
        $mealTotals[$mt]['fat'] += (int)$item['fats'];
        if (isset($completedToday[(int)$item['id']])) {
            $completedItems++;
            $mealTotals[$mt]['done']++;
            $totalCal += (int)$item['calories'];
            $totalProt += (int)$item['protein'];
            $totalCarb += (int)$item['carbs'];
            $totalFat += (int)$item['fats'];
        }
    }
}

$calPct = $dailyCalories > 0 ? min(150, (int)round(($totalCal / $dailyCalories) * 100)) : 0;
$protPct = $dailyProtein > 0 ? min(150, (int)round(($totalProt / $dailyProtein) * 100)) : 0;
$carbPct = $dailyCarbs > 0 ? min(150, (int)round(($totalCarb / $dailyCarbs) * 100)) : 0;
$fatPct = $dailyFats > 0 ? min(150, (int)round(($totalFat / $dailyFats) * 100)) : 0;
$overallPct = $totalItems > 0 ? (int)round(($completedItems / $totalItems) * 100) : 0;

// ============================================
// SUGGESTED MEALS
// ============================================
$filterType = $_GET['suggest'] ?? 'all';
$filterCat = $_GET['cat'] ?? 'all';

$suggestedMeals = [];
$suggestedCats = [];

if (tableExists($conn, 'suggested_meals')) {
    $sql = "SELECT * FROM suggested_meals WHERE is_active = 1";
    $params = []; $types = '';
    if ($filterType !== 'all') { $sql .= " AND meal_type = ?"; $params[] = $filterType; $types .= 's'; }
    if ($filterCat !== 'all') { $sql .= " AND category = ?"; $params[] = $filterCat; $types .= 's'; }
    $sql .= " ORDER BY RAND() LIMIT 24";

    $r = safeQuery($conn, $sql, $types, $params);
    if ($r) { while ($row = $r->fetch_assoc()) $suggestedMeals[] = $row; }

    $r = safeQuery($conn, "SELECT DISTINCT category FROM suggested_meals WHERE is_active = 1 ORDER BY category");
    if ($r) { while ($row = $r->fetch_assoc()) $suggestedCats[] = $row['category']; }
}

// ============================================
// PROGRESS HISTORY
// ============================================
$progressHistory = [];
if (tableExists($conn, 'nutrition_progress')) {
    $r = safeQuery($conn, "
        SELECT progress_date, calories_logged, protein_logged, carbs_logged, fats_logged,
               water_glasses, items_logged, items_total, completion_percent
        FROM nutrition_progress
        WHERE user_id = ?
        ORDER BY progress_date DESC
        LIMIT 14
    ", "i", [$userId]);
    if ($r) { while ($row = $r->fetch_assoc()) $progressHistory[] = $row; }
}

updateProgress($conn, $userId, $today);

// ============================================
// MEAL CONFIG
// ============================================
$mealConfig = [
    'Breakfast' => ['icon' => 'bi-sunrise-fill', 'color' => '#f59e0b', 'label' => 'Breakfast', 'time' => '6:00 - 10:00 AM'],
    'Morning Snack' => ['icon' => 'bi-cup-hot-fill', 'color' => '#0ea5e9', 'label' => 'Morning Snack', 'time' => '10:00 AM'],
    'Lunch' => ['icon' => 'bi-brightness-high-fill', 'color' => '#ea580c', 'label' => 'Lunch', 'time' => '12:00 - 2:00 PM'],
    'Afternoon Snack' => ['icon' => 'bi-cup-straw', 'color' => '#8b5cf6', 'label' => 'Afternoon Snack', 'time' => '3:00 PM'],
    'Dinner' => ['icon' => 'bi-moon-stars-fill', 'color' => '#4f46e5', 'label' => 'Dinner', 'time' => '6:00 - 8:00 PM'],
    'Evening Snack' => ['icon' => 'bi-cup-fill', 'color' => '#ec4899', 'label' => 'Evening Snack', 'time' => '9:00 PM'],
];

$initialTab = $activeTab === 'suggested' ? 'suggested' : ($activeTab === 'progress' ? 'progress' : 'today');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Nutrition Plan - UMak Gym: Fitsense</title>
    <link rel="icon" type="image/png" href="uploads/umak-gym-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --umak-blue: #005b9f; --umak-blue-dark: #00467a; --umak-yellow: #ffce00;
            --umak-green: #2e7d32; --umak-red: #d32f2f; --umak-orange: #f59e0b;
            --sidebar-width: 260px; --bg: #f4f6f9; --card-bg: #fff;
            --text: #1f2937; --text-muted: #6b7280; --border: #e5e7eb;
        }
        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { margin: 0; background: var(--bg); color: var(--text); }

        .sidebar {
            position: fixed; left: 0; top: 0; width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, #005b9f 0%, #00467a 100%);
            color: white; overflow-y: auto; z-index: 1000;
            display: flex; flex-direction: column;
            box-shadow: 4px 0 15px rgba(0,0,0,0.08); transition: transform 0.3s ease;
        }
        .sidebar-brand { padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
        .sidebar-brand .logo {
            width: 40px; height: 40px; background: var(--umak-yellow);
            border-radius: 10px; display: flex; align-items: center;
            justify-content: center; font-weight: 900; color: var(--umak-blue); font-size: 18px;
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
            font-weight: 600; border-left: 3px solid var(--umak-yellow); padding-left: 12px;
        }
        .nav-item i { font-size: 18px; width: 22px; text-align: center; flex-shrink: 0; }
        .nav-item .badge-count {
            margin-left: auto; background: var(--umak-red); color: white;
            font-size: 10px; font-weight: 700; padding: 2px 7px;
            border-radius: 10px; min-width: 18px; text-align: center;
        }
        .nav-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 12px 15px; }
        .nav-logout { color: #ff8a80 !important; }
        .nav-logout:hover { background: rgba(211,47,47,0.2) !important; }

        .main-wrapper { margin-left: var(--sidebar-width); min-height: 100vh; padding-bottom: 40px; transition: margin-left 0.3s ease; }
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
            box-shadow: 0 4px 12px rgba(0,91,159,0.3); transition: all 0.25s ease;
        }
        .btn-chat-open:hover { transform: translateY(-2px); }
        .notif-btn {
            background: #f4f6f9; border: none; width: 42px; height: 42px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; position: relative; font-size: 18px; color: var(--umak-blue);
        }
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
            font-weight: bold; font-size: 16px; overflow: hidden; transition: transform 0.2s;
        }
        .avatar:hover { transform: scale(1.05); }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }

        .container { max-width: 1300px; margin: 30px auto; padding: 0 25px; }
        .page-title { font-size: 26px; font-weight: 800; color: var(--umak-blue); margin: 0 0 6px; display: flex; align-items: center; gap: 12px; }
        .page-title i {
            font-size: 28px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .page-subtitle { color: var(--text-muted); font-size: 14px; margin-bottom: 28px; }

        .alert {
            padding: 14px 20px; border-radius: 12px; font-size: 14px;
            font-weight: 500; margin-bottom: 20px; display: flex;
            align-items: center; gap: 12px; animation: slideDown 0.4s ease;
        }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .alert-success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border-left: 4px solid var(--umak-green); }
        .alert-error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border-left: 4px solid var(--umak-red); }

        .main-tabs {
            display: flex; gap: 6px; margin-bottom: 25px; background: white;
            padding: 6px; border-radius: 16px; border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03); overflow-x: auto;
        }
        .main-tab {
            flex: 1; min-width: 130px; padding: 12px 20px; border-radius: 12px;
            border: none; background: transparent; font-size: 13.5px; font-weight: 700;
            color: var(--text-muted); cursor: pointer; transition: 0.25s;
            display: flex; align-items: center; justify-content: center;
            gap: 8px; font-family: inherit;
        }
        .main-tab:hover { background: #f3f4f6; color: var(--umak-blue); }
        .main-tab.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; box-shadow: 0 6px 18px rgba(0,91,159,0.3);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .daily-hero {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 24px; padding: 32px 36px; color: white;
            margin-bottom: 25px; position: relative; overflow: hidden;
            box-shadow: 0 15px 40px rgba(0,91,159,0.25);
        }
        .daily-hero::before {
            content: ''; position: absolute; top: -80px; right: -80px;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(255,206,0,0.18) 0%, transparent 70%);
            border-radius: 50%;
        }
        .daily-hero-content {
            position: relative; z-index: 2;
            display: flex; justify-content: space-between; align-items: center;
            gap: 30px; flex-wrap: wrap;
        }
        .daily-info h2 { margin: 0 0 6px; font-size: 28px; font-weight: 800; letter-spacing: -0.5px; }
        .daily-info p { margin: 0; font-size: 14px; color: rgba(255,255,255,0.8); font-weight: 500; }
        .daily-progress-ring { position: relative; width: 130px; height: 130px; flex-shrink: 0; }
        .daily-progress-ring svg { transform: rotate(-90deg); width: 100%; height: 100%; }
        .daily-progress-ring .track { fill: none; stroke: rgba(255,255,255,0.15); stroke-width: 10; }
        .daily-progress-ring .fill {
            fill: none; stroke: var(--umak-yellow); stroke-width: 10; stroke-linecap: round;
            stroke-dasharray: 339.292; stroke-dashoffset: 339.292; transition: stroke-dashoffset 1s ease;
        }
        .daily-progress-text {
            position: absolute; inset: 0; display: flex; flex-direction: column;
            align-items: center; justify-content: center;
        }
        .daily-progress-text .value { font-size: 30px; font-weight: 900; letter-spacing: -1px; line-height: 1; }
        .daily-progress-text .label { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.75; margin-top: 4px; font-weight: 700; }

        .macro-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 25px; }
        .macro-card {
            background: white; border-radius: 20px; padding: 22px;
            border: 1px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            transition: 0.25s; position: relative;
        }
        .macro-card:hover { transform: translateY(-4px); box-shadow: 0 12px 28px rgba(0,0,0,0.08); }
        .macro-card-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .macro-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: white; flex-shrink: 0;
        }
        .macro-icon.calories { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .macro-icon.protein { background: linear-gradient(135deg, #ef4444, #f87171); }
        .macro-icon.carbs { background: linear-gradient(135deg, #3b82f6, #60a5fa); }
        .macro-icon.fats { background: linear-gradient(135deg, #8b5cf6, #a78bfa); }
        .macro-card-header .macro-icon { display: none; }
        .macro-header-info { flex: 1; }
        .macro-label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); margin-bottom: 3px; }
        .macro-count { font-size: 24px; font-weight: 800; color: #111827; letter-spacing: -0.5px; line-height: 1; }
        .macro-count .unit { font-size: 13px; font-weight: 600; color: var(--text-muted); margin-left: 2px; }
        .macro-target { font-size: 11.5px; color: var(--text-muted); font-weight: 600; margin-top: 4px; }
        .macro-bar-track { height: 8px; background: #f3f4f6; border-radius: 4px; overflow: hidden; margin-top: 12px; }
        .macro-bar-fill { height: 100%; border-radius: 4px; width: 0%; transition: width 1s ease; }
        .macro-bar-fill.calories { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .macro-bar-fill.protein { background: linear-gradient(90deg, #ef4444, #f87171); }
        .macro-bar-fill.carbs { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .macro-bar-fill.fats { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
        .macro-bar-fill.over-target { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .macro-percent { position: absolute; top: 22px; right: 22px; font-size: 12px; font-weight: 800; color: var(--text-muted); }

        .water-card {
            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
            border-radius: 20px; padding: 24px 28px; margin-bottom: 25px;
            border: 1px solid rgba(14,165,233,0.2);
            display: flex; align-items: center; justify-content: space-between;
            gap: 20px; flex-wrap: wrap;
        }
        .water-info { display: flex; align-items: center; gap: 16px; flex: 1; min-width: 200px; }
        .water-icon {
            width: 60px; height: 60px; border-radius: 16px;
            background: linear-gradient(135deg, #0ea5e9, #38bdf8);
            color: white; display: flex; align-items: center; justify-content: center;
            font-size: 28px; flex-shrink: 0;
        }
        .water-info h3 { margin: 0 0 4px; font-size: 18px; font-weight: 800; color: #0c4a6e; }
        .water-info p { margin: 0; font-size: 13px; color: #0369a1; font-weight: 600; }
        .water-count { font-size: 20px; font-weight: 800; color: #0369a1; }
        .water-glasses { display: flex; gap: 6px; flex-wrap: wrap; }
        .water-glass {
            width: 36px; height: 44px; border-radius: 8px;
            border: 2px solid #0ea5e9; background: white;
            cursor: pointer; transition: 0.25s;
            display: flex; align-items: center; justify-content: center;
        }
        .water-glass:hover { transform: scale(1.1); }
        .water-glass.filled { background: linear-gradient(180deg, #38bdf8 0%, #0284c7 100%); border-color: #0284c7; }
        .water-glass.filled::after { content: '💧'; font-size: 20px; }

        .quick-log-bar {
            display: flex; gap: 12px; flex-wrap: wrap; justify-content: center;
            padding: 20px; background: white; border-radius: 16px;
            border: 2px dashed var(--border); margin-bottom: 25px;
        }
        .btn-quick-log {
            padding: 12px 20px; border-radius: 12px;
            border: 2px solid var(--umak-blue); background: white;
            color: var(--umak-blue); font-weight: 800; font-size: 13px;
            cursor: pointer; transition: 0.25s;
            display: inline-flex; align-items: center; gap: 8px;
            font-family: inherit; text-decoration: none;
        }
        .btn-quick-log:hover { background: var(--umak-blue); color: white; transform: translateY(-2px); }
        .btn-quick-log.danger { border-color: #ef4444; color: #ef4444; }
        .btn-quick-log.danger:hover { background: #ef4444; color: white; }

        .meal-tabs { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 6px; margin-bottom: 22px; }
        .meal-tabs::-webkit-scrollbar { height: 6px; }
        .meal-tabs::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
        .meal-tab {
            flex-shrink: 0; padding: 12px 20px; background: white;
            border: 2px solid var(--border); border-radius: 14px;
            cursor: pointer; font-size: 13px; font-weight: 700;
            color: var(--text-muted); transition: 0.25s;
            display: flex; align-items: center; gap: 10px; font-family: inherit;
        }
        .meal-tab:hover { border-color: rgba(0,91,159,0.3); color: var(--umak-blue); transform: translateY(-2px); }
        .meal-tab.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            border-color: transparent; color: white;
            box-shadow: 0 8px 20px rgba(0,91,159,0.3);
        }
        .meal-tab .tab-icon {
            width: 26px; height: 26px; border-radius: 8px;
            background: rgba(0,0,0,0.05);
            display: flex; align-items: center; justify-content: center; font-size: 14px;
        }
        .meal-tab.active .tab-icon { background: rgba(255,255,255,0.25); }
        .meal-tab .tab-count {
            background: rgba(0,0,0,0.08); padding: 2px 8px;
            border-radius: 10px; font-size: 11px; font-weight: 800;
        }
        .meal-tab.active .tab-count { background: rgba(255,255,255,0.25); }

        .meal-section { display: none; }
        .meal-section.active { display: block; animation: fadeIn 0.3s ease; }

        .meal-header-card {
            background: white; border-radius: 20px; padding: 24px 28px;
            border: 1px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 16px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
        }
        .meal-big-icon {
            width: 68px; height: 68px; border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            font-size: 30px; color: white; flex-shrink: 0;
        }
        .meal-big-info { flex: 1; min-width: 200px; }
        .meal-big-info h2 { margin: 0 0 4px; font-size: 22px; font-weight: 800; color: #111827; }
        .meal-big-info p { margin: 0; font-size: 13px; color: var(--text-muted); font-weight: 600; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .meal-summary-stats { display: flex; gap: 20px; flex-wrap: wrap; }
        .meal-summary-stat { text-align: center; padding: 8px 16px; background: #f9fafb; border-radius: 12px; min-width: 70px; }
        .meal-summary-stat .value { font-size: 18px; font-weight: 800; color: #111827; line-height: 1; }
        .meal-summary-stat .label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); font-weight: 700; margin-top: 4px; }

        .food-items-list {
            background: white; border-radius: 20px;
            border: 1px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            overflow: hidden; margin-bottom: 16px;
        }
        .food-row {
            display: flex; align-items: center; gap: 16px;
            padding: 18px 22px; border-bottom: 1px solid var(--border);
            transition: background 0.2s; cursor: pointer;
        }
        .food-row:last-child { border-bottom: none; }
        .food-row:hover { background: #f9fafb; }
        .food-row.checked {
            background: linear-gradient(90deg, rgba(46,125,50,0.06), transparent);
            border-left: 4px solid var(--umak-green);
        }
        .food-check {
            width: 32px; height: 32px; border-radius: 10px;
            border: 2.5px solid #d1d5db; background: white;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.25s; flex-shrink: 0;
            color: transparent; font-size: 18px; font-weight: 900;
        }
        .food-row:hover .food-check { border-color: var(--umak-blue); }
        .food-row.checked .food-check {
            background: linear-gradient(135deg, #2e7d32, #4ade80);
            border-color: #2e7d32; color: white; transform: scale(1.05);
        }
        .food-thumb {
            width: 56px; height: 56px; border-radius: 12px;
            object-fit: cover; flex-shrink: 0;
            background: #f3f4f6; border: 2px solid var(--border);
        }
        .food-row-info { flex: 1; min-width: 0; }
        .food-row-name { font-size: 15.5px; font-weight: 800; color: #111827; margin: 0 0 4px; }
        .food-row.checked .food-row-name {
            color: #166534; text-decoration: line-through;
            text-decoration-color: rgba(22,101,52,0.4); text-decoration-thickness: 2px;
        }
        .food-row-portion { font-size: 12.5px; color: var(--text-muted); font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .food-row-nutrients { display: flex; gap: 8px; flex-wrap: wrap; flex-shrink: 0; }
        .nutrient-chip {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 5px 11px; border-radius: 10px;
            font-size: 11px; font-weight: 800;
        }
        .nutrient-chip.cal { background: rgba(245,158,11,0.12); color: #b45309; }
        .nutrient-chip.prot { background: rgba(239,68,68,0.1); color: #b91c1c; }
        .nutrient-chip.carb { background: rgba(59,130,246,0.1); color: #1e40af; }
        .nutrient-chip.fat { background: rgba(139,92,246,0.1); color: #6b21a8; }
        .food-row-actions { display: flex; gap: 6px; opacity: 0; transition: opacity 0.2s; }
        .food-row:hover .food-row-actions { opacity: 1; }
        .icon-btn-sm {
            width: 32px; height: 32px; border-radius: 8px;
            border: none; background: #f3f4f6; color: #6b7280;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.2s; font-size: 14px;
        }
        .icon-btn-sm:hover { background: #fee2e2; color: #dc2626; }

        .suggested-filters { display: flex; gap: 8px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 4px; flex-wrap: wrap; }
        .suggest-filter {
            padding: 8px 16px; border-radius: 20px;
            background: white; border: 2px solid var(--border);
            font-size: 12px; font-weight: 700; color: var(--text-muted);
            text-decoration: none; cursor: pointer; transition: 0.2s; white-space: nowrap;
        }
        .suggest-filter:hover { border-color: var(--umak-blue); color: var(--umak-blue); }
        .suggest-filter.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            border-color: transparent; color: white;
        }

        .suggested-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 18px;
        }
        .suggested-card {
            background: white; border-radius: 18px;
            overflow: hidden; border: 2px solid var(--border);
            transition: 0.3s; display: flex; flex-direction: column;
        }
        .suggested-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 40px rgba(0,0,0,0.12);
            border-color: rgba(0,91,159,0.25);
        }
        .suggested-image {
            width: 100%; height: 160px; object-fit: cover;
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
        }
        .suggested-body { padding: 18px; flex: 1; display: flex; flex-direction: column; }
        .suggested-type {
            display: inline-block; padding: 3px 10px; border-radius: 8px;
            font-size: 10px; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.8px; margin-bottom: 10px;
            background: rgba(0,91,159,0.1); color: var(--umak-blue); align-self: flex-start;
        }
        .suggested-name { font-size: 15px; font-weight: 800; color: #111827; margin: 0 0 6px; line-height: 1.3; }
        .suggested-desc {
            font-size: 12.5px; color: var(--text-muted);
            line-height: 1.5; margin: 0 0 12px;
            display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden; flex: 1;
        }
        .suggested-macros { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
        .suggested-macro { font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 8px; }
        .suggested-macro.cal { background: rgba(245,158,11,0.12); color: #b45309; }
        .suggested-macro.prot { background: rgba(239,68,68,0.1); color: #b91c1c; }
        .suggested-macro.carb { background: rgba(59,130,246,0.1); color: #1e40af; }
        .suggested-macro.fat { background: rgba(139,92,246,0.1); color: #6b21a8; }

        .btn-add-suggested {
            width: 100%; padding: 10px 16px; border-radius: 10px; border: none;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; font-weight: 800; font-size: 12.5px;
            cursor: pointer; transition: 0.25s; font-family: inherit;
            display: flex; align-items: center; justify-content: center;
            gap: 8px; margin-top: auto;
        }
        .btn-add-suggested:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,91,159,0.3); }

        .progress-table {
            width: 100%; border-collapse: collapse; font-size: 13.5px;
            background: white; border-radius: 16px;
            overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }
        .progress-table th {
            padding: 14px 16px; text-align: left;
            color: var(--text-muted); font-weight: 700;
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 1px; background: #f9fafb; border-bottom: 1px solid var(--border);
        }
        .progress-table td { padding: 14px 16px; border-bottom: 1px solid var(--border); }
        .progress-table tbody tr:hover { background: #f9fafb; }
        .progress-bar-mini { height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden; margin-top: 4px; }
        .progress-bar-mini-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #2e7d32, #4ade80); }
        .progress-value { font-weight: 800; color: #111827; }

        .empty-state { text-align: center; padding: 70px 20px; background: white; border-radius: 20px; border: 2px dashed var(--border); }
        .empty-state-icon {
            width: 90px; height: 90px; margin: 0 auto 20px;
            background: rgba(0,91,159,0.08); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 42px; color: var(--umak-blue);
        }
        .empty-state h3 { margin: 0 0 10px; color: var(--umak-blue); font-size: 20px; font-weight: 800; }
        .empty-state p { color: var(--text-muted); font-size: 14px; margin: 0 0 22px; line-height: 1.6; max-width: 400px; margin-left: auto; margin-right: auto; }

        .tips-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 20px; padding: 26px 28px;
            border: 1px solid rgba(245,158,11,0.3); margin-bottom: 25px;
        }
        .tips-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .tips-header-icon {
            width: 44px; height: 44px; border-radius: 12px;
            background: rgba(245,158,11,0.25); color: #92400e;
            display: flex; align-items: center; justify-content: center; font-size: 22px;
        }
        .tips-header h3 { margin: 0; font-size: 17px; font-weight: 800; color: #78350f; }
        .tips-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
        .tip-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 14px; background: rgba(255,255,255,0.6);
            border-radius: 12px; font-size: 13px;
            color: #78350f; font-weight: 600; line-height: 1.5;
        }
        .tip-item i { color: #d97706; font-size: 16px; flex-shrink: 0; margin-top: 1px; }

        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; visibility: hidden; transition: 0.3s;
            z-index: 2000; padding: 20px;
        }
        .modal-overlay.open { opacity: 1; visibility: visible; }
        .modal {
            background: white; border-radius: 20px;
            width: 100%; max-width: 520px; max-height: 90vh;
            overflow-y: auto; transform: scale(0.9);
            transition: 0.3s; box-shadow: 0 25px 70px rgba(0,0,0,0.3);
        }
        .modal-overlay.open .modal { transform: scale(1); }
        .modal-header {
            padding: 22px 26px; border-bottom: 2px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; background: white; z-index: 2; border-radius: 20px 20px 0 0;
        }
        .modal-header h3 { margin: 0; font-size: 18px; font-weight: 800; color: var(--umak-blue); display: flex; align-items: center; gap: 10px; }
        .modal-close {
            background: #f3f4f6; border: none; width: 36px; height: 36px;
            border-radius: 50%; cursor: pointer; display: flex;
            align-items: center; justify-content: center;
            font-size: 16px; color: var(--text-muted); transition: all 0.2s;
        }
        .modal-close:hover { background: #e5e7eb; transform: rotate(90deg); }
        .modal-body { padding: 24px 26px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 12px; font-weight: 800; color: #374151; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 8px; }
        .form-control {
            width: 100%; padding: 12px 14px; border: 2px solid var(--border);
            border-radius: 10px; font-size: 14px; font-weight: 500;
            background: #f9fafb; transition: 0.25s; font-family: inherit;
            outline: none; color: #111827;
        }
        .form-control:focus { border-color: var(--umak-blue); background: white; box-shadow: 0 0 0 4px rgba(0,91,159,0.1); }
        .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-row-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
        .modal-footer { padding: 18px 26px 22px; display: flex; gap: 10px; justify-content: flex-end; border-top: 1px solid var(--border); }
        .btn {
            padding: 12px 24px; border-radius: 10px; font-size: 14px;
            font-weight: 700; cursor: pointer; border: none;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; transition: 0.25s; text-decoration: none; font-family: inherit;
        }
        .btn-primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; box-shadow: 0 4px 12px rgba(0,91,159,0.25);
        }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-outline { background: white; color: var(--umak-blue); border: 2px solid var(--umak-blue); }
        .btn-outline:hover { background: var(--umak-blue); color: white; }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
        }
        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .daily-hero { padding: 24px 22px; }
            .daily-info h2 { font-size: 22px; }
            .daily-progress-ring { width: 100px; height: 100px; }
            .daily-progress-text .value { font-size: 24px; }
            .macro-grid { grid-template-columns: 1fr 1fr; }
            .food-row { padding: 14px 16px; gap: 12px; flex-wrap: wrap; }
            .food-row-nutrients { width: 100%; margin-left: 100px; }
            .food-row-actions { opacity: 1; }
            .meal-summary-stats { width: 100%; justify-content: space-between; }
            .form-row-4 { grid-template-columns: 1fr 1fr; }
            .suggested-grid { grid-template-columns: 1fr; }
            .btn-chat-open span { display: none; }
        }
        @media (max-width: 576px) {
            .macro-grid { grid-template-columns: 1fr; }
            .water-card { flex-direction: column; align-items: flex-start; }
            .food-row-nutrients { margin-left: 0; }
            .form-row-2, .form-row-4 { grid-template-columns: 1fr; }
            .meal-tabs { gap: 6px; }
            .meal-tab { padding: 10px 14px; font-size: 12px; }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css"></head>
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
        <a href="user-dashboard.php" class="nav-item"><i class="bi bi-house-door-fill"></i><span>Dashboard</span></a>
        <a href="my-membership.php" class="nav-item"><i class="bi bi-ticket-perforated-fill"></i><span>My Membership</span></a>
        <a href="attendance.php" class="nav-item"><i class="bi bi-bar-chart-fill"></i><span>Attendance</span></a>

        <div class="nav-section-title">Fitness</div>
        <a href="workout-plan.php" class="nav-item"><i class="bi bi-heart-pulse-fill"></i><span>Workout Plan</span></a>
        <a href="nutrition-plan.php" class="nav-item active"><i class="bi bi-egg-fried"></i><span>Nutrition Plan</span></a>
        <a href="my-progress.php" class="nav-item"><i class="bi bi-graph-up-arrow"></i><span>My Progress</span></a>

        <div class="nav-section-title">Services</div>
        <a href="classes.php" class="nav-item"><i class="bi bi-calendar-event-fill"></i><span>Classes & Booking</span></a>
        <a href="payments.php" class="nav-item"><i class="bi bi-credit-card-fill"></i><span>Payments & Billing</span></a>
        <a href="notifications.php" class="nav-item">
            <i class="bi bi-bell-fill"></i><span>Notifications</span>
            <?php if ($unreadNotifCount > 0): ?><span class="badge-count"><?= $unreadNotifCount ?></span><?php endif; ?>
        </a>

        <div class="nav-section-title">Account</div>
        <a href="my-profile.php" class="nav-item"><i class="bi bi-person-circle"></i><span>My Profile</span></a>
        <a href="help.php" class="nav-item"><i class="bi bi-life-preserver"></i><span>Help & Support</span></a>
        <a href="settings.php" class="nav-item"><i class="bi bi-gear-fill"></i><span>Settings</span></a>
        <div class="nav-divider"></div>
        <a href="logout.php" class="nav-item nav-logout"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </nav>
</aside>

<!-- MAIN -->
<div class="main-wrapper">
    <header class="top-header">
        <div class="header-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
            <div>
                <h2>My Nutrition Plan</h2>
                <p class="subtitle">Track meals, macros, and hydration</p>
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
                <?php if ($unreadNotifCount > 0): ?><span class="notif-badge"><?= $unreadNotifCount ?></span><?php endif; ?>
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
            <i class="bi bi-egg-fried"></i>
            My Nutrition Plan
        </h1>
        <p class="page-subtitle">Log meals, track macros, and stay hydrated.</p>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'error' ?>">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>" style="font-size: 20px;"></i>
                <div><?= htmlspecialchars($message) ?></div>
            </div>
        <?php endif; ?>

        <div class="main-tabs">
            <button class="main-tab <?= $initialTab === 'today' ? 'active' : '' ?>" onclick="switchTab(this, 'tab-today')">
                <i class="bi bi-calendar-day-fill"></i> Today
            </button>
            <button class="main-tab <?= $initialTab === 'suggested' ? 'active' : '' ?>" onclick="switchTab(this, 'tab-suggested')">
                <i class="bi bi-stars"></i> Suggested Meals
            </button>
            <button class="main-tab <?= $initialTab === 'progress' ? 'active' : '' ?>" onclick="switchTab(this, 'tab-progress')">
                <i class="bi bi-graph-up-arrow"></i> Progress
            </button>
        </div>

        <!-- TAB: TODAY -->
        <div class="tab-content <?= $initialTab === 'today' ? 'active' : '' ?>" id="tab-today">

            <div class="daily-hero">
                <div class="daily-hero-content">
                    <div class="daily-info">
                        <h2>Today's Progress</h2>
                        <p>
                            <i class="bi bi-calendar3"></i> <?= date('l, F j, Y') ?>
                            <span style="opacity: 0.5; margin: 0 8px;">·</span>
                            <i class="bi bi-check2-circle"></i> <?= $completedItems ?> of <?= $totalItems ?> items logged
                        </p>
                    </div>
                    <div class="daily-progress-ring">
                        <svg viewBox="0 0 120 120">
                            <circle class="track" cx="60" cy="60" r="54"></circle>
                            <circle class="fill" cx="60" cy="60" r="54" id="dailyRing"></circle>
                        </svg>
                        <div class="daily-progress-text">
                            <div class="value" id="dailyProgressValue">0%</div>
                            <div class="label">Complete</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="macro-grid">
                <div class="macro-card">
                    <div class="macro-percent"><?= $calPct ?>%</div>
                    <div class="macro-card-header">
                        <div class="macro-icon calories"><i class="bi bi-fire"></i></div>
                        <div class="macro-header-info">
                            <div class="macro-label">Calories</div>
                            <div class="macro-count"><?= $totalCal ?><span class="unit">/ <?= $dailyCalories ?></span></div>
                            <div class="macro-target"><?= max(0, $dailyCalories - $totalCal) ?> kcal remaining</div>
                        </div>
                    </div>
                    <div class="macro-bar-track">
                        <div class="macro-bar-fill calories <?= $calPct > 100 ? 'over-target' : '' ?>" style="width: 0%" data-target="<?= min(100, $calPct) ?>%"></div>
                    </div>
                </div>

                <div class="macro-card">
                    <div class="macro-percent"><?= $protPct ?>%</div>
                    <div class="macro-card-header">
                        <div class="macro-icon protein"><i class="bi bi-droplet-fill"></i></div>
                        <div class="macro-header-info">
                            <div class="macro-label">Protein</div>
                            <div class="macro-count"><?= $totalProt ?><span class="unit">/ <?= $dailyProtein ?>g</span></div>
                            <div class="macro-target"><?= max(0, $dailyProtein - $totalProt) ?>g remaining</div>
                        </div>
                    </div>
                    <div class="macro-bar-track">
                        <div class="macro-bar-fill protein <?= $protPct > 100 ? 'over-target' : '' ?>" style="width: 0%" data-target="<?= min(100, $protPct) ?>%"></div>
                    </div>
                </div>

                <div class="macro-card">
                    <div class="macro-percent"><?= $carbPct ?>%</div>
                    <div class="macro-card-header">
                        <div class="macro-icon carbs"><i class="bi bi-lightning-charge-fill"></i></div>
                        <div class="macro-header-info">
                            <div class="macro-label">Carbs</div>
                            <div class="macro-count"><?= $totalCarb ?><span class="unit">/ <?= $dailyCarbs ?>g</span></div>
                            <div class="macro-target"><?= max(0, $dailyCarbs - $totalCarb) ?>g remaining</div>
                        </div>
                    </div>
                    <div class="macro-bar-track">
                        <div class="macro-bar-fill carbs <?= $carbPct > 100 ? 'over-target' : '' ?>" style="width: 0%" data-target="<?= min(100, $carbPct) ?>%"></div>
                    </div>
                </div>

                <div class="macro-card">
                    <div class="macro-percent"><?= $fatPct ?>%</div>
                    <div class="macro-card-header">
                        <div class="macro-icon fats"><i class="bi bi-moisture"></i></div>
                        <div class="macro-header-info">
                            <div class="macro-label">Fats</div>
                            <div class="macro-count"><?= $totalFat ?><span class="unit">/ <?= $dailyFats ?>g</span></div>
                            <div class="macro-target"><?= max(0, $dailyFats - $totalFat) ?>g remaining</div>
                        </div>
                    </div>
                    <div class="macro-bar-track">
                        <div class="macro-bar-fill fats <?= $fatPct > 100 ? 'over-target' : '' ?>" style="width: 0%" data-target="<?= min(100, $fatPct) ?>%"></div>
                    </div>
                </div>
            </div>

            <div class="water-card">
                <div class="water-info">
                    <div class="water-icon"><i class="bi bi-droplet-fill"></i></div>
                    <div>
                        <h3>Water Intake</h3>
                        <p>Aim for 8 glasses · <span class="water-count" id="waterCount"><?= $waterGlasses ?><span> / 8</span></span></p>
                    </div>
                </div>
                <div class="water-glasses" id="waterGlasses">
                    <?php for ($i = 1; $i <= 8; $i++): ?>
                        <div class="water-glass <?= $i <= $waterGlasses ? 'filled' : '' ?>" onclick="toggleWater(<?= $i ?>)"></div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="quick-log-bar">
                <button class="btn-quick-log" onclick="openQuickLog()">
                    <i class="bi bi-plus-circle-fill"></i> Quick Log Food
                </button>
                <button class="btn-quick-log" onclick="switchToSuggested()">
                    <i class="bi bi-stars"></i> Browse Suggested Meals
                </button>
                <?php if ($totalItems > 0): ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Clear all items from your plan?');">
                        <input type="hidden" name="action" value="clear_plan">
                        <?php echo app_csrf_field(); ?>
                        <button type="submit" class="btn-quick-log danger">
                            <i class="bi bi-trash3-fill"></i> Clear Plan
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (empty($nutritionPlan)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-clipboard-x"></i></div>
                    <h3>No Meal Plan Yet</h3>
                    <p>Start by browsing suggested meals or logging your own food.</p>
                    <button class="btn btn-primary" onclick="switchToSuggested()">
                        <i class="bi bi-stars"></i> Browse Suggested Meals
                    </button>
                </div>
            <?php else: ?>
                <div class="meal-tabs">
                    <?php $firstTab = true; foreach ($mealConfig as $mealType => $config):
                        if (!isset($nutritionPlan[$mealType])) continue;
                        $mt = $mealTotals[$mealType];
                    ?>
                        <button class="meal-tab <?= $firstTab ? 'active' : '' ?>" onclick="switchMealTab(this, 'meal-<?= md5($mealType) ?>')">
                            <span class="tab-icon"><i class="bi <?= $config['icon'] ?>"></i></span>
                            <span><?= $config['label'] ?></span>
                            <span class="tab-count"><?= $mt['done'] ?>/<?= $mt['total'] ?></span>
                        </button>
                    <?php $firstTab = false; endforeach; ?>
                </div>

                <?php $firstSection = true; foreach ($mealConfig as $mealType => $config):
                    if (!isset($nutritionPlan[$mealType])) continue;
                    $items = $nutritionPlan[$mealType];
                    $mt = $mealTotals[$mealType];
                ?>
                    <div class="meal-section <?= $firstSection ? 'active' : '' ?>" id="meal-<?= md5($mealType) ?>">
                        <div class="meal-header-card">
                            <div class="meal-big-icon" style="background: linear-gradient(135deg, <?= $config['color'] ?>, <?= $config['color'] ?>dd);">
                                <i class="bi <?= $config['icon'] ?>"></i>
                            </div>
                            <div class="meal-big-info">
                                <h2><?= htmlspecialchars($config['label']) ?></h2>
                                <p>
                                    <span><i class="bi bi-clock-fill"></i> <?= $config['time'] ?></span>
                                    <span style="opacity: 0.4;">·</span>
                                    <span><?= $mt['total'] ?> item<?= $mt['total'] != 1 ? 's' : '' ?></span>
                                </p>
                            </div>
                            <div class="meal-summary-stats">
                                <div class="meal-summary-stat"><div class="value"><?= $mt['cal'] ?></div><div class="label">kcal</div></div>
                                <div class="meal-summary-stat"><div class="value"><?= $mt['prot'] ?>g</div><div class="label">protein</div></div>
                                <div class="meal-summary-stat"><div class="value"><?= $mt['carb'] ?>g</div><div class="label">carbs</div></div>
                                <div class="meal-summary-stat"><div class="value"><?= $mt['fat'] ?>g</div><div class="label">fats</div></div>
                            </div>
                        </div>

                        <div class="food-items-list">
                            <?php foreach ($items as $item):
                                $itemId = (int)$item['id'];
                                $isChecked = isset($completedToday[$itemId]);
                            ?>
                                <div class="food-row <?= $isChecked ? 'checked' : '' ?>" id="food-row-<?= $itemId ?>">
                                    <div class="food-check" onclick="toggleItem(<?= $itemId ?>); event.stopPropagation();">✓</div>
                                    <?php if (!empty($item['image_url'])): ?>
                                        <img src="<?= htmlspecialchars($item['image_url']) ?>" class="food-thumb" alt="" onerror="this.style.display='none'">
                                    <?php else: ?>
                                        <div class="food-thumb" style="display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 20px;">
                                            <i class="bi bi-egg-fried"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="food-row-info" onclick="toggleItem(<?= $itemId ?>)">
                                        <div class="food-row-name"><?= htmlspecialchars($item['item_name']) ?></div>
                                        <?php if (!empty($item['portion'])): ?>
                                            <div class="food-row-portion">
                                                <i class="bi bi-basket2-fill"></i> <?= htmlspecialchars($item['portion']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="food-row-nutrients">
                                        <span class="nutrient-chip cal"><i class="bi bi-fire"></i> <?= (int)$item['calories'] ?></span>
                                        <span class="nutrient-chip prot">P <?= (int)$item['protein'] ?>g</span>
                                        <span class="nutrient-chip carb">C <?= (int)$item['carbs'] ?>g</span>
                                        <span class="nutrient-chip fat">F <?= (int)$item['fats'] ?>g</span>
                                    </div>
                                    <div class="food-row-actions">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_item">
                                            <?php echo app_csrf_field(); ?>
                                            <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                            <button type="submit" class="icon-btn-sm" onclick="return confirm('Remove this item?');">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php $firstSection = false; endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- TAB: SUGGESTED -->
        <div class="tab-content <?= $initialTab === 'suggested' ? 'active' : '' ?>" id="tab-suggested">
            <div class="daily-hero" style="padding: 28px 32px;">
                <div class="daily-hero-content">
                    <div class="daily-info">
                        <h2 style="font-size: 24px;"><i class="bi bi-stars" style="color: var(--umak-yellow);"></i> Suggested Meals</h2>
                        <p>Handpicked meals to jumpstart your nutrition. Click any card to add.</p>
                    </div>
                </div>
            </div>

            <div class="suggested-filters">
                <a href="?suggest=all&cat=<?= urlencode($filterCat) ?>#tab-suggested" class="suggest-filter <?= $filterType === 'all' ? 'active' : '' ?>">All Meals</a>
                <?php foreach ($mealConfig as $key => $cfg): ?>
                    <a href="?suggest=<?= urlencode($key) ?>&cat=<?= urlencode($filterCat) ?>#tab-suggested" class="suggest-filter <?= $filterType === $key ? 'active' : '' ?>">
                        <?= htmlspecialchars($cfg['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($suggestedCats)): ?>
                <div class="suggested-filters" style="margin-top: -12px;">
                    <a href="?suggest=<?= urlencode($filterType) ?>&cat=all#tab-suggested" class="suggest-filter <?= $filterCat === 'all' ? 'active' : '' ?>">All</a>
                    <?php foreach ($suggestedCats as $cat): ?>
                        <a href="?suggest=<?= urlencode($filterType) ?>&cat=<?= urlencode($cat) ?>#tab-suggested" class="suggest-filter <?= $filterCat === $cat ? 'active' : '' ?>">
                            <?= htmlspecialchars($cat) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($suggestedMeals)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-stars"></i></div>
                    <h3>No Suggested Meals</h3>
                    <p>Try a different filter or check back later.</p>
                </div>
            <?php else: ?>
                <div class="suggested-grid">
                    <?php foreach ($suggestedMeals as $meal): ?>
                        <div class="suggested-card">
                            <img class="suggested-image"
                                 src="<?= htmlspecialchars($meal['image_url'] ?: 'uploads/logo.png') ?>"
                                 alt="<?= htmlspecialchars($meal['name']) ?>"
                                 onerror="this.src='uploads/logo.png'">
                            <div class="suggested-body">
                                <span class="suggested-type"><?= htmlspecialchars($meal['meal_type']) ?></span>
                                <h3 class="suggested-name"><?= htmlspecialchars($meal['name']) ?></h3>
                                <p class="suggested-desc"><?= htmlspecialchars($meal['description'] ?? '') ?></p>
                                <div class="suggested-macros">
                                    <span class="suggested-macro cal"><i class="bi bi-fire" aria-hidden="true"></i> <?= (int)$meal['calories'] ?></span>
                                    <span class="suggested-macro prot">P <?= (int)$meal['protein'] ?>g</span>
                                    <span class="suggested-macro carb">C <?= (int)$meal['carbs'] ?>g</span>
                                    <span class="suggested-macro fat">F <?= (int)$meal['fats'] ?>g</span>
                                </div>
                                <form method="POST" style="margin-top: auto;">
                                    <input type="hidden" name="action" value="add_suggested">
                                    <?php echo app_csrf_field(); ?>
                                    <input type="hidden" name="suggested_id" value="<?= (int)$meal['id'] ?>">
                                    <button type="submit" class="btn-add-suggested">
                                        <i class="bi bi-plus-circle-fill"></i> Add to My Plan
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB: PROGRESS -->
        <div class="tab-content <?= $initialTab === 'progress' ? 'active' : '' ?>" id="tab-progress">
            <div class="daily-hero" style="padding: 28px 32px;">
                <div class="daily-hero-content">
                    <div class="daily-info">
                        <h2 style="font-size: 24px;"><i class="bi bi-graph-up-arrow" style="color: var(--umak-yellow);"></i> Nutrition Progress</h2>
                        <p>Track your daily calorie and macro adherence.</p>
                    </div>
                </div>
            </div>

            <?php if (empty($progressHistory)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-graph-up-arrow"></i></div>
                    <h3>No Progress Yet</h3>
                    <p>Start logging meals to see your history.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="progress-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Calories</th>
                                <th>Protein</th>
                                <th>Carbs</th>
                                <th>Fats</th>
                                <th>Water</th>
                                <th>Completion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($progressHistory as $p):
                                $isToday = $p['progress_date'] === $today;
                                $pct = (int)$p['completion_percent'];
                            ?>
                                <tr <?= $isToday ? 'style="background: rgba(0,91,159,0.04);"' : '' ?>>
                                    <td>
                                        <strong><?= date('M j, Y', strtotime($p['progress_date'])) ?></strong>
                                        <?php if ($isToday): ?>
                                            <span style="background: var(--umak-yellow); color: #333; padding: 2px 8px; border-radius: 8px; font-size: 10px; font-weight: 800; margin-left: 6px;">TODAY</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="progress-value"><?= number_format((int)$p['calories_logged']) ?></span> kcal</td>
                                    <td><span class="progress-value"><?= (int)$p['protein_logged'] ?>g</span></td>
                                    <td><span class="progress-value"><?= (int)$p['carbs_logged'] ?>g</span></td>
                                    <td><span class="progress-value"><?= (int)$p['fats_logged'] ?>g</span></td>
                                    <td><span class="progress-value"><?= (int)$p['water_glasses'] ?>/8</span></td>
                                    <td style="min-width: 140px;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="flex: 1;">
                                                <div class="progress-bar-mini">
                                                    <div class="progress-bar-mini-fill" style="width: <?= $pct ?>%"></div>
                                                </div>
                                            </div>
                                            <span class="progress-value" style="font-size: 13px;"><?= $pct ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="tips-card" style="margin-top: 25px;">
            <div class="tips-header">
                <div class="tips-header-icon"><i class="bi bi-lightbulb-fill"></i></div>
                <h3>Nutrition Tips</h3>
            </div>
            <div class="tips-grid">
                <div class="tip-item"><i class="bi bi-check-circle-fill"></i><span>Eat protein with every meal to support muscle recovery.</span></div>
                <div class="tip-item"><i class="bi bi-check-circle-fill"></i><span>Drink 8+ glasses of water daily.</span></div>
                <div class="tip-item"><i class="bi bi-check-circle-fill"></i><span>Never skip breakfast — it kickstarts your metabolism.</span></div>
                <div class="tip-item"><i class="bi bi-check-circle-fill"></i><span>Choose whole foods over processed snacks.</span></div>
                <div class="tip-item"><i class="bi bi-check-circle-fill"></i><span>Last meal 2-3 hours before bedtime.</span></div>
                <div class="tip-item"><i class="bi bi-check-circle-fill"></i><span>Prep meals ahead to stay on track.</span></div>
            </div>
        </div>

    </main>
</div>

<!-- QUICK LOG MODAL -->
<div class="modal-overlay" id="quickLogModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-plus-circle-fill"></i> Quick Log Food</h3>
            <button class="modal-close" onclick="closeQuickLog()"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="quick_log">
            <?php echo app_csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Food Name</label>
                    <input type="text" name="item_name" class="form-control" placeholder="e.g., Grilled Chicken" required autofocus>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">Meal</label>
                        <select name="meal_type" class="form-control" required>
                            <?php foreach ($mealConfig as $key => $cfg): ?>
                                <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($cfg['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Portion</label>
                        <input type="text" name="portion" class="form-control" placeholder="e.g., 150g">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Nutrition (per serving)</label>
                    <div class="form-row-4">
                        <input type="number" name="calories" class="form-control" placeholder="kcal" min="0" required>
                        <input type="number" name="protein" class="form-control" placeholder="P (g)" min="0">
                        <input type="number" name="carbs" class="form-control" placeholder="C (g)" min="0">
                        <input type="number" name="fats" class="form-control" placeholder="F (g)" min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Image URL (Optional)</label>
                    <input type="url" name="image_url" class="form-control" placeholder="https://...">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeQuickLog()">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle-fill"></i> Log Food</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleSidebar() { 
        document.getElementById('sidebar').classList.toggle('mobile-open'); 
    }
    
    document.addEventListener('click', function(e) {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        if (window.innerWidth <= 992 && !sidebar.contains(e.target) && toggle && !toggle.contains(e.target)) {
            sidebar.classList.remove('mobile-open');
        }
    });

    function switchTab(btn, id) {
        document.querySelectorAll('.main-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(id).classList.add('active');
        history.replaceState(null, null, '#' + id);
    }

    function switchToSuggested() {
        const btn = Array.from(document.querySelectorAll('.main-tab')).find(b => b.getAttribute('onclick').includes('tab-suggested'));
        if (btn) switchTab(btn, 'tab-suggested');
    }

    function switchMealTab(btn, id) {
        document.querySelectorAll('.meal-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.meal-section').forEach(s => s.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(id).classList.add('active');
    }

    document.addEventListener('DOMContentLoaded', function() {
        const ring = document.getElementById('dailyRing');
        const val = document.getElementById('dailyProgressValue');
        const target = <?= $overallPct ?>;

        if (ring) {
            const c = 2 * Math.PI * 54;
            const off = c - (target / 100) * c;
            setTimeout(() => { ring.style.strokeDashoffset = off; }, 200);
        }

        if (val) {
            let n = 0;
            const step = Math.max(1, Math.ceil(target / 30));
            const t = setInterval(() => {
                n = Math.min(target, n + step);
                val.textContent = n + '%';
                if (n >= target) clearInterval(t);
            }, 30);
        }

        document.querySelectorAll('.macro-bar-fill[data-target]').forEach(bar => {
            setTimeout(() => { bar.style.width = bar.dataset.target; }, 300);
        });

        const h = window.location.hash;
        if (h === '#tab-suggested' || h === '#tab-progress' || h === '#tab-today') {
            const btn = Array.from(document.querySelectorAll('.main-tab')).find(b => b.getAttribute('onclick').includes(h.substring(1)));
            if (btn) switchTab(btn, h.substring(1));
        }
    });

    const csrfToken = <?php echo json_encode(app_csrf_token()); ?>;

    function toggleItem(id) {
        const row = document.getElementById('food-row-' + id);
        if (!row) return;
        const wasChecked = row.classList.contains('checked');
        
        if (wasChecked) row.classList.remove('checked');
        else row.classList.add('checked');

        const fd = new FormData();
        fd.append('action', 'toggle_item');
        fd.append('item_id', id);
        fd.append('csrf_token', csrfToken);

        fetch('nutrition-plan.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(r => r.json())
        .then(d => { 
            if (d.success) setTimeout(() => window.location.reload(), 300);
            else {
                if (wasChecked) row.classList.add('checked');
                else row.classList.remove('checked');
            }
        })
        .catch(err => {
            console.error(err);
            if (wasChecked) row.classList.add('checked');
            else row.classList.remove('checked');
        });
    }

    function toggleWater(index) {
        const glasses = document.querySelectorAll('.water-glass');
        const filled = document.querySelectorAll('.water-glass.filled').length;
        let newCount = (index <= filled && index === filled) ? index - 1 : index;

        glasses.forEach((g, i) => {
            if (i < newCount) g.classList.add('filled');
            else g.classList.remove('filled');
        });

        document.getElementById('waterCount').innerHTML = newCount + '<span> / 8</span>';

        const fd = new FormData();
        fd.append('action', 'update_water');
        fd.append('glasses', newCount);
        fd.append('csrf_token', csrfToken);
        fetch('nutrition-plan.php', { 
            method: 'POST', 
            headers: { 'X-Requested-With': 'XMLHttpRequest' }, 
            body: fd 
        })
        .then(r => r.json())
        .then(d => { if (!d.success) window.location.reload(); })
        .catch(e => console.error(e));
    }

    function openQuickLog() {
        document.getElementById('quickLogModal').classList.add('open');
        document.body.style.overflow = 'hidden';
        setTimeout(() => document.querySelector('#quickLogModal input[name="item_name"]')?.focus(), 300);
    }
    
    function closeQuickLog() {
        document.getElementById('quickLogModal').classList.remove('open');
        document.body.style.overflow = '';
    }
    
    document.getElementById('quickLogModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeQuickLog();
    });
    
    document.addEventListener('keydown', e => { 
        if (e.key === 'Escape') closeQuickLog(); 
    });

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.alert-success').forEach(a => {
            setTimeout(() => {
                a.style.transition = 'opacity 0.5s, transform 0.5s';
                a.style.opacity = '0';
                a.style.transform = 'translateY(-10px)';
                setTimeout(() => a.style.display = 'none', 500);
            }, 4000);
        });
    });
</script>
<?php include 'chat_widget.php'; ?>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>