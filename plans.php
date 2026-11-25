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
// FETCH CURRENT ACTIVE MEMBERSHIP
// ============================================
$currentPlan = null;
if (tableExists($conn, 'memberships')) {
    $result = safeQuery($conn,
        "SELECT id, membership_type AS plan_name, status, start_date, end_date AS expiry_date 
         FROM memberships 
         WHERE user_id = ? AND status = 'Active' AND end_date >= CURDATE()
         ORDER BY end_date DESC LIMIT 1",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $currentPlan = $row;
    }
}

// ============================================
// FETCH PLANS FROM BILLING (membership_prices)
// ============================================
// The cards below are the REAL, billable plans used by the payment
// system (Xendit / GCash / Maya). Prices are read from the trusted
// server-side `membership_prices` table so this page and the checkout
// always agree. (The legacy `plans` marketing table is not used.)
$plans = [];

// Static description/features per real plan type (prices come from the DB).
$planMeta = [
    'One Day' => [
        'description' => 'Single-day access to the gym floor and equipment.',
        'price'       => 150.00,
        'duration_days' => 1,
        'period'      => 'day',
        'badge'       => null,
        'is_popular'  => false,
        'features'    => [
            'Single-day gym access',
            'Basic equipment',
            'Locker access',
            'Operating-hours access',
        ],
    ],
    'Monthly' => [
        'description' => 'Full access for 30 days — our most popular choice.',
        'price'       => 1500.00,
        'duration_days' => 30,
        'period'      => 'month',
        'badge'       => 'Most Popular',
        'is_popular'  => true,
        'features'    => [
            'Full gym access',
            'All equipment',
            'Group classes',
            'Locker access',
            'Operating-hours access',
        ],
    ],
    'Yearly' => [
        'description' => 'Best value — a full year of unlimited access.',
        'price'       => 15000.00,
        'duration_days' => 365,
        'period'      => 'year',
        'badge'       => 'Best Value',
        'is_popular'  => false,
        'features'    => [
            'Full gym access',
            'All equipment',
            'Group classes',
            'Locker access',
            'Operating-hours access',
            'Guest passes',
            'Priority support',
        ],
    ],
];

// Read the live prices from the billing table.
$livePrices = [];
if (tableExists($conn, 'membership_prices')) {
    $priceRes = safeQuery($conn, "SELECT membership_type, price FROM membership_prices WHERE is_active = 1");
    if ($priceRes) {
        while ($priceRow = $priceRes->fetch_assoc()) {
            $livePrices[$priceRow['membership_type']] = (float)$priceRow['price'];
        }
    }
}

foreach ($planMeta as $type => $meta) {
    // If billing has a price list, only show plans that are actually sold.
    if (!empty($livePrices) && !array_key_exists($type, $livePrices)) {
        continue;
    }
    $plans[] = [
        'id'            => $type,
        'name'          => $type,
        'description'   => $meta['description'],
        'price'         => $livePrices[$type] ?? (float)$meta['price'],
        'duration_days' => $meta['duration_days'],
        'period'        => $meta['period'],
        'features'      => $meta['features'],
        'badge'         => $meta['badge'],
        'is_popular'    => $meta['is_popular'],
    ];
}

// Sort plans by price
usort($plans, fn($a, $b) => $a['price'] <=> $b['price']);

