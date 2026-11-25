<?php
// Admin/admin-payments.php
session_start();
include __DIR__ . '/../conn.php';
require_once __DIR__ . '/../config/session_security.php';

// ============================================
// AUTHENTICATION CHECK
// ============================================
$userSession = app_require_session_user($conn, ['admin', 'super_admin'], 'admin-login.php');
$role = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

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

function getTableColumns($conn, $tableName) {
    static $cache = [];
    if (isset($cache[$tableName])) return $cache[$tableName];
    $columns = [];
    $tableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW COLUMNS FROM `$tableName`");
    if ($result) {
        while ($row = $result->fetch_assoc()) $columns[] = $row['Field'];
    }
    $cache[$tableName] = $columns;
    return $columns;
}

function columnExists($conn, $tableName, $columnName) {
    return in_array($columnName, getTableColumns($conn, $tableName));
}

// ============================================
// CHECK TRANSACTIONS TABLE
// ============================================
if (!tableExists($conn, 'transactions')) {
    die("Transactions table not found in database.");
}

// ============================================
// FETCH ADMIN DATA
// ============================================
$adminData = null;
$stmtAdmin = $conn->prepare("SELECT id, fullname, username, profile_image, role FROM users WHERE id = ? LIMIT 1");
if ($stmtAdmin) {
    $stmtAdmin->bind_param("i", $userId);
    $stmtAdmin->execute();
    $adminData = $stmtAdmin->get_result()->fetch_assoc();
    $stmtAdmin->close();
}

$adminName = $adminData['fullname'] ?? 'Administrator';
$adminFirstName = explode(' ', trim($adminName))[0];
$adminInitial = strtoupper(substr($adminName, 0, 1));
$adminAvatar = $adminData['profile_image'] ?? '';

// ============================================
// HANDLE PAYMENT ACTIONS
// ============================================
$flashMessage = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_message'] = 'This form has expired or is invalid. Please refresh the page and try again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: admin-payments.php');
        exit;
    }

    $txnId = (int)($_POST['transaction_id'] ?? 0);
    $action = $_POST['action'];
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($txnId > 0) {

        // Fetch the transaction
        $getStmt = $conn->prepare("
            SELECT t.*, u.fullname, u.email, u.id AS user_id, u.username
            FROM transactions t
            INNER JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
            LIMIT 1
        ");
        $getStmt->bind_param("i", $txnId);
        $getStmt->execute();
        $txnRes = $getStmt->get_result()->fetch_assoc();
        $getStmt->close();

        if ($txnRes) {

            switch ($action) {

                // -----------------------------------------
                // MARK AS PAID
                // -----------------------------------------
                case 'mark_paid':
                    $ok = executeQuery($conn,
                        "UPDATE transactions SET status = 'Paid' WHERE id = ?",
                        "i", [$txnId]
                    );

                    if ($ok) {
                        if (tableExists($conn, 'notifications')) {
                            $notifTitle = "Payment Confirmed";
                            $notifMsg = "Your payment of ₱" . number_format($txnRes['amount'], 2) . " for {$txnRes['description']} has been confirmed. Thank you!";
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'general', ?, ?, NOW())",
                                "iss", [$txnRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }
                        $flashMessage = "Payment marked as <strong>Paid</strong>.";
                        $flashType = 'success';
                    } else {
                        $flashMessage = "Failed to update payment status.";
                        $flashType = 'error';
                    }
                    break;

                // -----------------------------------------
                // MARK AS PENDING
                // -----------------------------------------
                case 'mark_pending':
                    $ok = executeQuery($conn,
                        "UPDATE transactions SET status = 'Pending' WHERE id = ?",
                        "i", [$txnId]
                    );
                    if ($ok) {
                        $flashMessage = "Payment reset to <strong>Pending</strong>.";
                        $flashType = 'success';
                    }
                    break;

                // -----------------------------------------
                // CANCEL
                // -----------------------------------------
                case 'cancel':
                    $ok = executeQuery($conn,
                        "UPDATE transactions SET status = 'Cancelled' WHERE id = ?",
                        "i", [$txnId]
                    );

                    if ($ok) {
                        if (tableExists($conn, 'notifications')) {
                            $notifTitle = "Payment Cancelled";
                            $notifMsg = "Your payment of ₱" . number_format($txnRes['amount'], 2) . " has been cancelled." .
                                        (!empty($adminNote) ? " Reason: {$adminNote}" : "");
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'general', ?, ?, NOW())",
                                "iss", [$txnRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }
                        $flashMessage = "Payment cancelled.";
                        $flashType = 'success';
                    }
                    break;

                // -----------------------------------------
                // REFUND (mark as cancelled with note)
                // -----------------------------------------
                case 'refund':
                    $ok = executeQuery($conn,
                        "UPDATE transactions SET status = 'Cancelled' WHERE id = ?",
                        "i", [$txnId]
                    );

                    if ($ok) {
                        if (tableExists($conn, 'notifications')) {
                            $notifTitle = "Refund Processed";
                            $notifMsg = "Your refund of ₱" . number_format($txnRes['amount'], 2) . " has been processed. Please allow 3-5 business days for it to reflect.";
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'general', ?, ?, NOW())",
                                "iss", [$txnRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }
                        $flashMessage = "Refund processed successfully.";
                        $flashType = 'success';
                    }
                    break;

                // -----------------------------------------
                // DELETE RECORD
                // -----------------------------------------
                case 'delete':
                    $ok = executeQuery($conn, "DELETE FROM transactions WHERE id = ?", "i", [$txnId]);
                    if ($ok) {
                        $flashMessage = "Transaction record deleted permanently.";
                        $flashType = 'success';
                    }
                    break;
            }
        }
    }

    if (!empty($flashMessage)) {
        $_SESSION['flash_message'] = $flashMessage;
        $_SESSION['flash_type'] = $flashType;
        header("Location: admin-payments.php" . (!empty($_POST['redirect_query']) ? '?' . $_POST['redirect_query'] : ''));
        exit;
    }
}

