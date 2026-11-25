<?php
require_once __DIR__ . '/config/session_security.php';
require_once __DIR__ . '/config/error_handling.php';

session_start();
include 'conn.php';

// ============================================
// PAYMENT GATEWAY CONFIGURATION (Xendit)
// ============================================
// Secrets live in the private server-side config / .env file and are
// NEVER printed to the page. See config/xendit.php and .env.example.
require_once __DIR__ . '/config/xendit.php';

$paymentGatewayEnabled = xendit_is_configured();
if (!$paymentGatewayEnabled) {
    app_log_error('Xendit payment gateway is not configured.', ['script' => basename(__FILE__)]);
}
$currencySymbol = '₱';

// CSRF token for the payment endpoints
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ============================================
// AUTHENTICATION CHECK
// ============================================
$userSession = app_validate_session_user($conn, ['user', 'admin', 'super_admin']);
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

// ============================================
// BILLING PLAN PRICES (for the "?plan=" checkout shortcut)
// ============================================
// Read the real, trusted prices straight from membership_prices so the
// amount shown on plans.php is exactly what the customer is charged.
$planPrices = [];
if (tableExists($conn, 'membership_prices')) {
    $planRes = safeQuery($conn, "SELECT membership_type, price FROM membership_prices WHERE is_active = 1");
    if ($planRes) {
        while ($planRow = $planRes->fetch_assoc()) {
            $planPrices[$planRow['membership_type']] = (float)$planRow['price'];
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
$userEmail = $userData['email'] ?? $_SESSION['email'] ?? '';
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
// FETCH MEMBERSHIP (current plan)
// ============================================
$membership = null;
if (tableExists($conn, 'memberships')) {
    $result = safeQuery($conn,
        "SELECT id, membership_type, status, start_date, end_date, amount
         FROM memberships
         WHERE user_id = ? AND status = 'Active' AND end_date >= CURDATE()
         ORDER BY end_date DESC LIMIT 1",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $membership = [
            'id'           => $row['id'],
            'plan_name'    => $row['membership_type'],
            'status'       => $row['status'],
            'start_date'   => $row['start_date'],
            'expiry_date'  => $row['end_date'],
            'price'        => $row['amount'],
        ];
    }
}

// ============================================
// FETCH TRANSACTIONS
// ============================================
$transactions = [];
$totalPaid = 0;
$pendingAmount = 0;

if (tableExists($conn, 'transactions')) {
    $result = safeQuery($conn,
        "SELECT id, reference_no, transaction_type, description, amount, status,
                created_at, paid_at, payment_method, payment_provider,
                xendit_channel_code, xendit_status, transaction_ref
         FROM transactions
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC",
        "i", [$userId]
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
            if (strtolower($row['status']) === 'paid') {
                $totalPaid += (float)$row['amount'];
            } elseif (in_array(strtolower($row['status']), ['pending', 'overdue'])) {
                $pendingAmount += (float)$row['amount'];
            }
        }
    }
}

// Latest paid transaction
$latestPayment = null;
foreach ($transactions as $t) {
    if (strtolower($t['status']) === 'paid') {
        $latestPayment = $t;
        break;
    }
}

// Pending transaction
$pendingPayment = null;
foreach ($transactions as $t) {
    if (in_array(strtolower($t['status']), ['pending', 'overdue'])) {
        $pendingPayment = $t;
        break;
    }
}

// ============================================
// OUTSTANDING BALANCE
// ============================================
$currentBalance = $pendingAmount;

// ============================================
// HELPER FUNCTIONS
// ============================================
function getPaymentMethodIcon($method) {
    $method = strtolower($method ?? '');
    if (strpos($method, 'gcash') !== false) return 'bi-phone-fill';
    if (strpos($method, 'maya') !== false || strpos($method, 'paymaya') !== false) return 'bi-credit-card-2-front-fill';
    if (strpos($method, 'card') !== false) return 'bi-credit-card-fill';
    if (strpos($method, 'cash') !== false) return 'bi-cash-stack';
    if (strpos($method, 'bank') !== false) return 'bi-bank';
    return 'bi-wallet2';
}

function getPaymentMethodColor($method) {
    $method = strtolower($method ?? '');
    if (strpos($method, 'gcash') !== false) return '#007bff';
    if (strpos($method, 'maya') !== false || strpos($method, 'paymaya') !== false) return '#00a651';
    if (strpos($method, 'card') !== false) return '#1a1f71';
    if (strpos($method, 'cash') !== false) return '#10b981';
    return '#6b7280';
}

function getStatusBadgeClass($status) {
    $status = strtolower($status);
    if ($status === 'paid') return 'paid';
    if ($status === 'pending') return 'pending';
    if ($status === 'overdue') return 'overdue';
    if ($status === 'refunded') return 'refunded';
    if ($status === 'cancelled' || $status === 'failed' || $status === 'expired') return 'cancelled';
    return 'pending';
}

function getStatusIcon($status) {
    $status = strtolower($status);
    if ($status === 'paid') return 'bi-check-circle-fill';
    if ($status === 'pending') return 'bi-clock-fill';
    if ($status === 'overdue') return 'bi-exclamation-triangle-fill';
    if ($status === 'refunded') return 'bi-arrow-counterclockwise';
    if ($status === 'failed') return 'bi-x-circle-fill';
    if ($status === 'expired') return 'bi-hourglass-bottom';
    return 'bi-circle-fill';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments & Billing - UMak Gym: Fitsense</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --umak-blue: #005b9f;
            --umak-blue-dark: #00467a;
            --umak-yellow: #ffce00;
            --umak-green: #2e7d32;
            --umak-red: #d32f2f;
            --umak-orange: #f59e0b;
            --brand-accent: #005b9f;
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
           BALANCE CARD
           ============================================ */
        .balance-card {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 24px;
            padding: 35px 40px;
            color: white;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0,91,159,0.25);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .balance-card::before {
            content: '';
            position: absolute;
            top: -100px; right: -100px;
            width: 350px; height: 350px;
            background: radial-gradient(circle, rgba(255,206,0,0.18) 0%, transparent 70%);
            border-radius: 50%;
        }

        .balance-card::after {
            content: '';
            position: absolute;
            bottom: -80px; left: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(74,222,128,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .balance-info { position: relative; z-index: 2; }

        .balance-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 2.5px;
            color: rgba(255,255,255,0.7);
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .balance-amount {
            font-size: 56px;
            font-weight: 800;
            letter-spacing: -2px;
            line-height: 1;
            margin-bottom: 8px;
        }

        .balance-amount.zero { color: #4ade80; }

        .balance-sub {
            font-size: 14px;
            color: rgba(255,255,255,0.75);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .balance-actions {
            position: relative;
            z-index: 2;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-balance {
            padding: 14px 26px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
            text-decoration: none;
            letter-spacing: 0.3px;
            font-family: inherit;
        }

        .btn-balance.primary {
            background: var(--umak-yellow);
            color: #333;
            box-shadow: 0 6px 20px rgba(255,206,0,0.4);
        }

        .btn-balance.primary:hover {
            background: #e5b800;
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(255,206,0,0.5);
        }

        .btn-balance.outline {
            background: rgba(255,255,255,0.1);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }

        .btn-balance.outline:hover {
            background: rgba(255,255,255,0.2);
            border-color: rgba(255,255,255,0.5);
        }

        /* ============================================
           STATS ROW
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

        .stat-box-icon.green { background: linear-gradient(135deg, rgba(46,125,50,0.12), rgba(74,222,128,0.18)); color: var(--umak-green); }
        .stat-box-icon.orange { background: linear-gradient(135deg, rgba(245,158,11,0.12), rgba(251,191,36,0.18)); color: #b45309; }
        .stat-box-icon.blue { background: linear-gradient(135deg, rgba(0,91,159,0.12), rgba(0,132,209,0.18)); color: var(--umak-blue); }
        .stat-box-icon.purple { background: linear-gradient(135deg, rgba(99,91,255,0.12), rgba(139,92,246,0.18)); color: #7c3aed; }

        .stat-box-info { flex: 1; min-width: 0; }
        .stat-box-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .stat-box-value { font-size: 24px; font-weight: 800; color: #111827; line-height: 1.1; letter-spacing: -0.5px; }
        .stat-box-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }

        /* ============================================
           MAIN GRID
           ============================================ */
        .main-grid {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .panel {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 26px 28px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
            gap: 12px;
            flex-wrap: wrap;
        }

        .panel-title {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-title i { font-size: 20px; }

        /* ============================================
           CURRENT MEMBERSHIP CARD
           ============================================ */
        .membership-summary {
            background: linear-gradient(135deg, #f9fafb 0%, #ffffff 100%);
            border-radius: 16px;
            padding: 22px;
            border: 2px solid var(--border);
            margin-bottom: 18px;
            transition: all 0.25s ease;
        }

        .membership-summary:hover {
            border-color: rgba(0,91,159,0.2);
            box-shadow: 0 8px 20px rgba(0,0,0,0.04);
        }

        .membership-plan-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .membership-plan-name {
            font-size: 20px;
            font-weight: 800;
            color: #111827;
            margin: 0 0 4px;
            letter-spacing: -0.3px;
        }

        .membership-plan-meta {
            font-size: 12.5px;
            color: var(--text-muted);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .membership-plan-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .membership-price {
            text-align: right;
        }

        .membership-price .amount {
            font-size: 32px;
            font-weight: 800;
            color: var(--umak-blue);
            letter-spacing: -1px;
            line-height: 1;
        }

        .membership-price .period {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 4px;
        }

        .membership-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .membership-status.active {
            background: #dcfce7;
            color: #166534;
        }

        .membership-status.expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .membership-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        .membership-detail-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .membership-detail-label {
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            font-weight: 700;
        }

        .membership-detail-value {
            font-size: 14px;
            font-weight: 700;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ============================================
           LATEST PAYMENT CARD
           ============================================ */
        .latest-payment {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border-radius: 16px;
            padding: 22px;
            border: 2px solid rgba(46,125,50,0.25);
            margin-bottom: 18px;
            position: relative;
            overflow: hidden;
        }

        .latest-payment::before {
            content: '';
            position: absolute;
            top: -50px; right: -50px;
            width: 150px; height: 150px;
            background: radial-gradient(circle, rgba(255,255,255,0.4) 0%, transparent 70%);
            border-radius: 50%;
        }

        .latest-payment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            position: relative;
            z-index: 2;
            gap: 12px;
            flex-wrap: wrap;
        }

        .latest-payment-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #065f46;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .latest-payment-amount {
            font-size: 32px;
            font-weight: 800;
            color: #065f46;
            letter-spacing: -1px;
            line-height: 1;
            display: flex;
            align-items: baseline;
            gap: 8px;
            position: relative;
            z-index: 2;
        }

        .latest-payment-amount .paid-badge {
            font-size: 12px;
            font-weight: 900;
            background: var(--umak-green);
            color: white;
            padding: 4px 10px;
            border-radius: 8px;
            letter-spacing: 1px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .latest-payment-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid rgba(46,125,50,0.2);
            position: relative;
            z-index: 2;
        }

        .latest-payment-meta-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .latest-payment-meta-label {
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #065f46;
            font-weight: 700;
            opacity: 0.8;
        }

        .latest-payment-meta-value {
            font-size: 14px;
            font-weight: 800;
            color: #064e3b;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ============================================
           PENDING PAYMENT BANNER
           ============================================ */
        .pending-banner {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 16px;
            padding: 22px;
            border: 2px solid rgba(245,158,11,0.35);
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .pending-banner-content {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
            min-width: 220px;
        }

        .pending-banner-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: rgba(245,158,11,0.2);
            color: #b45309;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .pending-banner-text h4 {
            margin: 0 0 4px;
            font-size: 16px;
            font-weight: 800;
            color: #78350f;
        }

        .pending-banner-text p {
            margin: 0;
            font-size: 13px;
            color: #92400e;
            font-weight: 600;
        }

        /* ============================================
           ACTION BUTTONS
           ============================================ */
        .action-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .btn-action {
            padding: 12px 22px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
            text-decoration: none;
            letter-spacing: 0.3px;
            font-family: inherit;
        }

        .btn-action.primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 15px rgba(0,91,159,0.3);
        }

        .btn-action.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,91,159,0.4);
        }

        .btn-action.pay {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 15px rgba(0,91,159,0.3);
        }

        .btn-action.pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,91,159,0.4);
        }

        .btn-action.yellow {
            background: linear-gradient(135deg, #ffce00, #ffb800);
            color: #333;
            box-shadow: 0 4px 15px rgba(255,206,0,0.4);
        }

        .btn-action.yellow:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255,206,0,0.5);
        }

        .btn-action.outline {
            background: transparent;
            color: var(--umak-blue);
            border: 2px solid var(--umak-blue);
        }

        .btn-action.outline:hover {
            background: var(--umak-blue);
            color: white;
        }

        .btn-action i { font-size: 15px; }

        /* ============================================
           TRANSACTIONS TABLE
           ============================================ */
        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }

        .transaction-table th,
        .transaction-table td {
            padding: 14px 10px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .transaction-table th {
            color: var(--text-muted);
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            background: #f9fafb;
        }

        .transaction-table th:first-child { border-radius: 10px 0 0 0; }
        .transaction-table th:last-child { border-radius: 0 10px 0 0; }

        .transaction-table tbody tr { transition: background 0.2s; }
        .transaction-table tbody tr:hover { background: #f9fafb; }

        .invoice-id {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            color: var(--umak-blue);
            font-size: 12.5px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pill.paid { background: #dcfce7; color: #166534; }
        .status-pill.pending { background: #fef3c7; color: #92400e; }
        .status-pill.overdue { background: #fee2e2; color: #991b1b; }
        .status-pill.refunded { background: #e0e7ff; color: #3730a3; }
        .status-pill.cancelled { background: #f3f4f6; color: #6b7280; }

        .payment-method-cell {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        .payment-method-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: white;
            flex-shrink: 0;
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
           PAYMENT MODAL
           ============================================ */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 2000;
            padding: 20px;
        }

        .modal-overlay.open { opacity: 1; visibility: visible; }

        .modal {
            background: white;
            border-radius: 20px;
            width: 100%;
            max-width: 520px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9);
            transition: transform 0.3s ease;
            box-shadow: 0 25px 70px rgba(0,0,0,0.35);
        }

        .modal-overlay.open .modal { transform: scale(1); }

        .modal-header {
            padding: 24px 28px;
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

        /* Order Summary */
        .order-summary {
            background: #f9fafb;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
        }

        .order-summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            font-size: 13.5px;
        }

        .order-summary-row.total {
            padding-top: 14px;
            margin-top: 8px;
            border-top: 2px dashed var(--border);
            font-size: 18px;
            font-weight: 800;
            color: var(--umak-blue);
        }

        .order-summary-label {
            color: var(--text-muted);
            font-weight: 600;
        }

        .order-summary-value {
            font-weight: 700;
            color: #1f2937;
        }

        /* GCash / Maya payment method picker */
        .pay-method-section {
            margin-bottom: 20px;
        }

        .pay-method-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .method-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .method-option {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px 12px;
            border: 2px solid var(--border);
            border-radius: 14px;
            background: #fff;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }

        .method-option:hover {
            border-color: #c7d2fe;
            transform: translateY(-2px);
        }

        .method-option.selected {
            border-color: var(--umak-blue);
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        .method-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .method-logo {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 22px;
        }

        .method-logo.gcash { background: linear-gradient(135deg, #007bff, #00a1ff); }
        .method-logo.maya { background: linear-gradient(135deg, #00a651, #00c853); }

        .method-name {
            font-size: 14px;
            font-weight: 800;
            color: #1f2937;
        }

        .method-tag {
            font-size: 10.5px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .method-check {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--umak-blue);
            color: #fff;
            font-size: 11px;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .method-option.selected .method-check { display: flex; }

        .pay-error {
            color: #b91c1c;
            font-size: 12.5px;
            margin-top: 8px;
            margin-bottom: 4px;
            font-weight: 600;
            min-height: 18px;
        }

        .pay-secure-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 11.5px;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
        }

        .pay-secure-badge i {
            color: var(--umak-blue);
            font-size: 14px;
        }

        .btn-pay-now {
            width: 100%;
            padding: 15px 24px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.25s;
            box-shadow: 0 6px 20px rgba(0,91,159,0.35);
            font-family: inherit;
            letter-spacing: 0.3px;
        }

        .btn-pay-now:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(0,91,159,0.45);
        }

        .btn-pay-now:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-pay-now i { font-size: 17px; }

        /* Success state */
        .payment-success {
            text-align: center;
            padding: 20px 10px;
        }

        .payment-success-icon {
            width: 90px;
            height: 90px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2e7d32, #4ade80);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 44px;
            animation: successPop 0.5s ease;
            box-shadow: 0 15px 40px rgba(46,125,50,0.35);
        }

        @keyframes successPop {
            0% { transform: scale(0) rotate(-180deg); }
            50% { transform: scale(1.2) rotate(10deg); }
            100% { transform: scale(1) rotate(0); }
        }

        .payment-success h3 {
            margin: 0 0 8px;
            font-size: 22px;
            font-weight: 800;
            color: var(--umak-green);
        }

        .payment-success p {
            margin: 0 0 24px;
            color: var(--text-muted);
            font-size: 14px;
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
            .main-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .btn-chat-open span.label { display: none; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .balance-card { padding: 26px 22px; }
            .balance-amount { font-size: 40px; }
            .balance-actions { width: 100%; }
            .btn-balance { flex: 1; justify-content: center; }
            .panel { padding: 20px 18px; }
            .latest-payment-meta { grid-template-columns: 1fr; }
            .transaction-table { font-size: 12px; }
            .transaction-table th, .transaction-table td { padding: 10px 6px; }
        }

        @media (max-width: 576px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
            .action-bar .btn-action { flex: 1; justify-content: center; }
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
        <a href="payments.php" class="nav-item active">
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
                <h2>Payments & Billing</h2>
                <p class="subtitle">Manage your membership payments</p>
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
            <i class="bi bi-credit-card-fill"></i>
            Payments & Billing
        </h1>
        <p class="page-subtitle">View your current balance, membership charges, and payment history.</p>

        <!-- Alerts -->
        <?php if (!empty($_GET['success'])): ?>
            <div class="alert alert-success" style="padding: 14px 20px; border-radius: 12px; background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border-left: 4px solid var(--umak-green); margin-bottom: 20px; display: flex; align-items: center; gap: 12px; font-weight: 600;">
                <i class="bi bi-check-circle-fill" style="font-size: 20px;"></i>
                Payment successful! Your transaction has been recorded.
            </div>
        <?php endif; ?>

        <!-- Payment return (from Xendit/GCash/Maya) — status is verified server-side -->
        <?php if (isset($_GET['payment']) && $_GET['payment'] === 'return'): ?>
            <div class="alert" id="paymentReturnAlert" style="padding: 14px 20px; border-radius: 12px; background: linear-gradient(135deg, #e0f2fe, #bae6fd); color: #075985; border-left: 4px solid var(--umak-blue); margin-bottom: 20px; display: flex; align-items: center; gap: 12px; font-weight: 600;">
                <i class="bi bi-arrow-repeat" style="font-size: 20px; animation: spin 1s linear infinite;"></i>
                <span id="paymentReturnText">Verifying your payment with the provider&hellip;</span>
            </div>
            <style>@keyframes spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }</style>
        <?php endif; ?>

        <?php if (!$paymentGatewayEnabled): ?>
            <div class="alert" style="padding: 14px 20px; border-radius: 12px; background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; margin-bottom: 20px; font-weight: 600;">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Online payments are not configured yet. Please contact the gym office or try again later.
            </div>
        <?php endif; ?>

        <!-- ============================================
             BALANCE CARD
             ============================================ -->
        <div class="balance-card">
            <div class="balance-info">
                <div class="balance-label">
                    <i class="bi bi-wallet2"></i>
                    Current Balance
                </div>
                <div class="balance-amount <?= $currentBalance == 0 ? 'zero' : '' ?>">
                    ₱<?= number_format($currentBalance, 2) ?>
                </div>
                <div class="balance-sub">
                    <?php if ($currentBalance == 0): ?>
                        <i class="bi bi-check-circle-fill" style="color: #4ade80;"></i>
                        You're all paid up! No outstanding balance.
                    <?php else: ?>
                        <i class="bi bi-exclamation-circle-fill" style="color: #fbbf24;"></i>
                        You have an outstanding balance to settle.
                    <?php endif; ?>
                </div>
            </div>

            <div class="balance-actions">
                <?php if ($currentBalance > 0 && $pendingPayment): ?>
                    <button class="btn-balance primary" <?= $paymentGatewayEnabled ? 'onclick="openPaymentModal(' . (int)$pendingPayment['id'] . ', ' . (float)$pendingPayment['amount'] . ', \'' . htmlspecialchars(addslashes($pendingPayment['description']), ENT_QUOTES) . '\')"' : 'disabled title="Online payments are not configured"' ?>>
                        <i class="bi bi-credit-card-fill"></i> Pay Now
                    </button>
                <?php endif; ?>
                <a href="plans.php" class="btn-balance outline">
                    <i class="bi bi-arrow-repeat"></i> Renew Plan
                </a>
            </div>
        </div>

        <!-- ============================================
             STATS ROW
             ============================================ -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-box-icon green">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Total Paid</div>
                    <div class="stat-box-value">₱<?= number_format($totalPaid, 2) ?></div>
                    <div class="stat-box-sub">all time</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon blue">
                    <i class="bi bi-receipt"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Transactions</div>
                    <div class="stat-box-value"><?= count($transactions) ?></div>
                    <div class="stat-box-sub">total records</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon orange">
                    <i class="bi bi-clock-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Pending</div>
                    <div class="stat-box-value">₱<?= number_format($pendingAmount, 2) ?></div>
                    <div class="stat-box-sub">awaiting payment</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon purple">
                    <i class="bi bi-calendar-check-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Pending Since</div>
                    <div class="stat-box-value" style="font-size: 16px;">
                        <?= $pendingPayment ? date('M d, Y', strtotime($pendingPayment['created_at'])) : 'All clear' ?>
                    </div>
                    <div class="stat-box-sub">payment requested</div>
                </div>
            </div>
        </div>

        <!-- ============================================
             MAIN GRID
             ============================================ -->
        <div class="main-grid">
            <!-- LEFT: Membership + Latest Payment -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-ticket-perforated-fill"></i>
                        Current Membership
                    </h3>
                    <?php if ($membership): ?>
                        <span class="membership-status <?= strtolower($membership['status']) === 'active' ? 'active' : 'expired' ?>">
                            <i class="bi bi-circle-fill" style="font-size: 8px;"></i>
                            <?= strtoupper($membership['status']) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Membership Summary -->
                <?php if ($membership): ?>
                    <div class="membership-summary">
                        <div class="membership-plan-header">
                            <div>
                                <h4 class="membership-plan-name"><?= htmlspecialchars($membership['plan_name']) ?> Membership</h4>
                                <div class="membership-plan-meta">
                                    <span class="membership-plan-meta-item">
                                        <i class="bi bi-calendar-check"></i>
                                        <?= date('M d, Y', strtotime($membership['start_date'])) ?>
                                        — <?= date('M d, Y', strtotime($membership['expiry_date'])) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="membership-price">
                                <div class="amount">₱<?= number_format($membership['price'], 2) ?></div>
                                <div class="period">per term</div>
                            </div>
                        </div>

                        <div class="membership-details-grid">
                            <div class="membership-detail-item">
                                <div class="membership-detail-label">Plan Type</div>
                                <div class="membership-detail-value">
                                    <i class="bi bi-star-fill" style="color: #f59e0b; font-size: 13px;"></i>
                                    <?= htmlspecialchars($membership['plan_name']) ?>
                                </div>
                            </div>
                            <div class="membership-detail-item">
                                <div class="membership-detail-label">Valid Until</div>
                                <div class="membership-detail-value">
                                    <i class="bi bi-calendar-x" style="color: #005b9f; font-size: 13px;"></i>
                                    <?= date('M d, Y', strtotime($membership['expiry_date'])) ?>
                                </div>
                            </div>
                            <div class="membership-detail-item">
                                <div class="membership-detail-label">Status</div>
                                <div class="membership-detail-value">
                                    <i class="bi bi-check-circle-fill" style="color: #10b981; font-size: 13px;"></i>
                                    <?= htmlspecialchars($membership['status']) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding: 24px 16px;">
                        <div class="empty-state-icon">
                            <i class="bi bi-ticket-perforated"></i>
                        </div>
                        <h3>No Active Membership</h3>
                        <p>Choose a plan to start your membership.</p>
                        <a href="plans.php" class="btn-action primary" style="display:inline-flex; margin-top: 8px;">
                            <i class="bi bi-arrow-repeat"></i> View Plans
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Pending Payment Banner -->
                <?php if ($pendingPayment): ?>
                    <div class="pending-banner">
                        <div class="pending-banner-content">
                            <div class="pending-banner-icon">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                            </div>
                            <div class="pending-banner-text">
                                <h4>Payment Due: ₱<?= number_format($pendingPayment['amount'], 2) ?></h4>
                                <p><?= htmlspecialchars($pendingPayment['description']) ?> · Requested <?= date('M d, Y', strtotime($pendingPayment['created_at'])) ?></p>
                            </div>
                        </div>
                        <button class="btn-action pay" <?= $paymentGatewayEnabled ? 'onclick="openPaymentModal(' . (int)$pendingPayment['id'] . ', ' . (float)$pendingPayment['amount'] . ', \'' . htmlspecialchars(addslashes($pendingPayment['description']), ENT_QUOTES) . '\')"' : 'disabled title="Online payments are not configured"' ?>>
                            <i class="bi bi-credit-card-fill"></i> Pay Now
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Latest Paid Payment -->
                <?php if ($latestPayment): ?>
                    <div class="latest-payment">
                        <div class="latest-payment-header">
                            <div class="latest-payment-label">
                                <i class="bi bi-check-circle-fill"></i>
                                Latest Payment
                            </div>
                            <div class="latest-payment-amount">
                                ₱<?= number_format($latestPayment['amount'], 2) ?>
                                <span class="paid-badge">
                                    <i class="bi bi-check-lg"></i> PAID
                                </span>
                            </div>
                        </div>

                        <div class="latest-payment-meta">
                            <div class="latest-payment-meta-item">
                                <div class="latest-payment-meta-label">
                                    <i class="bi bi-calendar3"></i> Payment Date
                                </div>
                                <div class="latest-payment-meta-value">
                                    <?= $latestPayment['paid_at'] 
                                        ? date('F j, Y', strtotime($latestPayment['paid_at']))
                                        : date('F j, Y', strtotime($latestPayment['created_at'])) ?>
                                </div>
                            </div>
                            <div class="latest-payment-meta-item">
                                <div class="latest-payment-meta-label">
                                    <i class="bi bi-wallet2"></i> Payment Method
                                </div>
                                <div class="latest-payment-meta-value">
                                    <span class="payment-method-icon"
                                          style="background: <?= getPaymentMethodColor($latestPayment['payment_method']) ?>;">
                                        <i class="bi <?= getPaymentMethodIcon($latestPayment['payment_method']) ?>"></i>
                                    </span>
                                    <?= htmlspecialchars($latestPayment['payment_method'] ?? 'Card') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Action Bar -->
                <div class="action-bar">
                    <?php if ($latestPayment): ?>
                        <button class="btn-action primary" onclick="viewReceipt(<?= (int)$latestPayment['id'] ?>)">
                            <i class="bi bi-receipt"></i> View Receipt
                        </button>
                    <?php endif; ?>
                    <a href="#payment-history" class="btn-action outline">
                        <i class="bi bi-clock-history"></i> Payment History
                    </a>
                </div>
            </div>

            <!-- RIGHT: Quick Info -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-info-circle-fill"></i>
                        Payment Info
                    </h3>
                </div>

                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div style="padding: 16px; background: #f9fafb; border-radius: 12px; border-left: 4px solid var(--brand-accent);">
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); font-weight: 700; margin-bottom: 6px;">
                            <i class="bi bi-lightning-charge-fill" style="color: var(--brand-accent);"></i>
                            Accepted Payments
                        </div>
                        <div style="font-size: 13.5px; font-weight: 600; color: #1f2937;">
                            GCash and Maya e-wallets, processed securely via Xendit.
                        </div>
                    </div>

                    <div style="padding: 16px; background: #f9fafb; border-radius: 12px; border-left: 4px solid var(--umak-blue);">
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); font-weight: 700; margin-bottom: 6px;">
                            <i class="bi bi-shield-fill-check" style="color: var(--umak-blue);"></i>
                            Secure Transactions
                        </div>
                        <div style="font-size: 13.5px; font-weight: 600; color: #1f2937;">
                            Payments are verified server-side with our payment provider. We never store your e-wallet credentials.
                        </div>
                    </div>

                    <div style="padding: 16px; background: #f9fafb; border-radius: 12px; border-left: 4px solid var(--umak-yellow);">
                        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); font-weight: 700; margin-bottom: 6px;">
                            <i class="bi bi-question-circle-fill" style="color: #b45309;"></i>
                            Need Help?
                        </div>
                        <div style="font-size: 13.5px; font-weight: 600; color: #1f2937;">
                            Contact the gym office for payment issues or refunds.
                        </div>
                        <a href="help.php" style="display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: var(--umak-blue); text-decoration: none; margin-top: 8px;">
                            Visit Help Center <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================
             PAYMENT HISTORY
             ============================================ -->
        <div class="panel" id="payment-history">
            <div class="panel-header">
                <h3 class="panel-title">
                    <i class="bi bi-clock-history"></i>
                    Payment History
                </h3>
                <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;">
                    <?= count($transactions) ?> transaction<?= count($transactions) != 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if (empty($transactions)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <h3>No Payments Yet</h3>
                    <p>Your payment history will appear here once you make a transaction.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="transaction-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t):
                                $statusClass = getStatusBadgeClass($t['status']);
                                $statusIcon = getStatusIcon($t['status']);
                                $methodIcon = getPaymentMethodIcon($t['payment_method']);
                                $methodColor = getPaymentMethodColor($t['payment_method']);
                            ?>
                                <tr>
                                    <td><span class="invoice-id"><?= htmlspecialchars($t['reference_no']) ?></span></td>
                                    <td><?= htmlspecialchars($t['transaction_type']) ?></td>
                                    <td><?= htmlspecialchars($t['description']) ?></td>
                                    <td><?= date('M d, Y', strtotime($t['paid_at'] ?: $t['created_at'])) ?></td>
                                    <td><strong>₱<?= number_format($t['amount'], 2) ?></strong></td>
                                    <td>
                                        <div class="payment-method-cell">
                                            <span class="payment-method-icon" style="background: <?= $methodColor ?>;">
                                                <i class="bi <?= $methodIcon ?>"></i>
                                            </span>
                                            <?= htmlspecialchars($t['payment_method'] ?? 'N/A') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-pill <?= $statusClass ?>">
                                            <i class="bi <?= $statusIcon ?>"></i>
                                            <?= htmlspecialchars($t['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (strtolower($t['status']) === 'paid'): ?>
                                            <button class="btn-action outline" style="padding: 6px 14px; font-size: 11.5px;"
                                                    onclick="viewReceipt(<?= (int)$t['id'] ?>)">
                                                <i class="bi bi-receipt"></i> Receipt
                                            </button>
                                        <?php elseif (in_array(strtolower($t['status']), ['pending', 'overdue']) && $paymentGatewayEnabled): ?>
                                            <button class="btn-action pay" style="padding: 6px 14px; font-size: 11.5px;"
                                                    onclick="openPaymentModal(<?= (int)$t['id'] ?>, <?= (float)$t['amount'] ?>, '<?= htmlspecialchars(addslashes($t['description']), ENT_QUOTES) ?>')">
                                                <i class="bi bi-credit-card-fill"></i> Pay
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ============================================
     PAYMENT MODAL (GCash / Maya via Xendit)
     ============================================ -->
<div class="modal-overlay" id="paymentModal" onclick="closePaymentModal(event)">
    <div class="modal" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h3><i class="bi bi-credit-card-fill"></i> Complete Payment</h3>
            <button class="modal-close" onclick="closePaymentModal()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="modal-body" id="paymentModalBody">
            <!-- Order Summary -->
            <div class="order-summary">
                <div class="order-summary-row">
                    <span class="order-summary-label">Item</span>
                    <span class="order-summary-value" id="orderDescription">Membership Payment</span>
                </div>
                <div class="order-summary-row">
                    <span class="order-summary-label">Subtotal</span>
                    <span class="order-summary-value" id="orderSubtotal">₱0.00</span>
                </div>
                <div class="order-summary-row">
                    <span class="order-summary-label">Processing Fee</span>
                    <span class="order-summary-value">₱0.00</span>
                </div>
                <div class="order-summary-row total">
                    <span>Total</span>
                    <span id="orderTotal">₱0.00</span>
                </div>
            </div>

            <!-- Payment Method (GCash / Maya) -->
            <div class="pay-method-section">
                <div class="pay-method-label">
                    <i class="bi bi-wallet2"></i>
                    Choose Payment Method
                </div>
                <div class="method-options">
                    <label class="method-option" id="methodGcash">
                        <input type="radio" name="payMethod" value="GCASH">
                        <span class="method-check"><i class="bi bi-check-lg"></i></span>
                        <span class="method-logo gcash"><i class="bi bi-phone-fill"></i></span>
                        <span class="method-name">GCash</span>
                        <span class="method-tag">E-Wallet</span>
                    </label>
                    <label class="method-option" id="methodMaya">
                        <input type="radio" name="payMethod" value="PAYMAYA">
                        <span class="method-check"><i class="bi bi-check-lg"></i></span>
                        <span class="method-logo maya"><i class="bi bi-wallet2"></i></span>
                        <span class="method-name">Maya</span>
                        <span class="method-tag">E-Wallet</span>
                    </label>
                </div>
                <div class="pay-error" id="payError" role="alert"></div>
            </div>

            <!-- Pay Button -->
            <button type="button" class="btn-pay-now" id="submitPayment">
                <i class="bi bi-lock-fill"></i>
                <span id="payButtonText">Pay ₱0.00</span>
            </button>

            <div class="pay-secure-badge">
                <i class="bi bi-shield-lock-fill"></i>
                Secured by <strong style="color: var(--brand-accent);">Xendit</strong> · You will be redirected to your e-wallet
            </div>
        </div>
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
    // PAYMENT MODAL CONTROLS (GCash / Maya via Xendit)
    // ============================================
    const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    const GATEWAY_ENABLED = <?= $paymentGatewayEnabled ? 'true' : 'false' ?>;
    const PLAN_PRICES = <?= json_encode($planPrices) ?>;
    const SESSION_LAST_TID = <?= (int)($_SESSION['xendit_last_tid'] ?? 0) ?>;

    let currentTransactionId = 0;
    let currentMembershipType = '';
    let currentAmount = 0;
    let currentDescription = '';

    function selectMethod(optionEl) {
        document.querySelectorAll('.method-option').forEach(function (el) {
            el.classList.remove('selected');
        });
        optionEl.classList.add('selected');
        const errorDiv = document.getElementById('payError');
        if (errorDiv) errorDiv.textContent = '';
    }

    document.querySelectorAll('.method-option').forEach(function (el) {
        el.addEventListener('click', function () {
            const radio = el.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            selectMethod(el);
        });
    });

    // Open the modal for an existing pending transaction.
    function openPaymentModal(transactionId, amount, description) {
        currentTransactionId = transactionId;
        currentMembershipType = '';
        openPaymentModalCore(amount, description);
    }

    // Open the modal to buy a membership plan directly (?plan=...).
    function openPlanPaymentModal(membershipType, amount, description) {
        currentTransactionId = 0;
        currentMembershipType = membershipType;
        openPaymentModalCore(amount, description);
    }

    function openPaymentModalCore(amount, description) {
        currentAmount = amount;
        currentDescription = description || 'Membership Payment';

        // Reset method selection
        document.querySelectorAll('.method-option').forEach(function (el) {
            el.classList.remove('selected');
            const radio = el.querySelector('input[type="radio"]');
            if (radio) radio.checked = false;
        });
        document.getElementById('payError').textContent = '';

        // Update summary
        document.getElementById('orderDescription').textContent = currentDescription;
        document.getElementById('orderSubtotal').textContent = '₱' + amount.toLocaleString('en-PH', {minimumFractionDigits: 2});
        document.getElementById('orderTotal').textContent = '₱' + amount.toLocaleString('en-PH', {minimumFractionDigits: 2});
        document.getElementById('payButtonText').textContent = 'Pay ₱' + amount.toLocaleString('en-PH', {minimumFractionDigits: 2});
        document.getElementById('paymentModal').classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closePaymentModal(e) {
        if (e && e.target !== document.getElementById('paymentModal')) return;
        document.getElementById('paymentModal').classList.remove('open');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.getElementById('paymentModal').classList.remove('open');
            document.body.style.overflow = '';
        }
    });

    // ============================================
    // START PAYMENT — creates a Xendit payment request,
    // then redirects the customer to the e-wallet page.
    // ============================================
    document.getElementById('submitPayment').addEventListener('click', async function() {
        const btn = this;
        const btnText = document.getElementById('payButtonText');
        const errorDiv = document.getElementById('payError');

        errorDiv.textContent = '';

        const selected = document.querySelector('input[name="payMethod"]:checked');
        if (!selected) {
            errorDiv.textContent = 'Please choose GCash or Maya.';
            return;
        }
        if (!GATEWAY_ENABLED) {
            errorDiv.textContent = 'Online payments are not available right now.';
            return;
        }

        btn.disabled = true;
        btnText.textContent = 'Starting\u2026';

        try {
            const payload = {
                channel: selected.value,
                csrf_token: CSRF_TOKEN
            };
            if (currentMembershipType) {
                payload.membership_type = currentMembershipType;
            } else {
                payload.transaction_id = currentTransactionId;
            }

            const response = await fetch('create_payment_intent.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Could not start the payment.');
            }

            if (data.status === 'Paid') {
                showSuccessScreen(currentAmount);
                return;
            }

            if (!data.checkout_url) {
                throw new Error('No checkout page was returned. Please try again.');
            }

            // Redirect the customer to the GCash/Maya hosted page.
            btnText.textContent = 'Redirecting\u2026';
            window.location.href = data.checkout_url;
        } catch (err) {
            errorDiv.textContent = err.message;
            btn.disabled = false;
            btnText.textContent = 'Pay ₱' + currentAmount.toLocaleString('en-PH', {minimumFractionDigits: 2});
        }
    });

    function showSuccessScreen(amount) {
        document.getElementById('paymentModalBody').innerHTML = `
            <div class="payment-success">
                <div class="payment-success-icon">
                    <i class="bi bi-check-lg"></i>
                </div>
                <h3>Payment Successful!</h3>
                <p>
                    Your payment of <strong>₱${amount.toLocaleString('en-PH', {minimumFractionDigits: 2})}</strong> has been confirmed.<br>
                    A receipt is available in your payment history.
                </p>
                <button class="btn-pay-now" onclick="window.location.reload()">
                    <i class="bi bi-check-circle-fill"></i>
                    Done
                </button>
            </div>
        `;
    }

    // ============================================
    // PAYMENT RETURN — verify with the server (which re-checks
    // Xendit) and refresh the page with the real outcome.
    // Handles both the legacy (?payment=return) and the
    // status=success|cancelled|failed return URLs.
    // ============================================
    document.addEventListener('DOMContentLoaded', function () {
        const params = new URLSearchParams(window.location.search);
        const isReturn = params.get('payment') === 'return'
            || params.has('status')
            || params.has('tid');
        if (!isReturn) return;

        const alertBox = document.getElementById('paymentReturnAlert');
        const alertText = document.getElementById('paymentReturnText');

        // Prefer the explicit ?tid=; fall back to the last one we started.
        let tid = parseInt(params.get('tid') || '0', 10);
        if (!tid) tid = SESSION_LAST_TID;

        if (!tid) {
            if (alertText) alertText.textContent = 'We could not identify the payment to verify. Please check your payment history.';
            return;
        }

        fetch('record_payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify({ transaction_id: tid, csrf_token: CSRF_TOKEN })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            const status = (data.status || '').toLowerCase();
            if (status === 'paid') {
                // Reload cleanly so the updated balance/history is shown.
                window.location.replace('payments.php?success=1');
            } else if (status === 'pending') {
                if (alertText) alertText.textContent = data.message || 'Your payment is still being processed. This page will update once it is confirmed.';
                if (alertBox) {
                    alertBox.style.background = 'linear-gradient(135deg, #e0f2fe, #bae6fd)';
                }
            } else {
                if (alertText) alertText.textContent = data.message || ('Payment ' + status + '. You can try again below.');
                if (alertBox) {
                    alertBox.style.background = 'linear-gradient(135deg, #fee2e2, #fecaca)';
                    alertBox.style.color = '#991b1b';
                }
            }
        })
        .catch(function () {
            if (alertText) alertText.textContent = 'We could not verify your payment right now. It will update automatically once confirmed.';
        });
    });

    // ============================================
    // PLAN CHECKOUT — when arriving from plans.php (?plan=Monthly),
    // open the payment modal for that plan automatically.
    // ============================================
    document.addEventListener('DOMContentLoaded', function () {
        const params = new URLSearchParams(window.location.search);
        const plan = params.get('plan');
        if (!plan) return;

        if (Object.prototype.hasOwnProperty.call(PLAN_PRICES, plan)) {
            if (!GATEWAY_ENABLED) {
                alert('Online payments are not configured yet. Please contact the gym staff.');
                return;
            }
            openPlanPaymentModal(plan, PLAN_PRICES[plan], plan + ' Membership');
        } else {
            alert('That plan is not available. Please choose another plan.');
        }
    });

    // ============================================
    // VIEW RECEIPT
    // ============================================
    function viewReceipt(transactionId) {
        window.open('receipt.php?id=' + transactionId, '_blank', 'width=700,height=900');
    }

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
            }, 5000);
        });
    });
</script>
<?php include 'chat_widget.php'; ?>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>