// Success/error messages from redirect
$success = $_SESSION['plan_success'] ?? '';
$error = $_SESSION['plan_error'] ?? '';
unset($_SESSION['plan_success'], $_SESSION['plan_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Plans - UMak Gym: Fitsense</title>
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
           ALERTS
           ============================================ */
        .alert {
            padding: 14px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.4s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border-left: 4px solid var(--umak-green);
        }

        .alert-danger {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
            border-left: 4px solid var(--umak-red);
        }

        /* ============================================
           CURRENT PLAN BANNER
           ============================================ */
        .current-plan-banner {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 16px;
            padding: 22px 28px;
            color: white;
            margin-bottom: 35px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            box-shadow: 0 10px 30px rgba(0,91,159,0.2);
            position: relative;
            overflow: hidden;
        }

        .current-plan-banner::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255,206,0,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }

        .current-plan-info { display: flex; align-items: center; gap: 18px; position: relative; z-index: 2; }

        .current-plan-icon {
            width: 55px;
            height: 55px;
            border-radius: 14px;
            background: rgba(255,206,0,0.2);
            color: var(--umak-yellow);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            flex-shrink: 0;
        }

        .current-plan-info h4 {
            margin: 0 0 4px;
            font-size: 16px;
            font-weight: 700;
        }

        .current-plan-info p {
            margin: 0;
            font-size: 13px;
            color: rgba(255,255,255,0.75);
        }

        .current-plan-banner .btn-banner {
            padding: 10px 22px;
            background: var(--umak-yellow);
            color: #333;
            border: none;
            border-radius: 25px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
            position: relative;
            z-index: 2;
        }

        .current-plan-banner .btn-banner:hover { background: #e5b800; transform: translateY(-2px); }

        /* ============================================
           PRICING SECTION
           ============================================ */
        .pricing-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .pricing-header .eyebrow {
            display: inline-block;
            background: rgba(0,91,159,0.1);
            color: var(--umak-blue);
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .pricing-header h2 {
            font-size: 32px;
            font-weight: 800;
            color: var(--umak-blue);
            margin: 0 0 10px;
            letter-spacing: -0.5px;
        }

        .pricing-header p {
            color: var(--text-muted);
            font-size: 15px;
            max-width: 550px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* Billing toggle */
        .billing-toggle {
            display: inline-flex;
            background: #e5e7eb;
            padding: 4px;
            border-radius: 25px;
            margin-top: 20px;
            gap: 4px;
        }

        .billing-toggle button {
            padding: 8px 20px;
            border-radius: 20px;
            border: none;
            background: transparent;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-muted);
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .billing-toggle button.active {
            background: white;
            color: var(--umak-blue);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .billing-toggle .save-badge {
            background: var(--umak-green);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
        }

        /* ============================================
           PLANS GRID
           ============================================ */
        .plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }

        .plan-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 32px 26px;
            position: relative;
            border: 2px solid var(--border);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .plan-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 45px rgba(0,0,0,0.1);
            border-color: rgba(0,91,159,0.3);
        }

        .plan-card.popular {
            border-color: var(--umak-blue);
            background: linear-gradient(180deg, #ffffff 0%, #f0f7fc 100%);
            box-shadow: 0 10px 30px rgba(0,91,159,0.15);
        }

        .plan-card.popular:hover {
            box-shadow: 0 25px 55px rgba(0,91,159,0.2);
        }

        .plan-card.current {
            border-color: var(--umak-green);
            background: linear-gradient(180deg, #ffffff 0%, #f0fdf4 100%);
        }

        .plan-badge {
            position: absolute;
            top: 18px;
            right: 18px;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .plan-badge.popular {
            background: linear-gradient(135deg, #ffce00, #ffb800);
            color: #333;
            box-shadow: 0 4px 12px rgba(255,206,0,0.4);
        }

        .plan-badge.value {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 12px rgba(16,185,129,0.3);
        }

        .plan-badge.student {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            box-shadow: 0 4px 12px rgba(139,92,246,0.3);
        }

        .plan-badge.current-badge {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            box-shadow: 0 4px 12px rgba(46,125,50,0.3);
        }

        .plan-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin-bottom: 18px;
        }

        .plan-card.basic .plan-icon {
            background: linear-gradient(135deg, #e5e7eb, #d1d5db);
            color: #4b5563;
        }

        .plan-card.standard .plan-icon,
        .plan-card.popular .plan-icon {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 8px 20px rgba(0,91,159,0.3);
        }

        .plan-card.premium .plan-icon {
            background: linear-gradient(135deg, #ffce00, #ffb800);
            color: #333;
            box-shadow: 0 8px 20px rgba(255,206,0,0.4);
        }

        .plan-card.student .plan-icon {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            box-shadow: 0 8px 20px rgba(139,92,246,0.3);
        }

        .plan-card.oneday .plan-icon {
            background: linear-gradient(135deg, #e5e7eb, #d1d5db);
            color: #4b5563;
        }

        .plan-card.yearly .plan-icon {
            background: linear-gradient(135deg, #ffce00, #ffb800);
            color: #333;
            box-shadow: 0 8px 20px rgba(255,206,0,0.4);
        }

        .plan-name {
            font-size: 22px;
            font-weight: 800;
            color: #111827;
            margin: 0 0 6px;
            letter-spacing: -0.3px;
        }

        .plan-description {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 20px;
            min-height: 40px;
        }

        .plan-price-block {
            display: flex;
            align-items: baseline;
            gap: 4px;
            margin-bottom: 6px;
        }

        .plan-currency {
            font-size: 18px;
            font-weight: 700;
            color: var(--umak-blue);
        }

        .plan-price {
            font-size: 42px;
            font-weight: 800;
            color: var(--umak-blue);
            line-height: 1;
            letter-spacing: -1.5px;
        }

        .plan-price.yellow { color: #b8860b; }
        .plan-price.purple { color: #7c3aed; }

        .plan-period {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .plan-savings {
            display: inline-block;
            background: rgba(16,185,129,0.12);
            color: #059669;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .plan-divider {
            height: 1px;
            background: var(--border);
            margin: 20px 0;
        }

        .plan-features {
            list-style: none;
            padding: 0;
            margin: 0 0 25px;
            flex: 1;
        }

        .plan-features li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 7px 0;
            font-size: 13.5px;
            color: #374151;
            font-weight: 500;
            line-height: 1.5;
        }

        .plan-features li.excluded {
            color: #9ca3af;
            text-decoration: line-through;
            text-decoration-color: #d1d5db;
        }

        .plan-features li i {
            font-size: 16px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .plan-features li i.check { color: var(--umak-green); }
        .plan-features li i.cross { color: #d1d5db; }

        .plan-cta {
            width: 100%;
            padding: 14px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.25s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.3px;
        }

        .plan-cta.primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 15px rgba(0,91,159,0.3);
        }

        .plan-cta.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,91,159,0.4);
        }

        .plan-cta.yellow {
            background: linear-gradient(135deg, #ffce00, #ffb800);
            color: #333;
            box-shadow: 0 4px 15px rgba(255,206,0,0.4);
        }

        .plan-cta.yellow:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255,206,0,0.5);
        }

        .plan-cta.purple {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            box-shadow: 0 4px 15px rgba(139,92,246,0.3);
        }

        .plan-cta.purple:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139,92,246,0.4);
        }

        .plan-cta.outline {
            background: transparent;
            color: var(--umak-blue);
            border: 2px solid var(--umak-blue);
        }

        .plan-cta.outline:hover {
            background: var(--umak-blue);
            color: white;
        }

        .plan-cta.current {
            background: #f0fdf4;
            color: var(--umak-green);
            border: 2px solid var(--umak-green);
            cursor: default;
        }

        /* ============================================
           COMPARISON SECTION
           ============================================ */
        .comparison-section {
            background: white;
            border-radius: 20px;
            padding: 35px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border: 1px solid var(--border);
        }

        .comparison-section h3 {
            font-size: 20px;
            font-weight: 800;
            color: var(--umak-blue);
            margin: 0 0 6px;
        }

        .comparison-section .subtitle {
            color: var(--text-muted);
            font-size: 13.5px;
            margin-bottom: 25px;
        }

        .comparison-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .comparison-table th,
        .comparison-table td {
            padding: 14px 12px;
            text-align: center;
            border-bottom: 1px solid var(--border);
        }

        .comparison-table th:first-child,
        .comparison-table td:first-child {
            text-align: left;
            font-weight: 600;
            color: #374151;
        }

        .comparison-table thead th {
            background: #f9fafb;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }

        .comparison-table thead th.featured {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
        }

        .comparison-table tbody tr:hover { background: #f9fafb; }

        .comparison-table .check-icon {
            color: var(--umak-green);
            font-size: 18px;
        }

        .comparison-table .cross-icon {
            color: #d1d5db;
            font-size: 18px;
        }

        /* ============================================
           FAQ SECTION
           ============================================ */
        .faq-section {
            background: white;
            border-radius: 20px;
            padding: 35px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border: 1px solid var(--border);
        }

        .faq-section h3 {
            font-size: 20px;
            font-weight: 800;
            color: var(--umak-blue);
            margin: 0 0 25px;
            text-align: center;
        }

        .faq-item {
            border-bottom: 1px solid var(--border);
            padding: 16px 0;
        }

        .faq-item:last-child { border-bottom: none; }

        .faq-question {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14.5px;
            font-weight: 700;
            color: #1f2937;
            cursor: pointer;
            padding: 6px 0;
            transition: color 0.2s;
        }

        .faq-question:hover { color: var(--umak-blue); }

        .faq-question i {
            color: var(--umak-blue);
            font-size: 18px;
            transition: transform 0.3s;
        }

        .faq-item.open .faq-question i { transform: rotate(180deg); }

        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.7;
        }

        .faq-item.open .faq-answer {
            max-height: 300px;
            padding-top: 10px;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
            .plans-grid { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .btn-chat-open span.label { display: none; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .pricing-header h2 { font-size: 24px; }
            .plan-card { padding: 26px 22px; }
            .plan-price { font-size: 34px; }
            .comparison-section { padding: 22px 16px; overflow-x: auto; }
            .comparison-table { min-width: 500px; }
        }

        @media (max-width: 576px) {
            .plans-grid { grid-template-columns: 1fr; }
            .current-plan-banner { padding: 18px 20px; }
            .current-plan-icon { width: 45px; height: 45px; font-size: 20px; }
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
        <a href="my-membership.php" class="nav-item">
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
                <h2>Membership Plans</h2>
                <p class="subtitle">Choose the plan that fits your goals</p>
            </div>
        </div>

        <div class="header-right">
            <button class="btn-chat-open" onclick="alert('Chat feature coming soon!')">
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

        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill" style="font-size: 20px;"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill" style="font-size: 20px;"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Current Plan Banner -->
        <?php if ($currentPlan): ?>
            <div class="current-plan-banner">
                <div class="current-plan-info">
                    <div class="current-plan-icon">
                        <i class="bi bi-patch-check-fill"></i>
                    </div>
                    <div>
                        <h4>You're on the <?= htmlspecialchars($currentPlan['plan_name']) ?> plan</h4>
                        <p>Active until <?= date('F d, Y', strtotime($currentPlan['expiry_date'])) ?></p>
                    </div>
                </div>
                <a href="my-membership.php" class="btn-banner">
                    <i class="bi bi-arrow-right-circle"></i> View Membership
                </a>
            </div>
        <?php endif; ?>

        <!-- Pricing Header -->
        <div class="pricing-header">
            <span class="eyebrow">Membership Plans</span>
            <h2>Choose Your Fitness Journey</h2>
            <p>Flexible membership options designed for every level. All plans include access to premium equipment and a welcoming community.</p>

            <!-- Prices come straight from the billing plans; no billing toggle. -->
        </div>

        <!-- Plans Grid -->
        <div class="plans-grid" id="plansGrid">
            <?php foreach ($plans as $plan):
                $planSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $plan['name']));
                $isCurrentPlan = $currentPlan && strtolower($currentPlan['plan_name']) === strtolower($plan['name']);
                $isPopular = $plan['is_popular'] || stripos($plan['name'], 'standard') !== false;
                $isPremium = stripos($plan['name'], 'premium') !== false;
                $isStudent = stripos($plan['name'], 'student') !== false;

                $priceClass = $isPremium ? 'yellow' : ($isStudent ? 'purple' : '');
                $ctaClass = $isPremium ? 'yellow' : ($isStudent ? 'purple' : 'primary');
                if ($isCurrentPlan) $ctaClass = 'current';

                $planIcon = $isPremium ? 'bi-gem' : ($isStudent ? 'bi-mortarboard-fill' : ($isPopular ? 'bi-star-fill' : 'bi-heart-pulse-fill'));
            ?>
                <div class="plan-card <?= $isPopular ? 'popular' : '' ?> <?= $isCurrentPlan ? 'current' : '' ?> <?= $planSlug ?>"
                     data-price-monthly="<?= $plan['price'] ?>"
                     data-price-quarterly="<?= round($plan['price'] * 3 * 0.85, 2) ?>">

                    <!-- Badge -->
                    <?php if ($isCurrentPlan): ?>
                        <div class="plan-badge current-badge">✓ Current Plan</div>
                    <?php elseif ($isPopular): ?>
                        <div class="plan-badge popular">⭐ Most Popular</div>
                    <?php elseif (!empty($plan['badge'])): ?>
                        <div class="plan-badge <?= $isStudent ? 'student' : 'value' ?>">
                            <?= htmlspecialchars($plan['badge']) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Icon -->
                    <div class="plan-icon">
                        <i class="bi <?= $planIcon ?>"></i>
                    </div>

                    <!-- Name & Description -->
                    <h3 class="plan-name"><?= htmlspecialchars($plan['name']) ?></h3>
                    <p class="plan-description"><?= htmlspecialchars($plan['description']) ?></p>

                    <!-- Price -->
                    <div class="plan-price-block">
                        <span class="plan-currency">₱</span>
                        <span class="plan-price <?= $priceClass ?>" id="price-<?= $planSlug ?>">
                            <?= number_format($plan['price'], 0) ?>
                        </span>
                        <span class="plan-period">/ <?= htmlspecialchars($plan['period'] ?? ($plan['duration_days'] . ' days')) ?></span>
                    </div>

                    <div class="plan-divider"></div>

                    <!-- Features -->
                    <ul class="plan-features">
                        <?php if (!empty($plan['features'])): ?>
                            <?php foreach ($plan['features'] as $feature): ?>
                                <li>
                                    <i class="bi bi-check-circle-fill check"></i>
                                    <span><?= htmlspecialchars($feature) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Always show what's not included for lower tiers -->
                        <?php if (!$isPremium && !$isStudent): ?>
                            <?php if (stripos($plan['name'], 'basic') !== false): ?>
                                <li class="excluded">
                                    <i class="bi bi-x-circle-fill cross"></i>
                                    <span>Group Classes</span>
                                </li>
                                <li class="excluded">
                                    <i class="bi bi-x-circle-fill cross"></i>
                                    <span>Personal Trainer</span>
                                </li>
                            <?php endif; ?>
                            <?php if ($isPopular && stripos($plan['name'], 'premium') === false): ?>
                                <li class="excluded">
                                    <i class="bi bi-x-circle-fill cross"></i>
                                    <span>Personal Trainer</span>
                                </li>
                                <li class="excluded">
                                    <i class="bi bi-x-circle-fill cross"></i>
                                    <span>Nutrition Consultation</span>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>
                    </ul>

                    <!-- CTA Button -->
                    <?php if ($isCurrentPlan): ?>
                        <button class="plan-cta current" disabled>
                            <i class="bi bi-check-circle-fill"></i> Current Plan
                        </button>
                    <?php else: ?>
                        <a href="payments.php?plan=<?= urlencode($plan['id']) ?>"
                           class="plan-cta <?= $ctaClass ?>"
                           id="cta-<?= $planSlug ?>">
                            <i class="bi bi-arrow-right-circle"></i> Choose <?= htmlspecialchars($plan['name']) ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Comparison Table -->
        <div class="comparison-section">
            <h3><i class="bi bi-list-check" style="color: var(--umak-blue);"></i> Compare All Features</h3>
            <p class="subtitle">See exactly what's included in each membership tier.</p>
            <div style="overflow-x: auto;">
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th>Feature</th>
                            <?php foreach ($plans as $p):
                                $isPop = $p['is_popular'] || stripos($p['name'], 'standard') !== false;
                            ?>
                                <th class="<?= $isPop ? 'featured' : '' ?>">
                                    <?= htmlspecialchars($p['name']) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $allFeatures = [];
                        foreach ($plans as $p) {
                            foreach ($p['features'] as $f) $allFeatures[$f] = true;
                        }
                        $allFeatures = array_keys($allFeatures);
                        ?>
                        <?php foreach ($allFeatures as $feature): ?>
                            <tr>
                                <td><?= htmlspecialchars($feature) ?></td>
                                <?php foreach ($plans as $p): ?>
                                    <td>
                                        <?php if (in_array($feature, $p['features'])): ?>
                                            <i class="bi bi-check-circle-fill check-icon"></i>
                                        <?php else: ?>
                                            <i class="bi bi-dash-circle cross-icon"></i>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- FAQ Section -->
        <div class="faq-section">
            <h3><i class="bi bi-question-circle-fill" style="color: var(--umak-blue);"></i> Frequently Asked Questions</h3>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span>Can I upgrade or downgrade my plan?</span>
                    <i class="bi bi-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    Yes! You can upgrade at any time, and the price difference will be prorated. Downgrades will take effect after your current plan expires.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span>What payment methods do you accept?</span>
                    <i class="bi bi-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    We accept online payments via GCash or Maya through our secure payment page, and cash payments at the UMak Cash Office (Ground Floor, Administration Building). Present your COR or valid ID when paying in cash.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span>How do I pay for a membership online?</span>
                    <i class="bi bi-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    Choose a plan, then pay with GCash or Maya. Once your payment is confirmed, your membership is activated automatically.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span>Can I freeze or pause my membership?</span>
                    <i class="bi bi-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    Please contact the gym staff to discuss freezing or pausing your membership.
                </div>
            </div>

            <div class="faq-item">
                <div class="faq-question" onclick="toggleFaq(this)">
                    <span>Is there a joining fee?</span>
                    <i class="bi bi-chevron-down"></i>
                </div>
                <div class="faq-answer">
                    No joining fee! Just pay for your chosen plan and you're ready to start your fitness journey.
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
    // FAQ ACCORDION
    // ============================================
    function toggleFaq(el) {
        const item = el.parentElement;
        const isOpen = item.classList.contains('open');

        // Close all others (accordion behavior)
        document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('open'));

        // Toggle current
        if (!isOpen) item.classList.add('open');
    }

    // Open first FAQ by default
    document.addEventListener('DOMContentLoaded', function() {
        const firstFaq = document.querySelector('.faq-item');
        if (firstFaq) firstFaq.classList.add('open');
    });
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>