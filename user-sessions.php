<?php
// user-sessions.php
session_start();
include 'conn.php';
require_once __DIR__ . '/config/session_security.php';

$userSession = app_require_session_user($conn, 'user', 'login.php');
$userId = (int)$_SESSION['user_id'];

$stmtUser = $conn->prepare("SELECT fullname, username, profile_image, branch_id FROM users WHERE id = ? LIMIT 1");
$stmtUser->bind_param("i", $userId);
$stmtUser->execute();
$userData = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$displayName = $userData['fullname'] ?? $_SESSION['fullname'] ?? 'User';
$username = $userData['username'] ?? $_SESSION['username'] ?? 'user';
$profileImage = $userData['profile_image'] ?? '';
$branchId = (int)($userData['branch_id'] ?? $_SESSION['branch_id'] ?? 0);

$_SESSION['fullname'] = $displayName;
$_SESSION['username'] = $username;
$_SESSION['branch_id'] = $branchId;

$userInitial = strtoupper(substr($displayName, 0, 1));

$message = "";
$messageType = "success";

$unreadNotifCount = 0;
$stmtUnread = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0");
$stmtUnread->bind_param("i", $userId);
$stmtUnread->execute();
$resultUnread = $stmtUnread->get_result();

if ($resultUnread && $resultUnread->num_rows > 0) {
    $unreadNotifCount = (int)$resultUnread->fetch_assoc()['total'];
}
$stmtUnread->close();

function notifyBranchAdmins($conn, $branchId, $type, $title, $message) {
    $adminQuery = $conn->prepare("SELECT id FROM users WHERE role = 'admin' AND branch_id = ?");
    $adminQuery->bind_param("i", $branchId);
    $adminQuery->execute();
    $adminResult = $adminQuery->get_result();

    while ($admin = $adminResult->fetch_assoc()) {
        $adminId = (int)$admin['id'];

        $adminNotifStmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, is_read)
            VALUES (?, ?, ?, ?, 0)
        ");
        $adminNotifStmt->bind_param("isss", $adminId, $type, $title, $message);
        $adminNotifStmt->execute();
        $adminNotifStmt->close();
    }

    $adminQuery->close();
}