if (!empty($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// ============================================
// FILTERS
// ============================================
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
$dateFilter = $_GET['date'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build WHERE
$where = ["1=1"];
$params = [];
$types = '';

if ($statusFilter !== 'all') {
    $where[] = "t.status = ?";
    $params[] = ucfirst($statusFilter);
    $types .= 's';
}

if ($typeFilter !== 'all') {
    $where[] = "t.transaction_type = ?";
    $params[] = ucfirst($typeFilter);
    $types .= 's';
}

if ($dateFilter === 'today') {
    $where[] = "DATE(t.created_at) = CURDATE()";
} elseif ($dateFilter === 'week') {
    $where[] = "YEARWEEK(t.created_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($dateFilter === 'month') {
    $where[] = "MONTH(t.created_at) = MONTH(CURDATE()) AND YEAR(t.created_at) = YEAR(CURDATE())";
} elseif ($dateFilter === 'year') {
    $where[] = "YEAR(t.created_at) = YEAR(CURDATE())";
}

if (!empty($search)) {
    $where[] = "(u.fullname LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR t.reference_no LIKE ? OR t.description LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sssss';
}

$whereSQL = implode(' AND ', $where);

// ============================================
// COUNT FOR PAGINATION
// ============================================
$totalRecords = 0;
$countSQL = "SELECT COUNT(*) AS total 
             FROM transactions t 
             INNER JOIN users u ON t.user_id = u.id 
             WHERE {$whereSQL}";

if (!empty($params)) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r && $row = $r->fetch_assoc()) $totalRecords = (int)$row['total'];
    $stmt->close();
} else {
    $r = $conn->query($countSQL);
    if ($r && $row = $r->fetch_assoc()) $totalRecords = (int)$row['total'];
}

$totalPages = max(1, ceil($totalRecords / $perPage));

// ============================================
// FETCH TRANSACTIONS
// ============================================
$transactions = [];
$channelCodeColumn = columnExists($conn, 'transactions', 'xendit_channel_code')
    ? 't.xendit_channel_code'
    : 'NULL AS xendit_channel_code';
$providerColumn = columnExists($conn, 'transactions', 'payment_provider')
    ? 't.payment_provider'
    : 'NULL AS payment_provider';
$fetchSQL = "
    SELECT t.id, t.reference_no, t.transaction_type, t.description, 
           t.amount, t.status, t.created_at, t.paid_at,
           {$channelCodeColumn}, {$providerColumn}, t.payment_method,
           u.id AS user_id, u.fullname, u.email, u.username, u.profile_image
    FROM transactions t
    INNER JOIN users u ON t.user_id = u.id
    WHERE {$whereSQL}
    ORDER BY 
        CASE t.status 
            WHEN 'Pending' THEN 1
            WHEN 'Paid' THEN 2
            WHEN 'Failed' THEN 3
            WHEN 'Expired' THEN 4
            WHEN 'Cancelled' THEN 5
            ELSE 9
        END,
        t.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($fetchSQL);
if ($stmt) {
    $fetchTypes = $types . 'ii';
    $fetchParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($fetchTypes, ...$fetchParams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt->close();
}

// ============================================
// STATS
// ============================================
$stats = [
    'total' => 0,
    'paid' => 0,
    'pending' => 0,
    'cancelled' => 0,
    'failed' => 0,
    'expired' => 0,
    'total_revenue' => 0,
    'month_revenue' => 0,
    'today_revenue' => 0,
    'pending_amount' => 0,
];

$r = $conn->query("SELECT COUNT(*) AS total FROM transactions");
if ($r && $row = $r->fetch_assoc()) $stats['total'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'Paid'");
if ($r && $row = $r->fetch_assoc()) $stats['paid'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'Pending'");
if ($r && $row = $r->fetch_assoc()) $stats['pending'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'Cancelled'");
if ($r && $row = $r->fetch_assoc()) $stats['cancelled'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'Failed'");
if ($r && $row = $r->fetch_assoc()) $stats['failed'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM transactions WHERE status = 'Expired'");
if ($r && $row = $r->fetch_assoc()) $stats['expired'] = (int)$row['total'];

$r = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM transactions WHERE status = 'Paid'");
if ($r && $row = $r->fetch_assoc()) $stats['total_revenue'] = (float)$row['total'];

$r = $conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM transactions 
    WHERE status = 'Paid' 
    AND MONTH(created_at) = MONTH(CURDATE()) 
    AND YEAR(created_at) = YEAR(CURDATE())
");
if ($r && $row = $r->fetch_assoc()) $stats['month_revenue'] = (float)$row['total'];

$r = $conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total FROM transactions 
    WHERE status = 'Paid' 
    AND DATE(created_at) = CURDATE()
");
if ($r && $row = $r->fetch_assoc()) $stats['today_revenue'] = (float)$row['total'];

$r = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM transactions WHERE status = 'Pending'");
if ($r && $row = $r->fetch_assoc()) $stats['pending_amount'] = (float)$row['total'];

// ============================================
// HELPER FUNCTIONS
// ============================================
function timeAgo($datetime) {
    if (empty($datetime)) return '—';
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
}

function getStatusClass($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'paid') return 'success';       // green
    if ($s === 'pending') return 'warning';    // yellow
    if ($s === 'failed') return 'danger';      // red
    if ($s === 'cancelled') return 'danger';   // red
    if ($s === 'expired') return 'secondary';  // grey
    return 'info';
}

function getStatusIcon($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'paid') return 'check-circle-fill';
    if ($s === 'pending') return 'hourglass-split';
    if ($s === 'failed') return 'x-octagon-fill';
    if ($s === 'cancelled') return 'x-circle-fill';
    if ($s === 'expired') return 'clock-history';
    return 'circle-fill';
}

function getTypeIcon($type) {
    $t = strtolower($type);
    if ($t === 'membership') return 'ticket-perforated-fill';
    if ($t === 'session') return 'calendar-event-fill';
    return 'credit-card-fill';
}

function getTypeColor($type) {
    $t = strtolower($type);
    if ($t === 'membership') return '#005b9f';
    if ($t === 'session') return '#8b5cf6';
    return '#6b7280';
}

/**
 * Human-readable membership plan for the "Plan" column.
 * Membership descriptions look like "Yearly Membership" -> "Yearly".
 */
function getPlanLabel($txn) {
    $type = strtolower(trim((string)($txn['transaction_type'] ?? '')));
    $desc = trim((string)($txn['description'] ?? ''));
    if ($type === 'membership') {
        $plan = trim((string)preg_replace('/\s*membership\s*$/i', '', $desc));
        return $plan !== '' ? $plan : 'Membership';
    }
    if ($type === 'session') return 'Session';
    return $desc !== '' ? $desc : 'Payment';
}

/**
 * Payment gateway (GCash / Maya) for the "Gateway" column.
 * Prefers the Xendit channel code, then falls back to payment_method/provider.
 */
function getGatewayInfo($txn) {
    $code = strtoupper(trim((string)($txn['xendit_channel_code'] ?? '')));
    if ($code === 'GCASH')   return ['label' => 'GCash', 'class' => 'gcash'];
    if ($code === 'PAYMAYA') return ['label' => 'Maya',  'class' => 'maya'];

    $method = strtolower(trim((string)($txn['payment_method'] ?? '')));
    if ($method !== '') {
        if (strpos($method, 'gcash') !== false) return ['label' => 'GCash', 'class' => 'gcash'];
        if (strpos($method, 'maya') !== false)  return ['label' => 'Maya',  'class' => 'maya'];
    }

    $provider = trim((string)($txn['payment_provider'] ?? ''));
    if ($provider !== '') return ['label' => $provider, 'class' => 'other'];

    return ['label' => '—', 'class' => 'none'];
}

function buildQuery($overrides = []) {
    $params = array_merge($_GET, $overrides);
    return http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Logs - FitSense Admin</title>
    <link rel="icon" type="image/png" href="../uploads/umak-gym-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --umak-blue: #005b9f;
            --umak-blue-dark: #00467a;
            --umak-yellow: #ffce00;
            --umak-red: #d32f2f;
            --umak-green: #2e7d32;
            --umak-orange: #f59e0b;
            --sidebar-width: 260px;
            --bg: #f4f6f9;
            --card-bg: #ffffff;
            --text: #1f2937;
            --text-muted: #6b7280;
            --border: #e5e7eb;
        }

        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { margin: 0; background-color: var(--bg); color: var(--text); }

        /* ============================================
           SIDEBAR
           ============================================ */
        .sidebar {
            position: fixed; left: 0; top: 0;
            width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, #005b9f 0%, #003a6b 100%);
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
            width: 45px; height: 45px; border-radius: 10px;
            background: rgba(255,255,255,0.1); padding: 3px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; overflow: hidden;
            border: 1px solid rgba(255,255,255,0.15);
        }

        .sidebar-brand .logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }

        .sidebar-brand .brand-info h3 {
            margin: 0; font-size: 16px; font-weight: 900;
            letter-spacing: 1.5px;
        }

        .sidebar-brand .brand-info p {
            margin: 2px 0 0; font-size: 10px; opacity: 0.65;
            text-transform: uppercase; letter-spacing: 1.5px;
        }

        .admin-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 800;
            letter-spacing: 0.5px; text-transform: uppercase;
            margin-top: 6px;
        }

        .admin-badge.super { background: rgba(255,206,0,0.2); color: var(--umak-yellow); border: 1px solid rgba(255,206,0,0.3); }
        .admin-badge.regular { background: rgba(74,222,128,0.2); color: #4ade80; border: 1px solid rgba(74,222,128,0.3); }

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
           MAIN WRAPPER
           ============================================ */
        .main-wrapper { margin-left: var(--sidebar-width); min-height: 100vh; padding-bottom: 40px; }

        .top-header {
            background: white; padding: 15px 30px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04); position: sticky; top: 0;
            z-index: 100; border-bottom: 3px solid var(--umak-yellow);
        }

        .header-left { display: flex; align-items: center; gap: 15px; }
        .sidebar-toggle {
            background: none; border: none; font-size: 22px;
            color: var(--umak-blue); cursor: pointer; display: none; padding: 5px;
        }
        .header-left h2 { margin: 0; font-size: 20px; color: var(--umak-blue); font-weight: 800; }
        .header-left .subtitle { font-size: 12px; color: var(--text-muted); margin: 0; }
        .header-right { display: flex; align-items: center; gap: 12px; }

        .header-btn {
            background: #f4f6f9; border: none; width: 42px; height: 42px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; position: relative; font-size: 18px;
            color: var(--umak-blue); transition: all 0.2s;
            text-decoration: none;
        }
        .header-btn:hover { background: #e5eef5; }

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
        .container { max-width: 1400px; margin: 30px auto; padding: 0 25px; }

        .page-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 20px; margin-bottom: 25px; flex-wrap: wrap;
        }

        .page-title {
            font-size: 26px; font-weight: 800; color: var(--umak-blue);
            margin: 0 0 6px; display: flex; align-items: center; gap: 12px;
        }

        .page-title i {
            font-size: 28px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle { color: var(--text-muted); font-size: 14px; margin: 0; }
        .page-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        /* Alerts */
        .alert {
            padding: 14px 20px; border-radius: 12px; font-size: 14px;
            font-weight: 600; margin-bottom: 20px; display: flex;
            align-items: center; gap: 12px; animation: slideDown 0.4s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border-left: 4px solid var(--umak-green); }
        .alert-error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border-left: 4px solid var(--umak-red); }

        .alert i { font-size: 20px; }

        /* ============================================
           REVENUE HERO
           ============================================ */
        .revenue-hero {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 20px; padding: 30px 35px;
            color: white; margin-bottom: 25px;
            position: relative; overflow: hidden;
            box-shadow: 0 15px 40px rgba(0,91,159,0.25);
            display: flex; justify-content: space-between;
            align-items: center; gap: 30px; flex-wrap: wrap;
        }

        .revenue-hero::before {
            content: ''; position: absolute;
            top: -100px; right: -100px;
            width: 350px; height: 350px;
            background: radial-gradient(circle, rgba(255,206,0,0.18) 0%, transparent 70%);
            border-radius: 50%;
        }

        .revenue-hero::after {
            content: ''; position: absolute;
            bottom: -80px; left: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(74,222,128,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .revenue-info {
            position: relative; z-index: 2;
        }

        .revenue-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 2.5px;
            color: rgba(255,255,255,0.7);
            font-weight: 700;
            margin-bottom: 10px;
            display: flex; align-items: center; gap: 8px;
        }

        .revenue-amount {
            font-size: 52px;
            font-weight: 800;
            letter-spacing: -1.5px;
            line-height: 1;
            margin-bottom: 8px;
        }

        .revenue-amount .currency {
            font-size: 32px;
            font-weight: 700;
            color: var(--umak-yellow);
            vertical-align: top;
            margin-right: 4px;
        }

        .revenue-sub {
            font-size: 13.5px;
            color: rgba(255,255,255,0.8);
            font-weight: 500;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }

        .revenue-sub .divider-dot {
            width: 4px; height: 4px; border-radius: 50%;
            background: rgba(255,255,255,0.4);
        }

        .revenue-stats {
            position: relative; z-index: 2;
            display: flex; gap: 15px; flex-wrap: wrap;
        }

        .revenue-stat-box {
            padding: 16px 22px;
            background: rgba(255,255,255,0.1);
            border-radius: 14px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.15);
            min-width: 130px;
        }

        .revenue-stat-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.7);
            font-weight: 700;
            margin-bottom: 6px;
        }

        .revenue-stat-value {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: var(--umak-yellow);
        }

        /* ============================================
           STATS CARDS
           ============================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px; margin-bottom: 25px;
        }

        .stat-box {
            background: white; border-radius: 16px; padding: 20px 22px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            display: flex; align-items: center; gap: 15px;
            transition: all 0.25s ease;
            text-decoration: none; color: inherit;
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
        .stat-box-icon.green { background: linear-gradient(135deg, rgba(46,125,50,0.12), rgba(74,222,128,0.18)); color: var(--umak-green); }
        .stat-box-icon.orange { background: linear-gradient(135deg, rgba(245,158,11,0.12), rgba(251,191,36,0.18)); color: #b45309; }
        .stat-box-icon.red { background: linear-gradient(135deg, rgba(211,47,47,0.12), rgba(248,113,113,0.18)); color: var(--umak-red); }

        .stat-box-info { flex: 1; min-width: 0; }
        .stat-box-label { font-size: 11px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .stat-box-value { font-size: 24px; font-weight: 800; color: #111827; line-height: 1.1; letter-spacing: -0.5px; }
        .stat-box-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }

        /* ============================================
           FILTER BAR
           ============================================ */
        .filter-bar {
            background: white; border-radius: 16px; padding: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 22px;
        }

        .filter-row {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
        }

        .search-wrapper {
            position: relative; flex: 1; min-width: 240px;
        }

        .search-wrapper i {
            position: absolute; left: 16px; top: 50%;
            transform: translateY(-50%); color: #9ca3af; font-size: 16px;
        }

        .search-wrapper input {
            width: 100%; padding: 12px 16px 12px 46px;
            border: 2px solid var(--border); border-radius: 10px;
            font-size: 14px; font-weight: 500; font-family: inherit;
            outline: none; transition: all 0.2s; background: #f9fafb;
            color: #111827;
        }

        .search-wrapper input:focus {
            border-color: var(--umak-blue); background: white;
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        .filter-select {
            padding: 12px 38px 12px 16px;
            border: 2px solid var(--border); border-radius: 10px;
            font-size: 13.5px; font-weight: 700; color: #374151;
            background: #f9fafb; cursor: pointer;
            outline: none; transition: all 0.2s;
            appearance: none; font-family: inherit;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%236b7280'%3e%3cpath d='M4.5 6l3.5 3.5L11.5 6z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }

        .filter-select:focus { border-color: var(--umak-blue); }

        .btn {
            padding: 11px 20px; border-radius: 10px; font-size: 13px;
            font-weight: 700; cursor: pointer; border: none;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; transition: all 0.25s ease; text-decoration: none;
            font-family: inherit; letter-spacing: 0.3px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 12px rgba(0,91,159,0.25);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,91,159,0.35); color: white; }

        .btn-outline {
            background: white; color: var(--umak-blue);
            border: 2px solid var(--umak-blue);
        }
        .btn-outline:hover { background: var(--umak-blue); color: white; }

        .btn-success {
            background: linear-gradient(135deg, #2e7d32, #4ade80);
            color: white;
        }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(46,125,50,0.35); color: white; }

        .btn-danger {
            background: linear-gradient(135deg, #d32f2f, #ef4444);
            color: white;
        }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(211,47,47,0.3); color: white; }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: #333;
        }
        .btn-warning:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(245,158,11,0.35); color: #333; }

        .btn-sm { padding: 8px 14px; font-size: 12px; }

        /* ============================================
           TABLE
           ============================================ */
        .table-card {
            background: white; border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            overflow: hidden;
        }

        .table-header {
            padding: 18px 24px; border-bottom: 2px solid var(--border);
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 12px;
        }

        .table-header h3 {
            margin: 0; font-size: 17px; font-weight: 800;
            color: var(--umak-blue);
            display: flex; align-items: center; gap: 10px;
        }

        .table-header h3 i { font-size: 20px; }

        .table-info {
            font-size: 13px; color: var(--text-muted); font-weight: 600;
        }

        .table-wrapper { overflow-x: auto; }

        .data-table {
            width: 100%; border-collapse: collapse; font-size: 13.5px;
        }

        .data-table th {
            padding: 14px 16px; text-align: left;
            color: var(--text-muted); font-weight: 700;
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 1px; background: #f9fafb;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .data-table td {
            padding: 16px; text-align: left;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .data-table tbody tr { transition: background 0.2s; }
        .data-table tbody tr:hover { background: #f9fafb; }
        .data-table tbody tr:last-child td { border-bottom: none; }

        .data-table tr.pending-row {
            background: linear-gradient(90deg, rgba(245,158,11,0.04), transparent 50%);
            border-left: 3px solid var(--umak-orange);
        }

        .user-cell {
            display: flex; align-items: center; gap: 12px;
        }

        .user-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 16px; flex-shrink: 0;
            overflow: hidden;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .user-info { min-width: 0; }
        .user-info .name {
            font-weight: 800; color: #111827; font-size: 14px;
            line-height: 1.2; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
            max-width: 180px;
        }
        .user-info .sub {
            font-size: 12px; color: var(--text-muted);
            margin-top: 2px;
        }

        /* Badges */
        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 11px; border-radius: 20px;
            font-size: 10.5px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .badge i { font-size: 9px; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-secondary { background: #f3f4f6; color: #4b5563; }

        /* Type Badge */
        .type-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; border-radius: 10px;
            font-size: 11.5px; font-weight: 800;
            letter-spacing: 0.3px;
            background: #f3f4f6;
        }

        /* Gateway chips */
        .gw-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 11px; border-radius: 20px;
            font-size: 11px; font-weight: 800;
            letter-spacing: 0.3px; white-space: nowrap;
        }
        .gw-chip i { font-size: 10px; }
        .gw-chip.gcash { background: #e0f2fe; color: #0369a1; }
        .gw-chip.maya { background: #dcfce7; color: #166534; }
        .gw-chip.other { background: #ede9fe; color: #5b21b6; }
        .gw-chip.none { background: #f3f4f6; color: #9ca3af; }

        /* Amount */
        .amount {
            font-size: 15px;
            font-weight: 800;
            color: #111827;
            letter-spacing: -0.3px;
        }

        .amount.paid { color: var(--umak-green); }
        .amount.pending { color: #b45309; }
        .amount.cancelled { color: var(--umak-red); }
        .amount.failed { color: var(--umak-red); }
        .amount.expired { color: #6b7280; }

        .ref-no {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            font-weight: 700;
            color: var(--umak-blue);
            background: rgba(0,91,159,0.08);
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
        }

        .txn-description {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            max-width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Action buttons */
        .action-group {
            display: flex; gap: 6px; align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .action-btn {
            padding: 7px 12px; border-radius: 8px;
            font-size: 11.5px; font-weight: 800;
            cursor: pointer; border: none;
            display: inline-flex; align-items: center; gap: 5px;
            transition: all 0.2s ease;
            text-transform: uppercase; letter-spacing: 0.3px;
            font-family: inherit;
            white-space: nowrap;
        }

        .action-btn.paid {
            background: linear-gradient(135deg, #2e7d32, #4ade80);
            color: white;
            box-shadow: 0 2px 8px rgba(46,125,50,0.25);
        }
        .action-btn.paid:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(46,125,50,0.35); }

        .action-btn.refund {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: #333;
        }
        .action-btn.refund:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(245,158,11,0.35); }

        .action-btn.cancel {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .action-btn.cancel:hover { background: #fde68a; }

        .action-btn.reactivate {
            background: white;
            color: var(--umak-blue);
            border: 1.5px solid var(--umak-blue);
        }
        .action-btn.reactivate:hover { background: var(--umak-blue); color: white; }

        .icon-btn {
            width: 34px; height: 34px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; border: none; font-size: 14px;
            transition: all 0.2s ease;
            background: #f3f4f6; color: #4b5563;
            text-decoration: none;
        }

        .icon-btn:hover { transform: translateY(-2px); }
        .icon-btn.view:hover { background: rgba(0,91,159,0.15); color: var(--umak-blue); }
        .icon-btn.delete:hover { background: rgba(211,47,47,0.15); color: var(--umak-red); }

        /* Empty state */
        .empty-state {
            text-align: center; padding: 70px 20px;
        }

        .empty-state-icon {
            width: 90px; height: 90px; margin: 0 auto 20px;
            background: rgba(0,91,159,0.08); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 40px; color: var(--umak-blue);
        }

        .empty-state h3 {
            margin: 0 0 10px; color: var(--umak-blue);
            font-size: 20px; font-weight: 800;
        }

        .empty-state p {
            color: var(--text-muted); font-size: 14px;
            margin: 0 0 22px; line-height: 1.6;
            max-width: 400px; margin-left: auto; margin-right: auto;
        }

        /* Pagination */
        .pagination {
            display: flex; justify-content: center; align-items: center;
            gap: 8px; padding: 20px 24px;
            border-top: 1px solid var(--border);
            background: #fafbfc;
            flex-wrap: wrap;
        }

        .page-link {
            min-width: 40px; height: 40px; padding: 0 14px;
            border-radius: 10px; display: inline-flex;
            align-items: center; justify-content: center;
            font-size: 13.5px; font-weight: 700;
            color: #4b5563; background: white;
            border: 1.5px solid var(--border);
            text-decoration: none; transition: all 0.2s ease;
            cursor: pointer;
        }

        .page-link:hover:not(.disabled):not(.active) {
            background: #f3f4f6;
            border-color: var(--umak-blue);
            color: var(--umak-blue);
        }

        .page-link.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; border-color: transparent;
            box-shadow: 0 4px 12px rgba(0,91,159,0.3);
        }

        .page-link.disabled {
            opacity: 0.4; cursor: not-allowed;
        }

        .page-info {
            font-size: 13px; color: var(--text-muted);
            font-weight: 600; margin: 0 8px;
        }

        /* ============================================
           MODAL
           ============================================ */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; visibility: hidden;
            transition: all 0.3s ease;
            z-index: 2000; padding: 20px;
        }

        .modal-overlay.open { opacity: 1; visibility: visible; }

        .modal {
            background: white; border-radius: 18px;
            width: 100%; max-width: 520px;
            max-height: 90vh; overflow-y: auto;
            transform: scale(0.9);
            transition: transform 0.3s ease;
            box-shadow: 0 25px 70px rgba(0,0,0,0.3);
        }

        .modal-overlay.open .modal { transform: scale(1); }

        .modal-header {
            padding: 22px 26px;
            border-bottom: 2px solid var(--border);
            display: flex; justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0; font-size: 18px; font-weight: 800;
            color: var(--umak-blue);
            display: flex; align-items: center; gap: 10px;
        }

        .modal-close {
            background: #f3f4f6; border: none;
            width: 36px; height: 36px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center;
            justify-content: center; font-size: 16px;
            color: var(--text-muted); transition: all 0.2s;
        }

        .modal-close:hover { background: #e5e7eb; transform: rotate(90deg); }

        .modal-body { padding: 24px 26px; }

        .modal-detail-list {
            display: flex; flex-direction: column; gap: 10px;
            margin-bottom: 20px;
        }

        .modal-detail-item {
            display: flex; justify-content: space-between;
            align-items: center; padding: 12px 16px;
            background: #f9fafb; border-radius: 10px;
            border: 1px solid var(--border);
        }

        .modal-detail-label {
            font-size: 12px; font-weight: 700;
            color: var(--text-muted); text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .modal-detail-value {
            font-size: 14px; font-weight: 800;
            color: #111827;
            text-align: right;
        }

        .form-group { margin-bottom: 16px; }

        .form-label {
            display: block;
            font-size: 12px; font-weight: 800;
            color: #374151; text-transform: uppercase;
            letter-spacing: 0.8px; margin-bottom: 8px;
        }

        .form-control {
            width: 100%; padding: 12px 14px;
            border: 2px solid var(--border); border-radius: 10px;
            font-size: 14px; font-weight: 500;
            color: #111827; background: #f9fafb;
            transition: all 0.25s ease;
            font-family: inherit; outline: none;
        }

        .form-control:focus {
            border-color: var(--umak-blue); background: white;
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        textarea.form-control {
            resize: vertical; min-height: 100px;
            line-height: 1.6;
        }

        .modal-footer {
            padding: 18px 26px 22px;
            display: flex; gap: 10px; justify-content: flex-end;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .modal-danger-text {
            background: #fee2e2; border-left: 4px solid var(--umak-red);
            padding: 14px 18px; border-radius: 10px;
            font-size: 13.5px; color: #991b1b;
            line-height: 1.6; margin-bottom: 16px;
        }

        .modal-warning-text {
            background: #fef3c7; border-left: 4px solid var(--umak-orange);
            padding: 14px 18px; border-radius: 10px;
            font-size: 13.5px; color: #78350f;
            line-height: 1.6; margin-bottom: 16px;
        }

        .modal-info-text {
            background: #f0f7fc; border-left: 4px solid var(--umak-blue);
            padding: 14px 18px; border-radius: 10px;
            font-size: 13.5px; color: #1e40af;
            line-height: 1.6; margin-bottom: 16px;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .filter-row { flex-direction: column; align-items: stretch; }
            .search-wrapper { min-width: auto; }
            .filter-select { width: 100%; }
            .data-table { font-size: 12px; }
            .data-table th, .data-table td { padding: 12px 8px; }
            .user-info .name { max-width: 140px; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .revenue-hero { padding: 22px; }
            .revenue-amount { font-size: 36px; }
            .revenue-stats { width: 100%; }
            .revenue-stat-box { flex: 1; }
        }

        @media (max-width: 576px) {
            .stats-row { grid-template-columns: 1fr; }
            .user-info .name { max-width: 120px; font-size: 13px; }
            .user-avatar { width: 38px; height: 38px; font-size: 14px; }
            .page-link { min-width: 36px; height: 36px; padding: 0 10px; font-size: 12px; }
            .action-btn { padding: 6px 10px; font-size: 10.5px; }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css">
<link rel="stylesheet" href="/PBL/css/admin-content-icons.css"></head>
<body>

<!-- ============================================
     SIDEBAR
     ============================================ -->
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
        <a href="admin-sessions.php" class="nav-item">
            <i class="bi bi-calendar-event-fill"></i><span>Sessions</span>
        </a>
         <a href="admin-classes.php" class="nav-item">
            <i class="bi bi-calendar-event-fill"></i><span>Classes</span>
        </a>
        <a href="admin-payments.php" class="nav-item active">
            <i class="bi bi-credit-card-fill"></i><span>Payment Logs</span>
            <?php if ($stats['pending'] > 0): ?>
                <span class="badge-count"><?= $stats['pending'] ?></span>
            <?php endif; ?>
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

<!-- ============================================
     MAIN WRAPPER
     ============================================ -->
<div class="main-wrapper">

    <header class="top-header">
        <div class="header-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h2>Payments</h2>
                <p class="subtitle">Manage transactions and revenue</p>
            </div>
        </div>

        <div class="header-right">
            <a href="admin-dashboard.php" class="header-btn" title="Dashboard">
                <i class="bi bi-speedometer2"></i>
            </a>
            <a href="admin-notifications.php" class="header-btn" title="Notifications">
                <i class="bi bi-bell-fill"></i>
                <?php if ($stats['pending'] > 0): ?>
                    <span class="notif-badge"><?= $stats['pending'] ?></span>
                <?php endif; ?>
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

        <!-- ============================================
             PAGE HEADER
             ============================================ -->
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="bi bi-credit-card-fill"></i>
                    Payment Logs
                </h1>
                <p class="page-subtitle">Track revenue, review GCash/Maya payments, and manage all gym transactions.</p>
            </div>
            <div class="page-actions">
                <a href="admin-payments-export.php?<?= htmlspecialchars(buildQuery(['export' => 'csv'])) ?>"
                   class="btn btn-outline" target="_blank">
                    <i class="bi bi-download"></i> Export CSV
                </a>
                <a href="admin-reports.php" class="btn btn-primary">
                    <i class="bi bi-graph-up-arrow"></i> View Reports
                </a>
            </div>
        </div>

        <!-- Flash Message -->
        <?php if (!empty($flashMessage)): ?>
            <div class="alert alert-<?= $flashType ?>">
                <i class="bi bi-<?= $flashType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
                <div><?= $flashMessage ?></div>
            </div>
        <?php endif; ?>

        <!-- ============================================
             REVENUE HERO
             ============================================ -->
        <div class="revenue-hero">
            <div class="revenue-info">
                <div class="revenue-label">
                    <i class="bi bi-cash-stack"></i>
                    Total Revenue
                </div>
                <div class="revenue-amount">
                    <span class="currency">₱</span><?= number_format($stats['total_revenue'], 2) ?>
                </div>
                <div class="revenue-sub">
                    <span><i class="bi bi-check-circle-fill"></i> <?= number_format($stats['paid']) ?> paid transactions</span>
                    <span class="divider-dot"></span>
                    <span><i class="bi bi-graph-up-arrow"></i> ₱<?= number_format($stats['month_revenue'], 0) ?> this month</span>
                </div>
            </div>

            <div class="revenue-stats">
                <div class="revenue-stat-box">
                    <div class="revenue-stat-label">Today</div>
                    <div class="revenue-stat-value">₱<?= number_format($stats['today_revenue'], 0) ?></div>
                </div>
                <div class="revenue-stat-box">
                    <div class="revenue-stat-label">Pending</div>
                    <div class="revenue-stat-value">₱<?= number_format($stats['pending_amount'], 0) ?></div>
                </div>
            </div>
        </div>

        <!-- ============================================
             STATS CARDS (Clickable Filters)
             ============================================ -->
        <div class="stats-row">
            <a href="?status=all&date=all" class="stat-box <?= ($statusFilter === 'all' && $dateFilter === 'all') ? 'active' : '' ?>">
                <div class="stat-box-icon blue">
                    <i class="bi bi-collection-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Total Transactions</div>
                    <div class="stat-box-value"><?= number_format($stats['total']) ?></div>
                    <div class="stat-box-sub">All records</div>
                </div>
            </a>

            <a href="?status=paid" class="stat-box <?= $statusFilter === 'paid' ? 'active' : '' ?>">
                <div class="stat-box-icon green">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Paid</div>
                    <div class="stat-box-value"><?= number_format($stats['paid']) ?></div>
                    <div class="stat-box-sub">Completed</div>
                </div>
            </a>

            <a href="?status=pending" class="stat-box <?= $statusFilter === 'pending' ? 'active' : '' ?>">
                <div class="stat-box-icon orange">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Pending</div>
                    <div class="stat-box-value"><?= number_format($stats['pending']) ?></div>
                    <div class="stat-box-sub">Awaiting payment</div>
                </div>
            </a>

            <a href="?status=cancelled" class="stat-box <?= $statusFilter === 'cancelled' ? 'active' : '' ?>">
                <div class="stat-box-icon red">
                    <i class="bi bi-x-circle-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Cancelled</div>
                    <div class="stat-box-value"><?= number_format($stats['cancelled']) ?></div>
                    <div class="stat-box-sub">Voided / refunded</div>
                </div>
            </a>

            <a href="?status=failed" class="stat-box <?= $statusFilter === 'failed' ? 'active' : '' ?>">
                <div class="stat-box-icon red">
                    <i class="bi bi-x-octagon-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Failed</div>
                    <div class="stat-box-value"><?= number_format($stats['failed']) ?></div>
                    <div class="stat-box-sub">Unsuccessful</div>
                </div>
            </a>
        </div>

        <!-- ============================================
             FILTER BAR
             ============================================ -->
        <form method="GET" class="filter-bar">
            <div class="filter-row">
                <div class="search-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search by member, reference #, or description..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>

                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                </select>

                <select name="type" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="membership" <?= $typeFilter === 'membership' ? 'selected' : '' ?>>Membership</option>
                    <option value="session" <?= $typeFilter === 'session' ? 'selected' : '' ?>>Session</option>
                </select>

                <select name="date" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>All Dates</option>
                    <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="year" <?= $dateFilter === 'year' ? 'selected' : '' ?>>This Year</option>
                </select>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel-fill"></i> Apply
                </button>

                <?php if (!empty($search) || $statusFilter !== 'all' || $typeFilter !== 'all' || $dateFilter !== 'all'): ?>
                    <a href="admin-payments.php" class="btn btn-outline">
                        <i class="bi bi-x-lg"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- ============================================
             TRANSACTIONS TABLE
             ============================================ -->
        <div class="table-card">
            <div class="table-header">
                <h3>
                    <i class="bi bi-list-ul"></i>
                    Transaction Records
                    <?php if ($totalRecords > 0): ?>
                        <span style="background:#e0f2fe; color:#0369a1; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:800;">
                            <?= number_format($totalRecords) ?>
                        </span>
                    <?php endif; ?>
                </h3>
                <div class="table-info">
                    <?php if ($totalRecords > 0): ?>
                        Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($transactions)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <h3>No Transactions Found</h3>
                    <p>
                        <?php if (!empty($search) || $statusFilter !== 'all' || $typeFilter !== 'all' || $dateFilter !== 'all'): ?>
                            No transactions match your current filters. Try adjusting your search criteria.
                        <?php else: ?>
                            No transactions have been recorded yet. They'll appear here once payments are made.
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($search) || $statusFilter !== 'all' || $typeFilter !== 'all' || $dateFilter !== 'all'): ?>
                        <a href="admin-payments.php" class="btn btn-primary">
                            <i class="bi bi-x-circle"></i> Clear Filters
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Reference</th>
                                <th>Plan</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Gateway</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t):
                                $initials = strtoupper(substr($t['fullname'] ?? 'U', 0, 1));
                                if (strpos($t['fullname'] ?? '', ' ') !== false) {
                                    $parts = explode(' ', $t['fullname']);
                                    $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
                                }
                                $statusClass = getStatusClass($t['status']);
                                $statusIcon = getStatusIcon($t['status']);
                                $isPending = strtolower($t['status']) === 'pending';
                                $isPaid = strtolower($t['status']) === 'paid';
                                $isCancelled = strtolower($t['status']) === 'cancelled';
                                $typeIcon = getTypeIcon($t['transaction_type']);
                                $typeColor = getTypeColor($t['transaction_type']);
                            ?>
                                <tr class="<?= $isPending ? 'pending-row' : '' ?>">
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar">
                                                <?php if (!empty($t['profile_image']) && file_exists(__DIR__ . '/../' . $t['profile_image'])): ?>
                                                    <img src="../<?= htmlspecialchars($t['profile_image']) ?>" alt="">
                                                <?php else: ?>
                                                    <?= htmlspecialchars($initials) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="user-info">
                                                <div class="name"><?= htmlspecialchars($t['fullname']) ?></div>
                                                <div class="sub" title="<?= htmlspecialchars($t['email'] ?? '') ?>"><?= htmlspecialchars(!empty($t['email']) ? $t['email'] : '@' . $t['username']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="ref-no"><?= htmlspecialchars($t['reference_no']) ?></span>
                                    </td>
                                    <td>
                                        <span class="type-badge" style="background: <?= $typeColor ?>15; color: <?= $typeColor ?>;">
                                            <i class="bi bi-<?= $typeIcon ?>"></i>
                                            <?= htmlspecialchars(getPlanLabel($t)) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="txn-description" title="<?= htmlspecialchars($t['description']) ?>">
                                            <?= htmlspecialchars($t['description']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="amount <?= strtolower($t['status']) ?>">
                                            ₱<?= number_format($t['amount'], 2) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php $gw = getGatewayInfo($t); ?>
                                        <span class="gw-chip <?= htmlspecialchars($gw['class']) ?>">
                                            <?php if ($gw['class'] === 'gcash' || $gw['class'] === 'maya'): ?>
                                                <i class="bi bi-wallet2"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($gw['label']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $statusClass ?>">
                                            <i class="bi bi-<?= $statusIcon ?>"></i>
                                            <?= htmlspecialchars($t['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php $shownDate = !empty($t['paid_at']) ? $t['paid_at'] : $t['created_at']; ?>
                                        <div style="font-size: 12.5px; font-weight: 600; color: #374151;">
                                            <?= date('M j, Y', strtotime($shownDate)) ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                            <?= timeAgo($shownDate) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <?php if ($isPending): ?>
                                                <button type="button"
                                                        class="action-btn paid"
                                                        onclick="markPaid(<?= (int)$t['id'] ?>, '<?= htmlspecialchars(addslashes($t['fullname'])) ?>', '<?= number_format($t['amount'], 2) ?>', '<?= htmlspecialchars(addslashes($t['description'])) ?>')">
                                                    <i class="bi bi-check-circle-fill"></i> Mark Paid
                                                </button>
                                                <button type="button"
                                                        class="action-btn cancel"
                                                        onclick="cancelTxn(<?= (int)$t['id'] ?>, '<?= htmlspecialchars(addslashes($t['fullname'])) ?>')">
                                                    <i class="bi bi-x-circle"></i> Cancel
                                                </button>

                                            <?php elseif ($isPaid): ?>
                                                <button type="button"
                                                        class="action-btn refund"
                                                        onclick="refundTxn(<?= (int)$t['id'] ?>, '<?= htmlspecialchars(addslashes($t['fullname'])) ?>', '<?= number_format($t['amount'], 2) ?>')">
                                                    <i class="bi bi-arrow-counterclockwise"></i> Refund
                                                </button>

                                            <?php elseif ($isCancelled): ?>
                                                <button type="button"
                                                        class="action-btn reactivate"
                                                        onclick="markPending(<?= (int)$t['id'] ?>, '<?= htmlspecialchars(addslashes($t['fullname'])) ?>')">
                                                    <i class="bi bi-arrow-clockwise"></i> Reactivate
                                                </button>
                                            <?php endif; ?>

                                            <a href="admin-member-view.php?id=<?= (int)$t['user_id'] ?>"
                                               class="icon-btn view" title="View Member">
                                                <i class="bi bi-eye-fill"></i>
                                            </a>

                                            <button type="button"
                                                    class="icon-btn delete" title="Delete Record"
                                                    onclick="deleteTxn(<?= (int)$t['id'] ?>, '<?= htmlspecialchars(addslashes($t['fullname'])) ?>')">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ============================================
                     PAGINATION
                     ============================================ -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <a href="?<?= htmlspecialchars(buildQuery(['page' => max(1, $page - 1)])) ?>"
                           class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1) {
                            echo '<a href="?' . htmlspecialchars(buildQuery(['page' => 1])) . '" class="page-link">1</a>';
                            if ($startPage > 2) echo '<span class="page-info">...</span>';
                        }
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="?<?= htmlspecialchars(buildQuery(['page' => $i])) ?>"
                               class="page-link <?= $i === $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <?php
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) echo '<span class="page-info">...</span>';
                            echo '<a href="?' . htmlspecialchars(buildQuery(['page' => $totalPages])) . '" class="page-link">' . $totalPages . '</a>';
                        }
                        ?>

                        <a href="?<?= htmlspecialchars(buildQuery(['page' => min($totalPages, $page + 1)])) ?>"
                           class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ============================================
     MARK PAID MODAL
     ============================================ -->
<div class="modal-overlay" id="paidModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-check-circle-fill" style="color: var(--umak-green);"></i> Mark as Paid</h3>
            <button class="modal-close" onclick="closeModal('paidModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <div class="modal-detail-list">
                <div class="modal-detail-item">
                    <span class="modal-detail-label">Member</span>
                    <span class="modal-detail-value" id="paidMemberName">—</span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">Description</span>
                    <span class="modal-detail-value" id="paidDescription">—</span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">Amount</span>
                    <span class="modal-detail-value" id="paidAmount" style="color: var(--umak-green); font-size: 18px;">—</span>
                </div>
            </div>

            <div class="modal-info-text">
                <strong><i class="bi bi-info-circle-fill"></i> Note:</strong>
                Confirming will mark this payment as paid and notify the member.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('paidModal')">Cancel</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="mark_paid">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="transaction_id" id="paidTxnId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle-fill"></i> Confirm Payment
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ============================================
     CANCEL MODAL
     ============================================ -->
<div class="modal-overlay" id="cancelModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-x-circle-fill" style="color: var(--umak-red);"></i> Cancel Transaction</h3>
            <button class="modal-close" onclick="closeModal('cancelModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <p style="margin: 0 0 16px; font-size: 14.5px; color: #4b5563; line-height: 1.6;">
                    Cancel the transaction for <strong id="cancelMemberName">—</strong>?
                </p>

                <div class="form-group">
                    <label class="form-label">Reason (Optional)</label>
                    <textarea name="admin_note" class="form-control"
                              placeholder="e.g., Payment not received, member requested, etc."></textarea>
                </div>

                <div class="modal-warning-text">
                    <strong><i class="bi bi-info-circle-fill"></i> Note:</strong>
                    The member will be notified about the cancellation.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('cancelModal')">Cancel</button>
                <input type="hidden" name="action" value="cancel">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="transaction_id" id="cancelTxnId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-x-circle-fill"></i> Cancel Transaction
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================
     REFUND MODAL
     ============================================ -->
<div class="modal-overlay" id="refundModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-arrow-counterclockwise" style="color: #b45309;"></i> Process Refund</h3>
            <button class="modal-close" onclick="closeModal('refundModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="modal-detail-list">
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">Member</span>
                        <span class="modal-detail-value" id="refundMemberName">—</span>
                    </div>
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">Refund Amount</span>
                        <span class="modal-detail-value" id="refundAmount" style="color: #b45309; font-size: 18px;">—</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Reason for Refund</label>
                    <textarea name="admin_note" class="form-control"
                              placeholder="e.g., Member requested, service issue, etc."></textarea>
                </div>

                <div class="modal-warning-text">
                    <strong><i class="bi bi-info-circle-fill"></i> Note:</strong>
                    This will mark the transaction as cancelled. The member will be notified that the refund is being processed (3–5 business days).
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('refundModal')">Cancel</button>
                <input type="hidden" name="action" value="refund">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="transaction_id" id="refundTxnId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-arrow-counterclockwise"></i> Process Refund
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================
     REACTIVATE MODAL
     ============================================ -->
<div class="modal-overlay" id="reactivateModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-arrow-clockwise" style="color: var(--umak-blue);"></i> Reactivate Transaction</h3>
            <button class="modal-close" onclick="closeModal('reactivateModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <p style="margin: 0 0 16px; font-size: 14.5px; color: #4b5563; line-height: 1.6;">
                Reactivate the cancelled transaction for <strong id="reactivateMemberName">—</strong>?
            </p>

            <div class="modal-info-text">
                <strong><i class="bi bi-info-circle-fill"></i> Note:</strong>
                The transaction will be reset to <strong>Pending</strong> status, and the member can pay again.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('reactivateModal')">Cancel</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="mark_pending">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="transaction_id" id="reactivateTxnId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-arrow-clockwise"></i> Reactivate
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ============================================
     DELETE MODAL
     ============================================ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-trash3-fill" style="color: var(--umak-red);"></i> Delete Transaction Record</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <p style="margin: 0 0 16px; font-size: 14.5px; color: #4b5563; line-height: 1.6;">
                Permanently delete the transaction record for <strong id="deleteMemberName">—</strong>?
            </p>

            <div class="modal-danger-text">
                <strong><i class="bi bi-exclamation-triangle-fill"></i> Warning:</strong>
                This action cannot be undone. The transaction record will be removed from the database permanently.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="delete">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="transaction_id" id="deleteTxnId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash3-fill"></i> Delete Permanently
                </button>
            </form>
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
    // MODAL HELPERS
    // ============================================
    function openModal(id) {
        document.getElementById(id).classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(m => {
                m.classList.remove('open');
            });
            document.body.style.overflow = '';
        }
    });

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('open');
                document.body.style.overflow = '';
            }
        });
    });

    // ============================================
    // MARK PAID
    // ============================================
    function markPaid(id, name, amount, description) {
        document.getElementById('paidMemberName').textContent = name;
        document.getElementById('paidDescription').textContent = description;
        document.getElementById('paidAmount').textContent = '₱' + amount;
        document.getElementById('paidTxnId').value = id;
        openModal('paidModal');
    }

    // ============================================
    // CANCEL
    // ============================================
    function cancelTxn(id, name) {
        document.getElementById('cancelMemberName').textContent = name;
        document.getElementById('cancelTxnId').value = id;
        openModal('cancelModal');
    }

    // ============================================
    // REFUND
    // ============================================
    function refundTxn(id, name, amount) {
        document.getElementById('refundMemberName').textContent = name;
        document.getElementById('refundAmount').textContent = '₱' + amount;
        document.getElementById('refundTxnId').value = id;
        openModal('refundModal');
    }

    // ============================================
    // MARK PENDING (Reactivate)
    // ============================================
    function markPending(id, name) {
        document.getElementById('reactivateMemberName').textContent = name;
        document.getElementById('reactivateTxnId').value = id;
        openModal('reactivateModal');
    }

    // ============================================
    // DELETE
    // ============================================
    function deleteTxn(id, name) {
        document.getElementById('deleteMemberName').textContent = name;
        document.getElementById('deleteTxnId').value = id;
        openModal('deleteModal');
    }

    // ============================================
    // AUTO-DISMISS ALERTS
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.alert-success').forEach(function(alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s, transform 0.5s';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function() { alert.style.display = 'none'; }, 500);
            }, 5000);
        });
    });
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>