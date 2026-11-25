<?php
session_start();
include 'conn.php';

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
            if (!empty($types)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            return $stmt->get_result();
        } else {
            return $conn->query($sql);
        }
    } catch (mysqli_sql_exception $e) {
        error_log("SafeQuery Error: " . $e->getMessage() . " | SQL: " . $sql);
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
// FETCH MEMBERSHIP DETAILS
// ============================================
$membership = null;
$hasMembership = false;

if (tableExists($conn, 'memberships')) {
    $result = safeQuery($conn,
        "SELECT id, membership_type AS plan_name, status, start_date, end_date AS expiry_date, amount AS price, created_at 
         FROM memberships 
         WHERE user_id = ? 
         ORDER BY end_date DESC, id DESC 
         LIMIT 1",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $membership = $row;
        $hasMembership = true;
    }
}

// Calculate days left
$daysLeft = 0;
$progressPercent = 0;
$totalDays = 0;
$usedDays = 0;
$isExpiringSoon = false;
$isExpired = false;

if ($hasMembership && !empty($membership['expiry_date']) && !empty($membership['start_date'])) {
    $start = new DateTime($membership['start_date']);
    $expiry = new DateTime($membership['expiry_date']);
    $now = new DateTime();

    $totalDays = max(1, (int)$start->diff($expiry)->days);
    $usedDays = max(0, (int)$start->diff($now)->days);

    if ($now > $expiry) {
        $isExpired = true;
        $daysLeft = 0;
        $usedDays = $totalDays;
    } else {
        $diff = $now->diff($expiry);
        $daysLeft = (int)$diff->days;
        $isExpiringSoon = $daysLeft <= 7 && $daysLeft > 0;
    }

    $progressPercent = min(100, round(($usedDays / $totalDays) * 100));
}

// Default benefits (customize based on plan)
$planBenefits = [
    'Full Gym Access'       => true,
    'Equipment Access'      => true,
    'Group Classes'         => true,
    'Locker Access'         => true,
    'Personal Trainer'      => false,
    'Nutrition Consultation'=> false,
    'Guest Passes'          => false,
];

// Override benefits based on plan name (customize this logic)
if ($hasMembership) {
    $planLower = strtolower($membership['plan_name']);
    if (strpos($planLower, 'premium') !== false) {
        $planBenefits['Personal Trainer'] = true;
        $planBenefits['Nutrition Consultation'] = true;
        $planBenefits['Guest Passes'] = true;
    } elseif (strpos($planLower, 'elite') !== false || strpos($planLower, 'vip') !== false) {
        $planBenefits = array_fill_keys(array_keys($planBenefits), true);
    }
}

// ============================================
// FETCH MEMBERSHIP HISTORY
// ============================================
$history = [];
if (tableExists($conn, 'memberships')) {
    $result = safeQuery($conn,
        "SELECT id, membership_type AS plan_name, status, start_date, end_date AS expiry_date, amount AS price 
         FROM memberships 
         WHERE user_id = ? 
         ORDER BY start_date DESC 
         LIMIT 10",
        "i", [$userId]
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
    }
}

// ============================================
// FETCH UPCOMING BILLING
// ============================================
$nextPayment = null;
if (tableExists($conn, 'transactions')) {
    $result = safeQuery($conn,
        "SELECT reference_no, description, amount, created_at, status 
         FROM transactions 
         WHERE user_id = ? AND status = 'Pending' 
         ORDER BY created_at ASC LIMIT 1",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $nextPayment = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Membership - UMak Gym: Fitsense</title>
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
           SIDEBAR NAVIGATION (same as dashboard)
           ============================================ */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, #005b9f 0%, #00467a 100%);
            color: white;
            overflow-y: auto;
            z-index: 1000;
            transition: width 0.3s ease;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 15px rgba(0,0,0,0.08);
        }

        .sidebar::-webkit-scrollbar { width: 6px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }

        .sidebar-brand {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        .sidebar-brand .logo {
            width: 40px;
            height: 40px;
            background: var(--umak-yellow);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            color: var(--umak-blue);
            font-size: 18px;
            flex-shrink: 0;
        }

        .sidebar-brand .brand-info h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .sidebar-brand .brand-info p {
            margin: 2px 0 0;
            font-size: 11px;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .sidebar-nav { flex: 1; padding: 15px 10px; overflow-y: auto; }

        .nav-section-title {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.4);
            padding: 15px 15px 8px;
            font-weight: 700;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 15px;
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 2px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            transform: translateX(3px);
        }

        .nav-item.active {
            background: rgba(255,206,0,0.15);
            color: var(--umak-yellow);
            font-weight: 600;
            border-left: 3px solid var(--umak-yellow);
            padding-left: 12px;
        }

        .nav-item i { font-size: 18px; width: 22px; text-align: center; flex-shrink: 0; }

        .nav-item .badge-count {
            margin-left: auto;
            background: var(--umak-red);
            color: white;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
        }

        .nav-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 12px 15px; }
        .nav-logout { color: #ff8a80 !important; }
        .nav-logout:hover { background: rgba(211,47,47,0.2) !important; color: #ff5252 !important; }

        /* ============================================
           MAIN WRAPPER & HEADER
           ============================================ */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            padding-bottom: 40px;
        }

        .top-header {
            background: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 3px solid var(--umak-yellow);
        }

        .header-left { display: flex; align-items: center; gap: 15px; }
        .sidebar-toggle {
            background: none;
            border: none;
            font-size: 22px;
            color: var(--umak-blue);
            cursor: pointer;
            display: none;
            padding: 5px;
        }

        .header-left h2 {
            margin: 0;
            font-size: 20px;
            color: var(--umak-blue);
            font-weight: 700;
        }

        .header-left .subtitle {
            font-size: 12px;
            color: var(--text-muted);
            margin: 0;
        }

        .header-right { display: flex; align-items: center; gap: 12px; }

        .btn-chat-open {
            background: linear-gradient(135deg, #005b9f 0%, #0084d1 100%);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,91,159,0.3);
        }

        .btn-chat-open:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,91,159,0.4); }

        .btn-chat-open .chat-dot {
            width: 8px;
            height: 8px;
            background: #4ade80;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }

        .notif-btn {
            background: #f4f6f9;
            border: none;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
            font-size: 18px;
            color: var(--umak-blue);
            transition: all 0.2s;
        }

        .notif-btn:hover { background: #e5eef5; }

        .notif-badge {
            position: absolute;
            top: 0; right: 0;
            background: var(--umak-red);
            color: white;
            font-size: 10px;
            font-weight: bold;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }

        .avatar-btn { background: none; border: none; padding: 0; cursor: pointer; }

        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
            transition: transform 0.2s;
        }

        .avatar:hover { transform: scale(1.05); }

        /* ============================================
           PAGE CONTENT
           ============================================ */
        .container { max-width: 1300px; margin: 30px auto; padding: 0 25px; }

        .page-title {
            font-size: 26px;
            font-weight: 800;
            color: var(--umak-blue);
            margin: 0 0 6px;
            display: flex;
            align-items: center;
            gap: 12px;
            letter-spacing: 0.5px;
        }

        .page-title i {
            font-size: 28px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 28px;
        }

        /* ============================================
           MEMBERSHIP HERO CARD
           ============================================ */
        .membership-hero {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 20px;
            padding: 40px;
            color: white;
            position: relative;
            overflow: hidden;
            margin-bottom: 25px;
            box-shadow: 0 15px 40px rgba(0,91,159,0.25);
        }

        .membership-hero::before {
            content: '';
            position: absolute;
            top: -100px;
            right: -100px;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,206,0,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }

        .membership-hero::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            border-radius: 50%;
        }

        .hero-content { position: relative; z-index: 2; }

        .hero-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }

        .hero-plan-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: rgba(255,255,255,0.7);
            margin-bottom: 8px;
            font-weight: 600;
        }

        .hero-plan-name {
            font-size: 36px;
            font-weight: 800;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .hero-plan-name .plan-icon {
            color: var(--umak-yellow);
            margin-right: 8px;
        }

        .hero-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .hero-status.active {
            background: rgba(74, 222, 128, 0.2);
            color: #4ade80;
            border: 1px solid rgba(74, 222, 128, 0.4);
        }

        .hero-status.expired {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.4);
        }

        .hero-status.pending {
            background: rgba(251, 191, 36, 0.2);
            color: #fbbf24;
            border: 1px solid rgba(251, 191, 36, 0.4);
        }

        .hero-status .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
            animation: statusPulse 2s infinite;
        }

        @keyframes statusPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }

        .hero-divider {
            height: 1px;
            background: rgba(255,255,255,0.15);
            margin: 25px 0;
        }

        .hero-dates {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 25px;
        }

        .hero-date-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .hero-date-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.65);
            font-weight: 600;
        }

        .hero-date-value {
            font-size: 20px;
            font-weight: 700;
            color: white;
        }

        .hero-date-value .highlight {
            color: var(--umak-yellow);
        }

        .hero-date-value.expiring {
            color: #fbbf24;
        }

        .hero-date-value.expired {
            color: #fca5a5;
        }

        /* Progress Bar in Hero */
        .hero-progress {
            margin-top: 25px;
        }

        .hero-progress-header {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: rgba(255,255,255,0.75);
            margin-bottom: 8px;
            font-weight: 600;
        }

        .hero-progress-track {
            height: 8px;
            background: rgba(255,255,255,0.15);
            border-radius: 4px;
            overflow: hidden;
        }

        .hero-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #ffce00, #ffb800);
            border-radius: 4px;
            width: var(--progress, 0%);
            transition: width 0.8s ease;
        }

        /* ============================================
           DETAILS GRID
           ============================================ */
        .details-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .panel {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border);
        }

        .panel-title {
            font-size: 16px;
            font-weight: 700;
            margin: 0;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-title i { font-size: 20px; }

        /* Benefits List */
        .benefits-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .benefit-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            transition: all 0.2s;
            font-size: 14px;
            font-weight: 500;
        }

        .benefit-item:hover { background: #f8fafc; }

        .benefit-item.included {
            color: #1f2937;
        }

        .benefit-item.excluded {
            color: #9ca3af;
            text-decoration: line-through;
            text-decoration-color: #d1d5db;
        }

        .benefit-icon {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
            font-weight: 900;
        }

        .benefit-item.included .benefit-icon {
            background: rgba(46, 125, 50, 0.12);
            color: var(--umak-green);
        }

        .benefit-item.excluded .benefit-icon {
            background: #f3f4f6;
            color: #9ca3af;
        }

        /* Summary List */
        .summary-list {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 12px;
            border-bottom: 1px dashed var(--border);
        }

        .summary-item:last-child { border-bottom: none; }

        .summary-label {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-label i {
            font-size: 15px;
            color: var(--umak-blue);
        }

        .summary-value {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }

        .summary-value.status-active { color: var(--umak-green); }
        .summary-value.status-warning { color: var(--umak-orange); }
        .summary-value.status-danger { color: var(--umak-red); }

        /* ============================================
           ACTION BUTTONS
           ============================================ */
        .action-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .btn {
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.25s ease;
            text-decoration: none;
            letter-spacing: 0.3px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 15px rgba(0,91,159,0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,91,159,0.4);
        }

        .btn-yellow {
            background: linear-gradient(135deg, #ffce00, #ffb800);
            color: #333;
            box-shadow: 0 4px 15px rgba(255,206,0,0.4);
        }

        .btn-yellow:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255,206,0,0.5);
        }

        .btn-outline {
            background: transparent;
            color: var(--umak-blue);
            border: 2px solid var(--umak-blue);
        }

        .btn-outline:hover {
            background: var(--umak-blue);
            color: white;
        }

        .btn i { font-size: 16px; }

        /* ============================================
           BILLING ALERT
           ============================================ */
        .billing-alert {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 5px solid var(--umak-orange);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .billing-alert-content {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
            min-width: 250px;
        }

        .billing-alert-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: rgba(245, 158, 11, 0.2);
            color: #b45309;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }

        .billing-alert-text h4 {
            margin: 0 0 4px;
            font-size: 15px;
            font-weight: 700;
            color: #78350f;
        }

        .billing-alert-text p {
            margin: 0;
            font-size: 13px;
            color: #92400e;
        }

        .billing-alert .btn { padding: 10px 20px; font-size: 13px; }

        /* ============================================
           MEMBERSHIP HISTORY TABLE
           ============================================ */
        .history-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .history-table th,
        .history-table td {
            padding: 14px 12px;
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

        .history-table th:first-child { border-radius: 10px 0 0 0; }
        .history-table th:last-child { border-radius: 0 10px 0 0; }

        .history-table tbody tr { transition: background 0.2s; }
        .history-table tbody tr:hover { background: #f9fafb; }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pill.active { background: #e8f5e9; color: var(--umak-green); }
        .status-pill.expired { background: #fee2e2; color: #b91c1c; }
        .status-pill.pending { background: #fef3c7; color: #b45309; }
        .status-pill.cancelled { background: #f3f4f6; color: #6b7280; }

        /* ============================================
           EMPTY STATE
           ============================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 16px;
            border: 2px dashed var(--border);
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
            font-size: 20px;
        }

        .empty-state p {
            color: var(--text-muted);
            margin-bottom: 25px;
            font-size: 14px;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
            .details-grid { grid-template-columns: 1fr; }
            .membership-hero { padding: 28px 22px; }
            .hero-plan-name { font-size: 26px; }
        }

        @media (max-width: 576px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .btn-chat-open span.label { display: none; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .hero-dates { grid-template-columns: 1fr 1fr; gap: 15px; }
            .hero-date-value { font-size: 16px; }
            .action-bar .btn { flex: 1; justify-content: center; }
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
            <i class="bi bi-house-door-fill"></i>
            <span>Dashboard</span>
        </a>
        <a href="my-membership.php" class="nav-item active">
            <i class="bi bi-ticket-perforated-fill"></i>
            <span>My Membership</span>
        </a>
        <a href="attendance.php" class="nav-item">
            <i class="bi bi-bar-chart-fill"></i>
            <span>Attendance</span>
        </a>

        <div class="nav-section-title">Fitness</div>
        <a href="workout-plan.php" class="nav-item">
            <i class="bi bi-heart-pulse-fill"></i>
            <span>Workout Plan</span>
        </a>
        <a href="nutrition-plan.php" class="nav-item">
            <i class="bi bi-egg-fried"></i>
            <span>Nutrition Plan</span>
        </a>
        <a href="my-progress.php" class="nav-item">
            <i class="bi bi-graph-up-arrow"></i>
            <span>My Progress</span>
        </a>

        <div class="nav-section-title">Services</div>
        <a href="classes.php" class="nav-item">
            <i class="bi bi-calendar-event-fill"></i>
            <span>Classes & Booking</span>
        </a>
        <a href="payments.php" class="nav-item">
            <i class="bi bi-credit-card-fill"></i>
            <span>Payments & Billing</span>
        </a>
        <a href="notifications.php" class="nav-item">
            <i class="bi bi-bell-fill"></i>
            <span>Notifications</span>
            <?php if ($unreadNotifCount > 0): ?>
                <span class="badge-count"><?= $unreadNotifCount ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section-title">Account</div>
        <a href="my-profile.php" class="nav-item">
            <i class="bi bi-person-circle"></i>
            <span>My Profile</span>
        </a>
        <a href="help.php" class="nav-item">
            <i class="bi bi-life-preserver"></i>
            <span>Help & Support</span>
        </a>
        <a href="settings.php" class="nav-item">
            <i class="bi bi-gear-fill"></i>
            <span>Settings</span>
        </a>
        <div class="nav-divider"></div>
        <a href="logout.php" class="nav-item nav-logout">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
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
                <h2>My Membership</h2>
                <p class="subtitle">Manage your gym membership plan</p>
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
            <i class="bi bi-ticket-perforated-fill"></i>
            My Membership
        </h1>
        <p class="page-subtitle">View your active plan, track expiry, and manage your subscription.</p>

        <?php if ($hasMembership): ?>

            <!-- ============================================
                 BILLING ALERT (if pending payment)
                 ============================================ -->
            <?php if ($nextPayment): ?>
                <div class="billing-alert">
                    <div class="billing-alert-content">
                        <div class="billing-alert-icon">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </div>
                        <div class="billing-alert-text">
                            <h4>Payment Due: ₱<?= number_format($nextPayment['amount'], 2) ?></h4>
                            <p><?= htmlspecialchars($nextPayment['description']) ?> · Requested <?= date('M d, Y', strtotime($nextPayment['created_at'])) ?></p>
                        </div>
                    </div>
                    <a href="payments.php" class="btn btn-primary">
                        <i class="bi bi-credit-card-fill"></i> Pay Now
                    </a>
                </div>
            <?php endif; ?>

            <!-- ============================================
                 MEMBERSHIP HERO CARD
                 ============================================ -->
            <div class="membership-hero">
                <div class="hero-content">
                    <div class="hero-top">
                        <div>
                            <div class="hero-plan-label">Your Active Plan</div>
                            <h2 class="hero-plan-name">
                                <span class="plan-icon">
                                    <?php
                                    $planLower = strtolower($membership['plan_name']);
                                    if (strpos($planLower, 'premium') !== false) echo '👑';
                                    elseif (strpos($planLower, 'elite') !== false) echo '💎';
                                    elseif (strpos($planLower, 'vip') !== false) echo '⭐';
                                    else echo '🎫';
                                    ?>
                                </span>
                                <?= htmlspecialchars($membership['plan_name']) ?>
                            </h2>
                        </div>

                        <div class="hero-status <?= $isExpired ? 'expired' : (strtolower($membership['status']) === 'active' ? 'active' : 'pending') ?>">
                            <span class="dot"></span>
                            <?= $isExpired ? 'EXPIRED' : strtoupper($membership['status']) ?>
                        </div>
                    </div>

                    <div class="hero-divider"></div>

                    <div class="hero-dates">
                        <div class="hero-date-item">
                            <div class="hero-date-label">
                                <i class="bi bi-calendar-check"></i> Started
                            </div>
                            <div class="hero-date-value">
                                <?= date('F d, Y', strtotime($membership['start_date'])) ?>
                            </div>
                        </div>

                        <div class="hero-date-item">
                            <div class="hero-date-label">
                                <i class="bi bi-calendar-x"></i> Expires
                            </div>
                            <div class="hero-date-value <?= $isExpired ? 'expired' : ($isExpiringSoon ? 'expiring' : '') ?>">
                                <?= date('F d, Y', strtotime($membership['expiry_date'])) ?>
                            </div>
                        </div>

                        <div class="hero-date-item">
                            <div class="hero-date-label">
                                <i class="bi bi-hourglass-split"></i> Days Left
                            </div>
                            <div class="hero-date-value <?= $isExpired ? 'expired' : ($isExpiringSoon ? 'expiring' : '') ?>">
                                <?php if ($isExpired): ?>
                                    <span style="color: #fca5a5;">Expired</span>
                                <?php else: ?>
                                    <span class="highlight"><?= $daysLeft ?></span> day<?= $daysLeft != 1 ? 's' : '' ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!$isExpired): ?>
                    <div class="hero-progress">
                        <div class="hero-progress-header">
                            <span>Membership Progress</span>
                            <span><?= $usedDays ?> / <?= $totalDays ?> days used</span>
                        </div>
                        <div class="hero-progress-track">
                            <div class="hero-progress-fill" style="--progress: <?= $progressPercent ?>%"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============================================
                 ACTION BUTTONS
                 ============================================ -->
            <div class="action-bar">
                <a href="plans.php" class="btn btn-yellow">
                    <i class="bi bi-arrow-repeat"></i> Renew Membership
                </a>
                <a href="payments.php" class="btn btn-primary">
                    <i class="bi bi-credit-card-fill"></i> View Billing History
                </a>
                <a href="classes.php" class="btn btn-outline">
                    <i class="bi bi-calendar-event-fill"></i> Book a Class
                </a>
                <button class="btn btn-outline" onclick="window.print()">
                    <i class="bi bi-printer-fill"></i> Print Card
                </button>
            </div>

            <!-- ============================================
                 DETAILS GRID: Benefits + Summary
                 ============================================ -->
            <div class="details-grid">
                <!-- Benefits -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="bi bi-star-fill" style="color: var(--umak-yellow);"></i>
                            Plan Benefits
                        </h3>
                        <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;">
                            <?= count(array_filter($planBenefits)) ?> / <?= count($planBenefits) ?> included
                        </span>
                    </div>
                    <div class="benefits-list">
                        <?php foreach ($planBenefits as $benefit => $included): ?>
                            <div class="benefit-item <?= $included ? 'included' : 'excluded' ?>">
                                <div class="benefit-icon">
                                    <?= $included ? '✓' : '✕' ?>
                                </div>
                                <span><?= htmlspecialchars($benefit) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Summary -->
                <div class="panel">
                    <div class="panel-header">
                        <h3 class="panel-title">
                            <i class="bi bi-info-circle-fill"></i>
                            Membership Summary
                        </h3>
                    </div>
                    <div class="summary-list">
                        <div class="summary-item">
                            <span class="summary-label">
                                <i class="bi bi-tag-fill"></i> Plan
                            </span>
                            <span class="summary-value"><?= htmlspecialchars($membership['plan_name']) ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">
                                <i class="bi bi-circle-fill"></i> Status
                            </span>
                            <span class="summary-value <?= $isExpired ? 'status-danger' : (strtolower($membership['status']) === 'active' ? 'status-active' : 'status-warning') ?>">
                                <?= $isExpired ? 'Expired' : ucfirst(strtolower($membership['status'])) ?>
                            </span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">
                                <i class="bi bi-calendar-event"></i> Start Date
                            </span>
                            <span class="summary-value"><?= date('M d, Y', strtotime($membership['start_date'])) ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">
                                <i class="bi bi-calendar-x"></i> Expiry Date
                            </span>
                            <span class="summary-value"><?= date('M d, Y', strtotime($membership['expiry_date'])) ?></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">
                                <i class="bi bi-clock-history"></i> Duration
                            </span>
                            <span class="summary-value"><?= $totalDays ?> days</span>
                        </div>
                        <?php if (!empty($membership['price'])): ?>
                        <div class="summary-item">
                            <span class="summary-label">
                                <i class="bi bi-currency-exchange"></i> Plan Fee
                            </span>
                            <span class="summary-value">₱<?= number_format($membership['price'], 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="summary-item">
                            <span class="summary-label">
                                <i class="bi bi-hourglass-split"></i> Days Remaining
                            </span>
                            <span class="summary-value <?= $isExpired ? 'status-danger' : ($isExpiringSoon ? 'status-warning' : 'status-active') ?>">
                                <?= $isExpired ? 'Expired' : $daysLeft . ' days' ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================
                 MEMBERSHIP HISTORY
                 ============================================ -->
            <?php if (!empty($history)): ?>
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-clock-history"></i>
                        Membership History
                    </h3>
                    <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;">
                        <?= count($history) ?> record<?= count($history) != 1 ? 's' : '' ?>
                    </span>
                </div>
                <div style="overflow-x: auto;">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Plan</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <?php if (!empty($history[0]['price'])): ?>
                                    <th>Amount</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $h): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($h['plan_name']) ?></strong></td>
                                    <td><?= date('M d, Y', strtotime($h['start_date'])) ?></td>
                                    <td><?= date('M d, Y', strtotime($h['expiry_date'])) ?></td>
                                    <td>
                                        <span class="status-pill <?= strtolower($h['status']) ?>">
                                            <?= htmlspecialchars($h['status']) ?>
                                        </span>
                                    </td>
                                    <?php if (isset($h['price'])): ?>
                                        <td>₱<?= number_format($h['price'] ?? 0, 2) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        <?php else: ?>

            <!-- ============================================
                 EMPTY STATE - NO MEMBERSHIP
                 ============================================ -->
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="bi bi-ticket-perforated"></i>
                </div>
                <h3>No Active Membership</h3>
                <p>You don't have an active gym membership yet.<br>Choose a plan below to get started!</p>
                <a href="plans.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle-fill"></i> Browse Membership Plans
                </a>
            </div>

        <?php endif; ?>
    </main>
</div>

<script>
    function createNotification($conn, $userId, $title, $message, $type = 'info', $link = null) {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO notifications (user_id, title, message, type, link, created_at) 
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        if ($stmt) {
            $stmt->bind_param("issss", $userId, $title, $message, $type, $link);
            return $stmt->execute();
        }
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
    }
    return false;
}
    // Toggle sidebar on mobile
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

    // Animate progress bar on load
    document.addEventListener('DOMContentLoaded', function() {
        const fill = document.querySelector('.hero-progress-fill');
        if (fill) {
            const target = fill.style.getPropertyValue('--progress');
            fill.style.width = '0%';
            setTimeout(() => {
                fill.style.width = target;
            }, 200);
        }
    });
</script>
<?php include 'chat_widget.php'; ?>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>