$sessionOffers = [];
$stmtOffers = $conn->prepare("
    SELECT session_type, price 
    FROM session_offers 
    WHERE branch_id = ? AND is_active = 1 
    ORDER BY session_type ASC
");
$stmtOffers->bind_param("i", $branchId);
$stmtOffers->execute();
$resultOffers = $stmtOffers->get_result();

while ($row = $resultOffers->fetch_assoc()) {
    $sessionOffers[$row['session_type']] = (float)$row['price'];
}
$stmtOffers->close();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_message'] = 'This form has expired or is invalid. Please refresh the page and try again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: user-sessions.php');
        exit;
    }

    if (isset($_POST['cancel_session_id'])) {
        $sessionId = (int)$_POST['cancel_session_id'];

        $stmtCheck = $conn->prepare("SELECT * FROM sessions WHERE id = ? AND user_id = ?");
        $stmtCheck->bind_param("ii", $sessionId, $userId);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();

        if ($resultCheck && $resultCheck->num_rows > 0) {
            $session = $resultCheck->fetch_assoc();

            if ($session['status'] === 'Pending') {
                $stmtCancel = $conn->prepare("UPDATE sessions SET status = 'Cancelled' WHERE id = ? AND user_id = ?");
                $stmtCancel->bind_param("ii", $sessionId, $userId);

                if ($stmtCancel->execute()) {
                    $description = $session['session_type'] . " Session Booking";

                    $stmtTrans = $conn->prepare("
                        UPDATE transactions
                        SET status = 'Cancelled'
                        WHERE user_id = ?
                        AND transaction_type = 'Session'
                        AND description = ?
                        AND status = 'Pending'
                    ");
                    $stmtTrans->bind_param("is", $userId, $description);
                    $stmtTrans->execute();
                    $stmtTrans->close();

                    $notifTitle = "Session Request Cancelled";
                    $notifMessage = "Your pending session request has been cancelled successfully.";

                    $notifStmt = $conn->prepare("
                        INSERT INTO notifications (user_id, type, title, message, is_read)
                        VALUES (?, 'session', ?, ?, 0)
                    ");
                    $notifStmt->bind_param("iss", $userId, $notifTitle, $notifMessage);
                    $notifStmt->execute();
                    $notifStmt->close();

                    notifyBranchAdmins(
                        $conn,
                        $branchId,
                        'session',
                        'Session Request Cancelled',
                        $displayName . " cancelled a pending " . $session['session_type'] . " session request."
                    );

                    $message = "Session request cancelled successfully.";
                    $messageType = "success";
                } else {
                    $message = "Unable to cancel session request.";
                    $messageType = "error";
                }

                $stmtCancel->close();
            } else {
                $message = "Only pending session requests can be cancelled.";
                $messageType = "error";
            }
        } else {
            $message = "Session request not found.";
            $messageType = "error";
        }

        $stmtCheck->close();
    }

    if (isset($_POST['session_type']) && !isset($_POST['cancel_session_id'])) {
        $sessionType = trim($_POST['session_type']);
        $sessionDate = $_POST['session_date'];
        $sessionTime = $_POST['session_time'];
        $status = "Pending";

        $today = date("Y-m-d");
        $currentTime = date("H:i");

        if ($sessionDate < $today) {
            $message = "You cannot book a session for a past date.";
            $messageType = "error";
        } elseif ($sessionDate === $today && $sessionTime <= $currentTime) {
            $message = "You cannot book a session for a past time today.";
            $messageType = "error";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO sessions (user_id, session_type, session_date, session_time, status, branch_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issssi", $userId, $sessionType, $sessionDate, $sessionTime, $status, $branchId);

            if ($stmt->execute()) {
                $referenceNo = "SES-" . time();
                $description = $sessionType . " Session Booking";
                if (!isset($sessionOffers[$sessionType])) {
                     $message = "Invalid or unavailable session type selected.";
                     $messageType = "error";
                     $stmt->close();
                } else{
                    $amount = $sessionOffers[$sessionType];
                }

                $transStmt = $conn->prepare("
                    INSERT INTO transactions (user_id, reference_no, transaction_type, description, amount, status)
                    VALUES (?, ?, 'Session', ?, ?, 'Pending')
                ");
                $transStmt->bind_param("issd", $userId, $referenceNo, $description, $amount);
                $transStmt->execute();
                $transStmt->close();

                $notifTitle = "Session Request Submitted";
                $notifMessage = "Your session request has been submitted and is now pending admin approval.";

                $notifStmt = $conn->prepare("
                    INSERT INTO notifications (user_id, type, title, message, is_read)
                    VALUES (?, 'session', ?, ?, 0)
                ");
                $notifStmt->bind_param("iss", $userId, $notifTitle, $notifMessage);
                $notifStmt->execute();
                $notifStmt->close();

                notifyBranchAdmins(
                    $conn,
                    $branchId,
                    'session',
                    'New Session Application',
                    $displayName . " submitted a " . $sessionType . " session request."
                );

                $message = "Session request submitted. Please wait for admin approval.";
                $messageType = "success";
            } else {
                $message = "Error booking session.";
                $messageType = "error";
            }

            $stmt->close();
        }
    }
}

$sessionList = [];
$stmtList = $conn->prepare("SELECT * FROM sessions WHERE user_id = ? ORDER BY session_date DESC, session_time DESC");
$stmtList->bind_param("i", $userId);
$stmtList->execute();
$resultList = $stmtList->get_result();

while ($row = $resultList->fetch_assoc()) {
    $sessionList[] = $row;
}
$stmtList->close();

function sessionStatusClass($status) {
    if ($status === 'Booked') return 'status-booked';
    if ($status === 'Pending') return 'status-pending';
    if (in_array($status, ['Cancelled', 'Completed', 'Rejected'])) return 'status-cancelled';
    return 'status-default';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Sessions - KSYN Gym: FitConnect</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        * { box-sizing: border-box; }

        html, body {
            min-height: 100%;
            margin: 0;
            overflow-x: hidden;
        }

        body {
            font-family: Arial, sans-serif;
            background:
                linear-gradient(rgba(0,0,0,0.82), rgba(0,0,0,0.9)),
                url('https://images.unsplash.com/photo-1534438327276-14e5300c3a48?auto=format&fit=crop&w=1600&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: #ffffff;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background:
                radial-gradient(circle at 15% 20%, rgba(255,122,0,0.22), transparent 30%),
                radial-gradient(circle at 85% 80%, rgba(255,60,0,0.16), transparent 35%);
            z-index: -1;
            animation: bgGlow 7s ease-in-out infinite alternate;
        }

        @keyframes bgGlow {
            from { opacity: .65; transform: scale(1); }
            to { opacity: 1; transform: scale(1.04); }
        }

        .page-wrapper {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .user-nav {
            background: rgba(15,15,15,0.92);
            backdrop-filter: blur(12px);
            padding: 15px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,122,0,0.18);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .user-nav-left {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 18px;
            position: relative;
        }

        .brand-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .brand-logo {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid rgba(255,122,0,0.35);
        }

        .brand-text-main {
            font-size: 22px;
            font-weight: 800;
            color: #ff7a00;
            line-height: 1;
        }

        .brand-text-sub {
            font-size: 10px;
            color: #aaaaaa;
            letter-spacing: 1.5px;
            margin-top: 5px;
            text-transform: uppercase;
        }

        .user-nav-menu {
            display: flex;
            gap: 25px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .user-nav-menu a {
            color: #aaaaaa;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: 0.3s ease;
        }

        .user-nav-menu a:hover,
        .user-nav-menu a.active {
            color: #ff7a00;
        }

        .notif-link {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .notif-badge {
            background: #ff3c00;
            color: #ffffff;
            font-size: 10px;
            font-weight: 700;
            padding: 0 6px;
            border-radius: 999px;
            min-width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .user-nav-right {
            display: flex;
            align-items: center;
            position: relative;
        }

        .profile-trigger {
            border: none;
            background: transparent;
            padding: 0;
            cursor: pointer;
            position: relative;
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(90deg, #ff7a00, #ff3c00);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            overflow: hidden;
            flex-shrink: 0;
            border: 2px solid rgba(255,122,0,0.45);
        }

        .user-avatar img,
        .dropdown-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-caret {
            position: absolute;
            right: -3px;
            bottom: -3px;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            background: #2d2d2d;
            border: 1px solid rgba(255,255,255,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 10px;
        }

        .profile-dropdown {
            position: absolute;
            top: 58px;
            right: 0;
            width: 310px;
            background: rgba(25,25,25,0.98);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            box-shadow: 0 18px 50px rgba(0,0,0,0.55);
            padding: 14px;
            display: none;
            z-index: 999;
        }

        .profile-dropdown.show { display: block; }

        .dropdown-profile-card {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,122,0,0.35);
            border-radius: 14px;
            padding: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .dropdown-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(90deg, #ff7a00, #ff3c00);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            font-weight: 800;
        }

        .dropdown-profile-card h4 {
            margin: 0 0 3px;
            font-size: 15px;
        }

        .dropdown-profile-card p {
            margin: 0;
            font-size: 12px;
            color: #aaaaaa;
        }

        .dropdown-item {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 12px;
            border-radius: 12px;
            color: #dddddd;
            text-decoration: none;
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            text-align: left;
        }

        .dropdown-item:hover {
            background: rgba(255,255,255,0.07);
            color: #ff7a00;
        }

        .dropdown-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dropdown-divider {
            height: 1px;
            background: rgba(255,255,255,0.08);
            margin: 8px 0;
        }

        .page-container {
            flex: 1;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            padding: 40px;
        }

        .glass-card {
            background: rgba(20,20,20,0.88);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 18px;
            box-shadow: 0 18px 50px rgba(0,0,0,0.35);
        }

        .page-header,
        .content-card {
            padding: 30px;
            margin-bottom: 25px;
        }

        .page-header h1,
        .content-card h3 {
            margin-top: 0;
        }

        .page-header p {
            color: #aaaaaa;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 800;
            color: #bbbbbb;
            text-transform: uppercase;
            letter-spacing: .6px;
        }

        select,
        input,
        button {
            width: 100%;
            padding: 13px;
            border-radius: 10px;
            border: 1px solid #333333;
            background: rgba(15,15,15,0.95);
            color: #ffffff;
            outline: none;
        }

        input:focus,
        select:focus {
            border-color: #ff7a00;
            box-shadow: 0 0 0 3px rgba(255,122,0,0.12);
        }

        .submit-btn,
        .cancel-btn {
            border: none;
            font-weight: 900;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: .7px;
        }

        .submit-btn {
            background: linear-gradient(90deg, #ff7a00, #ff3c00);
        }

        .cancel-btn {
            background: rgba(255,255,255,0.08);
            margin-top: 10px;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .message {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .message.success {
            background: rgba(76,175,80,0.15);
            color: #7ee787;
            border: 1px solid rgba(76,175,80,0.35);
        }

        .message.error {
            background: rgba(244,67,54,0.15);
            color: #ff9b9b;
            border: 1px solid rgba(244,67,54,0.35);
        }

        .note-box {
            background: rgba(255,152,0,0.12);
            border: 1px solid rgba(255,152,0,0.25);
            color: #ffb74d;
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 13px;
            line-height: 1.6;
        }

        .record-card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 15px;
        }

        .record-card p {
            margin: 8px 0;
            color: #dddddd;
            font-size: 14px;
        }

        .status-pill {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .status-pending {
            background: rgba(255,152,0,0.15);
            color: #ffb74d;
        }

        .status-booked {
            background: rgba(76,175,80,0.15);
            color: #7ee787;
        }

        .status-cancelled {
            background: rgba(244,67,54,0.15);
            color: #ff9b9b;
        }

        .status-default {
            background: rgba(255,255,255,0.08);
            color: #cccccc;
        }

        .main-footer {
            background: rgba(10,10,10,0.94);
            border-top: 1px solid rgba(255,122,0,0.18);
            color: #aaaaaa;
            margin-top: auto;
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            padding: 40px 20px;
        }

        .footer-section h4 {
            margin: 0 0 12px;
            font-size: 15px;
            color: #ffffff;
        }

        .footer-section p,
        .footer-section a {
            font-size: 12px;
            color: #aaaaaa;
            text-decoration: none;
            display: block;
            margin-bottom: 8px;
            line-height: 1.7;
        }

        .footer-section a:hover { color: #ff7a00; }

        .footer-bottom {
            text-align: center;
            padding: 15px;
            border-top: 1px solid rgba(255,255,255,0.08);
            font-size: 12px;
            color: #888888;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 999;
            inset: 0;
            background: rgba(0,0,0,0.72);
            justify-content: center;
            align-items: center;
            padding: 16px;
        }

        .modal-content {
            background: #111111;
            padding: 25px;
            border-radius: 14px;
            width: 320px;
            text-align: center;
            border: 1px solid rgba(255,122,0,0.25);
        }

        .modal-content p { color: #aaaaaa; }

        .modal-actions {
            display: flex;
            gap: 10px;
        }

        .btn-cancel-modal,
        .btn-confirm {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            color: #ffffff;
        }

        .btn-cancel-modal { background: #333333; }
        .btn-confirm { background: linear-gradient(90deg, #ff7a00, #ff3c00); }

        @media (max-width: 768px) {
            body { background-attachment: scroll; }

            .user-nav {
                flex-direction: column;
                align-items: center;
                gap: 14px;
                padding: 16px 14px;
            }

            .user-nav-left {
                width: 100%;
                flex-direction: column;
                align-items: center;
                gap: 14px;
            }

            .brand-row {
                width: 100%;
                justify-content: center;
                gap: 12px;
            }

            .brand-wrap {
                justify-content: center;
                text-align: center;
            }

            .brand-text-main { font-size: 17px; }
            .brand-text-sub { font-size: 9px; }

            .user-nav-menu {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .user-nav-menu a {
                text-align: center;
                font-size: 11px;
                padding: 9px 8px;
                background: rgba(255,255,255,0.06);
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 999px;
            }

            .notif-link { justify-content: center; }

            .page-container {
                padding: 24px 14px;
            }

            .page-header,
            .content-card {
                padding: 22px 18px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .footer-container {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .modal-actions {
                flex-direction: column;
            }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css"><link rel="stylesheet" href="/PBL/css/user-content-icons.css"></head>

<body>
<div class="page-wrapper">

    <div class="user-nav">
        <div class="user-nav-left">
            <div class="brand-row">
                <a href="user-dashboard.php" class="brand-wrap">
                    <img src="uploads/umak-gym-logo.png" class="brand-logo" alt="UMak Gym Logo">
                    <div>
                        <div class="brand-text-main">KSYN Gym: FitConnect</div>
                        <div class="brand-text-sub">Gym Franchise System</div>
                    </div>
                </a>

                <div class="user-nav-right">
                    <button class="profile-trigger" onclick="toggleProfileMenu(event)">
                        <div class="user-avatar">
                            <?php if (!empty($profileImage)): ?>
                                <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile">
                            <?php else: ?>
                                <?php echo htmlspecialchars($userInitial); ?>
                            <?php endif; ?>
                        </div>
                        <span class="avatar-caret">⌄</span>
                    </button>

                    <div id="profileDropdown" class="profile-dropdown">
                        <div class="dropdown-profile-card">
                            <div class="dropdown-avatar">
                                <?php if (!empty($profileImage)): ?>
                                    <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile">
                                <?php else: ?>
                                    <?php echo htmlspecialchars($userInitial); ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars($displayName); ?></h4>
                                <p>@<?php echo htmlspecialchars($username); ?></p>
                            </div>
                        </div>

                        <a href="user-profile.php" class="dropdown-item">
                            <span class="dropdown-icon">👤</span>
                            See Profile
                        </a>

                        <a href="user-profile.php?tab=security" class="dropdown-item">
                            <span class="dropdown-icon">⚙</span>
                            Settings
                        </a>

                        <div class="dropdown-divider"></div>

                        <button type="button" class="dropdown-item" onclick="openLogout()">
                            <span class="dropdown-icon">↪</span>
                            Log out
                        </button>
                    </div>
                </div>
            </div>

            <nav class="user-nav-menu">
                <a href="user-dashboard.php">Dashboard</a>
                <a href="user-branches.php">Locations</a>
                <a href="user-notifications.php" class="notif-link">
                    Notifications
                    <?php if ($unreadNotifCount > 0): ?>
                        <span class="notif-badge"><?php echo $unreadNotifCount > 9 ? '9+' : $unreadNotifCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="user-profile.php">Profile</a>
            </nav>
        </div>
    </div>

    <main class="page-container">
        <div class="page-header glass-card">
            <h1>My Sessions</h1>
            <p>Choose your preferred session type, date, and time.</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo htmlspecialchars($messageType); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="note-box">
            You may only book sessions for today or future dates. If you choose today, the selected time must still be later than the current time. After booking, please wait for admin approval.
        </div>

        <div class="content-card glass-card">
            <h3>Book a Session</h3>

            <form method="POST">
                <?php echo app_csrf_field(); ?>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="session_type">Session Type</label>
                        <select name="session_type" id="session_type" required>
                            <option value="">Select type</option>
                            <?php foreach ($sessionOffers as $type => $price): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>">
                                    <?php echo htmlspecialchars($type); ?> - PHP <?php echo number_format($price, 2); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="session_date">Preferred Date</label>
                        <input type="date" name="session_date" id="session_date" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="session_time">Preferred Time</label>
                        <input type="time" name="session_time" id="session_time" required>
                    </div>
                </div>

                <button type="submit" class="submit-btn">Submit Session Request</button>
            </form>
        </div>

        <div class="content-card glass-card">
            <h3>My Session Records</h3>

            <?php if (!empty($sessionList)): ?>
                <?php foreach ($sessionList as $session): ?>
                    <div class="record-card">
                        <p><strong>Type:</strong> <?php echo htmlspecialchars($session['session_type']); ?></p>
                        <p><strong>Date:</strong> <?php echo htmlspecialchars($session['session_date']); ?></p>
                        <p><strong>Time:</strong> <?php echo date("h:i A", strtotime($session['session_time'])); ?></p>
                        <p>
                            <strong>Status:</strong>
                            <span class="status-pill <?php echo sessionStatusClass($session['status']); ?>">
                                <?php echo htmlspecialchars($session['status']); ?>
                            </span>
                        </p>

                        <?php if ($session['status'] === 'Pending'): ?>
                            <form method="POST">
                                <?php echo app_csrf_field(); ?>
                                <input type="hidden" name="cancel_session_id" value="<?php echo (int)$session['id']; ?>">
                                <button type="submit" class="cancel-btn">Cancel Request</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No session bookings yet.</p>
            <?php endif; ?>
        </div>
    </main>

    <footer class="main-footer">
        <div class="footer-container">
            <div class="footer-section">
                <h4>KSYN Gym</h4>
                <p>Premium fitness experience with modern equipment, expert coaching, and strong community.</p>
            </div>

            <div class="footer-section">
                <h4>Quick Links</h4>
                <a href="login.php">Login</a>
                <a href="user-register.php">Register</a>
                <a href="user-franchise.php">Franchise</a>
            </div>

            <div class="footer-section">
                <h4>Contact</h4>
                <p>Email: support@ksyngym.com</p>
                <p>Phone: +63 912 345 6789</p>
            </div>

            <div class="footer-section">
                <h4>Follow Us</h4>
                <p>Facebook</p>
                <p>Instagram</p>
                <p>TikTok</p>
            </div>
        </div>

        <div class="footer-bottom">
            © <?php echo date("Y"); ?> KSYN Gym: FitConnect. All rights reserved.
        </div>
    </footer>
</div>

<div id="logoutModal" class="modal">
    <div class="modal-content">
        <h3>Logout</h3>
        <p>Are you sure you want to logout?</p>

        <div class="modal-actions">
            <button class="btn-cancel-modal" onclick="closeLogout()">Cancel</button>
            <button class="btn-confirm" onclick="logoutNow()">Logout</button>
        </div>
    </div>
</div>

<script>
function toggleProfileMenu(event) {
    event.stopPropagation();
    document.getElementById("profileDropdown").classList.toggle("show");
}

document.addEventListener("click", function(event) {
    const dropdown = document.getElementById("profileDropdown");
    const trigger = document.querySelector(".profile-trigger");

    if (dropdown && trigger && !dropdown.contains(event.target) && !trigger.contains(event.target)) {
        dropdown.classList.remove("show");
    }
});

function openLogout() {
    const dropdown = document.getElementById("profileDropdown");
    if (dropdown) dropdown.classList.remove("show");

    document.getElementById("logoutModal").style.display = "flex";
}

function closeLogout() {
    document.getElementById("logoutModal").style.display = "none";
}

function logoutNow() {
    window.location.href = "logout.php";
}

window.onclick = function(e) {
    const modal = document.getElementById("logoutModal");
    if (e.target === modal) {
        closeLogout();
    }
};
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>