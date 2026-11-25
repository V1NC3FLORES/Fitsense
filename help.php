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

function getTableColumns($conn, $tableName) {
    $columns = [];
    $tableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW COLUMNS FROM `$tableName`");
    if ($result) {
        while ($row = $result->fetch_assoc()) $columns[] = $row['Field'];
    }
    return $columns;
}

// ============================================
// ENSURE SUPPORT TICKETS TABLE EXISTS
// ============================================
try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS `support_tickets` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `ticket_number` VARCHAR(20) NOT NULL,
            `subject` VARCHAR(200) NOT NULL,
            `category` VARCHAR(50) NOT NULL,
            `priority` ENUM('Low','Medium','High','Urgent') DEFAULT 'Medium',
            `message` TEXT NOT NULL,
            `status` ENUM('Open','In Progress','Resolved','Closed') DEFAULT 'Open',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_ticket` (`ticket_number`),
            INDEX `idx_user` (`user_id`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log("Support tickets table creation error: " . $e->getMessage());
}

$userColumns = getTableColumns($conn, 'users');
$hasColumn = function($col) use ($userColumns) {
    return in_array($col, $userColumns);
};

// ============================================
// HANDLE SUPPORT TICKET SUBMISSION
// ============================================
$message = '';
$messageType = '';
$ticketNumber = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_message'] = 'This form has expired or is invalid. Please refresh the page and try again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: help.php');
        exit;
    }

    if ($_POST['action'] === 'submit_ticket') {
        $subject = trim($_POST['subject'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $priority = trim($_POST['priority'] ?? 'Medium');
        $msg = trim($_POST['message'] ?? '');

        $errors = [];

        if (empty($subject) || strlen($subject) < 5) {
            $errors[] = 'Subject must be at least 5 characters.';
        }
        if (empty($msg) || strlen($msg) < 20) {
            $errors[] = 'Message must be at least 20 characters.';
        }
        if (!in_array($priority, ['Low', 'Medium', 'High', 'Urgent'])) {
            $priority = 'Medium';
        }

        if (empty($errors)) {
            $ticketNumber = 'TKT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 5));

            $stmt = $conn->prepare(
                "INSERT INTO support_tickets 
                 (user_id, ticket_number, subject, category, priority, message, status, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, 'Open', NOW())"
            );
            if ($stmt) {
                $stmt->bind_param("isssss",
                    $userId, $ticketNumber, $subject, $category, $priority, $msg
                );
                if ($stmt->execute()) {
                    $message = 'Support ticket submitted successfully! Ticket #: <strong>' . htmlspecialchars($ticketNumber) . '</strong> — We\'ll get back to you within 24 hours.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to submit ticket. Please try again.';
                    $messageType = 'error';
                }
                $stmt->close();
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
    }
}

// ============================================
// FETCH USER DATA
// ============================================
$selectFields = ['id', 'username'];
foreach (['fullname', 'email', 'profile_image'] as $f) {
    if ($hasColumn($f)) $selectFields[] = $f;
}

$userData = null;
$sql = "SELECT " . implode(', ', $selectFields) . " FROM users WHERE id = ? LIMIT 1";
$stmtUser = $conn->prepare($sql);
if ($stmtUser) {
    $stmtUser->bind_param("i", $userId);
    $stmtUser->execute();
    $userData = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();
}

$displayName = $userData['fullname'] ?? $_SESSION['fullname'] ?? $userData['username'] ?? 'User';
$firstName = explode(' ', trim($displayName))[0];
$userEmail = $userData['email'] ?? $_SESSION['email'] ?? '';
$userInitial = strtoupper(substr($displayName, 0, 1));
$userAvatar = $userData['profile_image'] ?? '';

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
// FETCH USER'S SUPPORT TICKETS
// ============================================
$tickets = [];
$result = safeQuery($conn,
    "SELECT id, ticket_number, subject, category, priority, status, created_at 
     FROM support_tickets 
     WHERE user_id = ? 
     ORDER BY created_at DESC 
     LIMIT 10",
    "i", [$userId]
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $tickets[] = $row;
    }
}

// ============================================
// FAQ DATA
// ============================================
$faqs = [
    'Account & Membership' => [
        [
            'q' => 'How do I renew my membership?',
            'a' => 'Go to <strong>My Membership</strong> from the sidebar and click "Renew Membership". You can also visit <strong>Membership Plans</strong> to browse and choose a new plan. All payments are processed securely through our system.'
        ],
        [
            'q' => 'Can I upgrade or downgrade my plan?',
            'a' => 'Yes! You can upgrade anytime and the price difference will be prorated. Downgrades will take effect after your current plan expires. Visit the Membership Plans page to make changes.'
        ],
        [
            'q' => 'How do I update my personal information?',
            'a' => 'Go to <strong>My Profile</strong> in the sidebar. You can update your name, email, phone, address, emergency contacts, and bio. Changes are saved immediately.'
        ],
        [
            'q' => 'What happens if I miss a payment?',
            'a' => 'You\'ll receive a notification and email reminder. A grace period of 7 days is provided. After that, your access may be temporarily suspended until payment is completed.'
        ],
    ],
    'Attendance & Classes' => [
        [
            'q' => 'How do I check in at the gym?',
            'a' => 'Scan your RFID card at the entrance scanner. Your attendance will be automatically logged. You can view your visit history in the <strong>Attendance</strong> section.'
        ],
        [
            'q' => 'Can I cancel a class booking?',
            'a' => 'Yes! Go to <strong>Classes & Booking</strong> and click "Cancel Booking" on any booked class. We recommend canceling at least 2 hours before the class to free up slots for others.'
        ],
        [
            'q' => 'What if a class is full?',
            'a' => 'You can join the waitlist. If someone cancels, you\'ll automatically be moved up and notified via email and in-app notification.'
        ],
        [
            'q' => 'Is there a limit to how many classes I can book?',
            'a' => 'Premium members can book unlimited classes. Basic members are limited to 4 classes per month. Check your membership details for exact limits.'
        ],
    ],
    'Payments & Billing' => [
        [
            'q' => 'What payment methods do you accept?',
            'a' => 'We accept <strong>GCash</strong> and <strong>Maya</strong> e-wallet payments online, processed securely through <strong>Xendit</strong>. Cash payments can be made at the UMak Cash Office (Ground Floor, Administration Building).'
        ],
        [
            'q' => 'How do I view my payment history?',
            'a' => 'Go to <strong>Payments & Billing</strong>. All your transactions are listed with invoice numbers, amounts, dates, and payment methods. You can download receipts for any paid transaction.'
        ],
        [
            'q' => 'Are refunds available?',
            'a' => 'Refunds are handled on a case-by-case basis. Please contact the gym office directly or submit a support ticket with your reason. Refunds are typically processed within 5-7 business days.'
        ],
        [
            'q' => 'I was charged twice — what should I do?',
            'a' => 'Please submit an <strong>Urgent</strong> priority support ticket with your invoice number. We\'ll investigate and process a refund immediately if confirmed. You can also contact the gym office directly.'
        ],
    ],
    'Technical Support' => [
        [
            'q' => 'I forgot my password. How do I reset it?',
            'a' => 'On the login page, click "Forgot password?" and follow the email reset instructions. You can also change your password anytime from <strong>Settings → Security</strong>.'
        ],
        [
            'q' => 'The website is not loading properly.',
            'a' => 'Try clearing your browser cache, using a different browser, or switching networks. If the issue persists, please submit a support ticket with a screenshot and your device/browser information.'
        ],
        [
            'q' => 'My RFID card is not working.',
            'a' => 'Visit the gym reception desk to have your card checked. If it\'s damaged or lost, a replacement can be issued for a small fee. Report it immediately in case of loss.'
        ],
        [
            'q' => 'Can I access FitSense from my phone?',
            'a' => 'Yes! FitSense is fully responsive and works on any modern mobile browser. We recommend bookmarking the site for quick access.'
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help & Support - UMak Gym: Fitsense</title>
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
           SIDEBAR
           ============================================ */
        .sidebar {
            position: fixed; left: 0; top: 0;
            width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, #005b9f 0%, #00467a 100%);
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
        .main-wrapper { margin-left: var(--sidebar-width); min-height: 100vh; padding-bottom: 40px; }

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
            font-weight: bold; font-size: 16px; overflow: hidden;
        }

        .avatar img { width: 100%; height: 100%; object-fit: cover; }

        /* ============================================
           PAGE CONTENT
           ============================================ */
        .container { max-width: 1200px; margin: 30px auto; padding: 0 25px; }

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
           HELP HERO
           ============================================ */
        .help-hero {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 24px;
            padding: 50px 40px;
            color: white;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0,91,159,0.25);
            text-align: center;
        }

        .help-hero::before {
            content: '';
            position: absolute;
            top: -100px; right: -100px;
            width: 350px; height: 350px;
            background: radial-gradient(circle, rgba(255,206,0,0.18) 0%, transparent 70%);
            border-radius: 50%;
        }

        .help-hero::after {
            content: '';
            position: absolute;
            bottom: -80px; left: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(74,222,128,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .help-hero-content { position: relative; z-index: 2; }

        .help-hero-icon {
            width: 90px;
            height: 90px;
            margin: 0 auto 22px;
            border-radius: 24px;
            background: rgba(255,206,0,0.18);
            border: 2px solid rgba(255,206,0,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            color: var(--umak-yellow);
        }

        .help-hero h1 {
            margin: 0 0 12px;
            font-size: 38px;
            font-weight: 800;
            letter-spacing: -0.8px;
        }

        .help-hero p {
            margin: 0 auto;
            font-size: 16px;
            color: rgba(255,255,255,0.85);
            max-width: 600px;
            line-height: 1.6;
        }

        /* Search Bar */
        .help-search {
            position: relative;
            z-index: 2;
            max-width: 600px;
            margin: 28px auto 0;
            position: relative;
        }

        .help-search input {
            width: 100%;
            padding: 16px 24px 16px 56px;
            border-radius: 14px;
            border: none;
            font-size: 15px;
            font-family: inherit;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            color: white;
            outline: none;
            transition: all 0.3s;
            border: 2px solid rgba(255,255,255,0.2);
        }

        .help-search input::placeholder { color: rgba(255,255,255,0.65); }

        .help-search input:focus {
            background: rgba(255,255,255,0.2);
            border-color: var(--umak-yellow);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        }

        .help-search i {
            position: absolute;
            left: 22px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,0.75);
            font-size: 18px;
        }

        /* ============================================
           QUICK CONTACT CARDS
           ============================================ */
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
            margin-bottom: 30px;
        }

        .contact-card {
            background: white;
            border-radius: 18px;
            padding: 26px 24px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            text-align: center;
            transition: all 0.25s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }

        .contact-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.08);
            border-color: rgba(0,91,159,0.2);
        }

        .contact-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: white;
        }

        .contact-icon.blue { background: linear-gradient(135deg, #005b9f, #0084d1); }
        .contact-icon.green { background: linear-gradient(135deg, #2e7d32, #4ade80); }
        .contact-icon.purple { background: linear-gradient(135deg, #8b5cf6, #a78bfa); }
        .contact-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }

        .contact-card h3 {
            margin: 0 0 6px;
            font-size: 16px;
            font-weight: 800;
            color: #111827;
        }

        .contact-card p {
            margin: 0;
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* ============================================
           MAIN LAYOUT
           ============================================ */
        .help-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            align-items: start;
        }

        .panel {
            background: white;
            border-radius: 20px;
            padding: 30px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 22px;
            padding-bottom: 18px;
            border-bottom: 2px solid var(--border);
            gap: 12px;
            flex-wrap: wrap;
        }

        .panel-header h3 {
            margin: 0 0 4px;
            font-size: 18px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-header p {
            margin: 0;
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* ============================================
           FAQ ACCORDION
           ============================================ */
        .faq-category {
            margin-bottom: 24px;
        }

        .faq-category:last-child { margin-bottom: 0; }

        .faq-category-title {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding: 0 4px;
        }

        .faq-category-title i { font-size: 14px; }

        .faq-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .faq-item {
            background: #f9fafb;
            border: 1.5px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            transition: all 0.25s ease;
        }

        .faq-item:hover {
            border-color: rgba(0,91,159,0.2);
            background: #ffffff;
        }

        .faq-item.open {
            background: #ffffff;
            border-color: var(--umak-blue);
            box-shadow: 0 8px 20px rgba(0,91,159,0.08);
        }

        .faq-question {
            padding: 16px 20px;
            font-size: 14.5px;
            font-weight: 700;
            color: #111827;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            transition: color 0.2s;
            user-select: none;
        }

        .faq-question:hover { color: var(--umak-blue); }

        .faq-question .faq-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e5e7eb;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }

        .faq-item.open .faq-question {
            color: var(--umak-blue);
        }

        .faq-item.open .faq-question .faq-icon {
            background: var(--umak-blue);
            color: white;
            transform: rotate(180deg);
        }

        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.35s ease;
            padding: 0 20px;
        }

        .faq-item.open .faq-answer {
            max-height: 500px;
            padding: 0 20px 18px;
        }

        .faq-answer-inner {
            font-size: 13.5px;
            line-height: 1.7;
            color: #4b5563;
            padding-top: 4px;
            border-top: 1px dashed var(--border);
            padding-top: 14px;
        }

        /* ============================================
           SUPPORT FORM
           ============================================ */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
        }

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

        .form-label .required { color: var(--umak-red); }

        .form-control {
            padding: 12px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            font-family: inherit;
            width: 100%;
            color: var(--text);
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--umak-blue);
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 120px;
            line-height: 1.6;
        }

        select.form-control { cursor: pointer; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .btn {
            padding: 13px 26px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s ease;
            text-decoration: none;
            font-family: inherit;
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

        .btn i { font-size: 15px; }

        /* ============================================
           TICKETS LIST
           ============================================ */
        .ticket-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .ticket-item {
            padding: 14px 16px;
            background: #f9fafb;
            border-radius: 12px;
            border-left: 4px solid var(--umak-blue);
            transition: all 0.2s;
        }

        .ticket-item:hover {
            background: #f3f4f6;
            transform: translateX(3px);
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }

        .ticket-number {
            font-family: 'Courier New', monospace;
            font-size: 11.5px;
            font-weight: 800;
            color: var(--umak-blue);
            background: rgba(0,91,159,0.08);
            padding: 3px 8px;
            border-radius: 6px;
        }

        .ticket-status {
            font-size: 10.5px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .ticket-status.open { background: #dbeafe; color: #1e40af; }
        .ticket-status.progress { background: #fef3c7; color: #92400e; }
        .ticket-status.resolved { background: #dcfce7; color: #166534; }
        .ticket-status.closed { background: #f3f4f6; color: #6b7280; }

        .ticket-subject {
            font-size: 13.5px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
        }

        .ticket-meta {
            font-size: 11.5px;
            color: var(--text-muted);
            font-weight: 600;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* ============================================
           CONTACT INFO PANEL
           ============================================ */
        .contact-info-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .contact-info-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 14px;
            border-radius: 12px;
            background: #f9fafb;
            border: 1px solid var(--border);
        }

        .contact-info-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(0,91,159,0.12), rgba(0,132,209,0.18));
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .contact-info-content { flex: 1; min-width: 0; }

        .contact-info-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: 4px;
        }

        .contact-info-value {
            font-size: 13.5px;
            font-weight: 700;
            color: #1f2937;
            line-height: 1.5;
            word-break: break-word;
        }

        .contact-info-value a {
            color: var(--umak-blue);
            text-decoration: none;
        }

        .contact-info-value a:hover { text-decoration: underline; }

        /* ============================================
           EMPTY STATE
           ============================================ */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 16px;
            background: rgba(0,91,159,0.08);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            color: var(--umak-blue);
        }

        .empty-state h4 {
            margin: 0 0 6px;
            color: var(--umak-blue);
            font-size: 15px;
            font-weight: 800;
        }

        .empty-state p {
            color: var(--text-muted);
            font-size: 13px;
            margin: 0;
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
            .help-layout { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .btn-chat-open span.label { display: none; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 22px; }
            .help-hero { padding: 32px 22px; }
            .help-hero h1 { font-size: 26px; }
            .help-hero-icon { width: 70px; height: 70px; font-size: 32px; }
            .panel { padding: 22px 18px; }
            .form-row { grid-template-columns: 1fr; }
            .contact-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 576px) {
            .help-hero h1 { font-size: 22px; }
            .help-hero p { font-size: 14px; }
            .btn { width: 100%; }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css"><link rel="stylesheet" href="/PBL/css/user-content-icons.css"></head>
<body>

<!-- ============================================
     SIDEBAR
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
        <a href="user-dashboard.php" class="nav-item"><i class="bi bi-house-door-fill"></i><span>Dashboard</span></a>
        <a href="my-membership.php" class="nav-item"><i class="bi bi-ticket-perforated-fill"></i><span>My Membership</span></a>
        <a href="attendance.php" class="nav-item"><i class="bi bi-bar-chart-fill"></i><span>Attendance</span></a>

        <div class="nav-section-title">Fitness</div>
        <a href="workout-plan.php" class="nav-item"><i class="bi bi-heart-pulse-fill"></i><span>Workout Plan</span></a>
        <a href="nutrition-plan.php" class="nav-item"><i class="bi bi-egg-fried"></i><span>Nutrition Plan</span></a>
        <a href="my-progress.php" class="nav-item"><i class="bi bi-graph-up-arrow"></i><span>My Progress</span></a>

        <div class="nav-section-title">Services</div>
        <a href="classes.php" class="nav-item"><i class="bi bi-calendar-event-fill"></i><span>Classes & Booking</span></a>
        <a href="payments.php" class="nav-item"><i class="bi bi-credit-card-fill"></i><span>Payments & Billing</span></a>
        <a href="notifications.php" class="nav-item">
            <i class="bi bi-bell-fill"></i><span>Notifications</span>
            <?php if ($unreadNotifCount > 0): ?>
                <span class="badge-count"><?= $unreadNotifCount ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section-title">Account</div>
        <a href="my-profile.php" class="nav-item"><i class="bi bi-person-circle"></i><span>My Profile</span></a>
        <a href="help.php" class="nav-item active"><i class="bi bi-life-preserver"></i><span>Help & Support</span></a>
        <a href="settings.php" class="nav-item"><i class="bi bi-gear-fill"></i><span>Settings</span></a>
        <div class="nav-divider"></div>
        <a href="logout.php" class="nav-item nav-logout"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a>
    </nav>
</aside>

<!-- ============================================
     MAIN WRAPPER
     ============================================ -->
<div class="main-wrapper">

    <header class="top-header">
        <div class="header-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
            <div>
                <h2>Help & Support</h2>
                <p class="subtitle">We're here to help you</p>
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
                    <?php if (!empty($userAvatar)): ?>
                        <img src="<?= htmlspecialchars($userAvatar) ?>" alt="">
                    <?php else: ?>
                        <?= htmlspecialchars($userInitial) ?>
                    <?php endif; ?>
                </div>
            </button>
        </div>
    </header>

    <main class="container">

        <!-- ============================================
             HELP HERO WITH SEARCH
             ============================================ -->
        <div class="help-hero">
            <div class="help-hero-content">
                <div class="help-hero-icon">
                    <i class="bi bi-life-preserver"></i>
                </div>
                <h1>How can we help you?</h1>
                <p>Find answers to common questions, browse our FAQs, or submit a support ticket. We respond within 24 hours.</p>

                <div class="help-search">
                    <i class="bi bi-search"></i>
                    <input type="text" id="faqSearch" placeholder="Search for help..." autocomplete="off">
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>" style="font-size: 20px;"></i>
                <div><?= $message ?></div>
            </div>
        <?php endif; ?>

        <!-- ============================================
             QUICK CONTACT CARDS
             ============================================ -->
        <div class="contact-grid">
            <a href="#contact-form" class="contact-card">
                <div class="contact-icon blue">
                    <i class="bi bi-chat-dots-fill"></i>
                </div>
                <h3>Submit a Ticket</h3>
                <p>Get help from our support team</p>
            </a>

            <a href="#faqs" class="contact-card">
                <div class="contact-icon green">
                    <i class="bi bi-book-fill"></i>
                </div>
                <h3>Browse FAQs</h3>
                <p>Find quick answers to common questions</p>
            </a>

            <a href="tel:+6328883888" class="contact-card">
                <div class="contact-icon purple">
                    <i class="bi bi-telephone-fill"></i>
                </div>
                <h3>Call Us</h3>
                <p>(02) 8883-8888 · Mon-Fri 8AM-5PM</p>
            </a>

            <a href="mailto:support@umak.edu.ph" class="contact-card">
                <div class="contact-icon orange">
                    <i class="bi bi-envelope-fill"></i>
                </div>
                <h3>Email Us</h3>
                <p>support@umak.edu.ph</p>
            </a>
        </div>

        <!-- ============================================
             MAIN LAYOUT
             ============================================ -->
        <div class="help-layout">

            <!-- LEFT: FAQ -->
            <div>
                <div class="panel" id="faqs">
                    <div class="panel-header">
                        <div>
                            <h3><i class="bi bi-question-circle-fill"></i> Frequently Asked Questions</h3>
                            <p>Click on any question to reveal the answer.</p>
                        </div>
                    </div>

                    <?php foreach ($faqs as $category => $items): ?>
                        <div class="faq-category" data-category="<?= htmlspecialchars(strtolower($category)) ?>">
                            <div class="faq-category-title">
                                <i class="bi bi-bookmark-fill"></i>
                                <?= htmlspecialchars($category) ?>
                            </div>
                            <div class="faq-list">
                                <?php foreach ($items as $idx => $faq): ?>
                                    <div class="faq-item" data-search="<?= htmlspecialchars(strtolower($faq['q'] . ' ' . strip_tags($faq['a']))) ?>">
                                        <div class="faq-question" onclick="toggleFaq(this)">
                                            <span><?= htmlspecialchars($faq['q']) ?></span>
                                            <span class="faq-icon">
                                                <i class="bi bi-chevron-down"></i>
                                            </span>
                                        </div>
                                        <div class="faq-answer">
                                            <div class="faq-answer-inner">
                                                <?= $faq['a'] ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- RIGHT: Contact Form + Info + Tickets -->
            <div>
                <!-- Contact Form -->
                <div class="panel" id="contact-form">
                    <div class="panel-header">
                        <div>
                            <h3><i class="bi bi-send-fill"></i> Contact Support</h3>
                            <p>Submit a ticket and we'll get back to you.</p>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="submit_ticket">
                        <?php echo app_csrf_field(); ?>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-tag-fill"></i>
                                Subject <span class="required">*</span>
                            </label>
                            <input type="text" name="subject" class="form-control"
                                   placeholder="Brief summary of your issue" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-folder-fill"></i>
                                    Category
                                </label>
                                <select name="category" class="form-control">
                                    <option>General</option>
                                    <option>Account</option>
                                    <option>Membership</option>
                                    <option>Payments</option>
                                    <option>Classes</option>
                                    <option>Technical</option>
                                    <option>Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="bi bi-exclamation-circle-fill"></i>
                                    Priority
                                </label>
                                <select name="priority" class="form-control">
                                    <option value="Low">Low</option>
                                    <option value="Medium" selected>Medium</option>
                                    <option value="High">High</option>
                                    <option value="Urgent">Urgent</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-chat-left-text-fill"></i>
                                Message <span class="required">*</span>
                            </label>
                            <textarea name="message" class="form-control"
                                      placeholder="Describe your issue in detail. Include any relevant account info or screenshots..." required></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class="bi bi-send-fill"></i> Submit Ticket
                        </button>
                    </form>
                </div>

                <!-- My Tickets -->
                <?php if (!empty($tickets)): ?>
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3><i class="bi bi-ticket-detailed-fill"></i> My Tickets</h3>
                            <p>Recent support requests.</p>
                        </div>
                    </div>
                    <div class="ticket-list">
                        <?php foreach ($tickets as $t):
                            $statusClass = 'open';
                            if ($t['status'] === 'In Progress') $statusClass = 'progress';
                            elseif ($t['status'] === 'Resolved') $statusClass = 'resolved';
                            elseif ($t['status'] === 'Closed') $statusClass = 'closed';
                        ?>
                            <div class="ticket-item">
                                <div class="ticket-header">
                                    <span class="ticket-number"><?= htmlspecialchars($t['ticket_number']) ?></span>
                                    <span class="ticket-status <?= $statusClass ?>">
                                        <?= htmlspecialchars($t['status']) ?>
                                    </span>
                                </div>
                                <div class="ticket-subject"><?= htmlspecialchars($t['subject']) ?></div>
                                <div class="ticket-meta">
                                    <span><i class="bi bi-folder"></i> <?= htmlspecialchars($t['category']) ?></span>
                                    <span><i class="bi bi-clock"></i> <?= date('M j, g:i A', strtotime($t['created_at'])) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Contact Info -->
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3><i class="bi bi-info-circle-fill"></i> Gym Contact</h3>
                        </div>
                    </div>

                    <div class="contact-info-list">
                        <div class="contact-info-item">
                            <div class="contact-info-icon">
                                <i class="bi bi-geo-alt-fill"></i>
                            </div>
                            <div class="contact-info-content">
                                <div class="contact-info-label">Location</div>
                                <div class="contact-info-value">
                                    11th Floor, Health & Physical Science Building<br>
                                    University of Makati
                                </div>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <div class="contact-info-icon">
                                <i class="bi bi-clock-fill"></i>
                            </div>
                            <div class="contact-info-content">
                                <div class="contact-info-label">Operating Hours</div>
                                <div class="contact-info-value">
                                    Mon–Fri: 6:00 AM – 9:00 PM<br>
                                    Saturday: 7:00 AM – 6:00 PM<br>
                                    Sunday: Closed
                                </div>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <div class="contact-info-icon">
                                <i class="bi bi-telephone-fill"></i>
                            </div>
                            <div class="contact-info-content">
                                <div class="contact-info-label">Phone</div>
                                <div class="contact-info-value">
                                    <a href="tel:+6328883888">(02) 8883-8888</a>
                                </div>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <div class="contact-info-icon">
                                <i class="bi bi-envelope-fill"></i>
                            </div>
                            <div class="contact-info-content">
                                <div class="contact-info-label">Email</div>
                                <div class="contact-info-value">
                                    <a href="mailto:support@umak.edu.ph">support@umak.edu.ph</a>
                                </div>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <div class="contact-info-icon">
                                <i class="bi bi-facebook"></i>
                            </div>
                            <div class="contact-info-content">
                                <div class="contact-info-label">Social</div>
                                <div class="contact-info-value">
                                    <a href="#">facebook.com/umakgym</a>
                                </div>
                            </div>
                        </div>
                    </div>
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

        // Close others in the same category
        const category = item.closest('.faq-category');
        if (category) {
            category.querySelectorAll('.faq-item').forEach(function(i) {
                i.classList.remove('open');
            });
        }

        // Toggle current
        if (!isOpen) item.classList.add('open');
    }

    // ============================================
    // FAQ SEARCH
    // ============================================
    document.getElementById('faqSearch').addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();

        document.querySelectorAll('.faq-category').forEach(function(category) {
            let visibleCount = 0;

            category.querySelectorAll('.faq-item').forEach(function(item) {
                const searchText = item.dataset.search || '';
                const question = item.querySelector('.faq-question span')?.textContent.toLowerCase() || '';

                if (!query || searchText.includes(query) || question.includes(query)) {
                    item.style.display = '';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            category.style.display = visibleCount > 0 ? '' : 'none';
        });
    });

    // ============================================
    // AUTO-OPEN FIRST FAQ
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const firstFaq = document.querySelector('.faq-item');
        if (firstFaq) firstFaq.classList.add('open');

        // Auto-dismiss alerts
        document.querySelectorAll('.alert-success').forEach(function(alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() { alert.style.display = 'none'; }, 500);
            }, 5000);
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
            anchor.addEventListener('click', function(e) {
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    });
</script>
<?php include 'chat_widget.php'; ?>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>