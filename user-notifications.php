<?php
// user-notifications.php
session_start();
include 'conn.php';
require_once __DIR__ . '/config/session_security.php';

$userSession = app_require_session_user($conn, 'user', 'login.php');
$userId = (int)$_SESSION['user_id'];

$stmtUser = $conn->prepare("SELECT fullname, username, profile_image FROM users WHERE id = ? LIMIT 1");
$stmtUser->bind_param("i", $userId);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

$displayName = $user['fullname'] ?? ($_SESSION['fullname'] ?? 'User');
$username = $user['username'] ?? ($_SESSION['username'] ?? '');
$profileImage = $user['profile_image'] ?? '';
$userInitial = strtoupper(substr($displayName ?: $username, 0, 1));

$unreadNotifCount = 0;
$stmtUnread = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0");
$stmtUnread->bind_param("i", $userId);
$stmtUnread->execute();
$resultUnread = $stmtUnread->get_result();

if ($resultUnread && $resultUnread->num_rows > 0) {
    $unreadNotifCount = (int)$resultUnread->fetch_assoc()['total'];
}
$stmtUnread->close();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_message'] = 'This form has expired or is invalid. Please refresh the page and try again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: user-notifications.php');
        exit;
    }

    if (isset($_POST['mark_read_id'])) {
        $notificationId = (int)$_POST['mark_read_id'];

        $stmtMark = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmtMark->bind_param("ii", $notificationId, $userId);
        $stmtMark->execute();
        $stmtMark->close();

        header("Location: user-notifications.php");
        exit();
    }

    if (isset($_POST['mark_all_read'])) {
        $stmtAll = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmtAll->bind_param("i", $userId);
        $stmtAll->execute();
        $stmtAll->close();

        header("Location: user-notifications.php");
        exit();
    }
}

$notifications = [];
$stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt->close();

function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . " minute" . (floor($diff / 60) > 1 ? "s" : "") . " ago";
    if ($diff < 86400) return floor($diff / 3600) . " hour" . (floor($diff / 3600) > 1 ? "s" : "") . " ago";
    if ($diff < 604800) return floor($diff / 86400) . " day" . (floor($diff / 86400) > 1 ? "s" : "") . " ago";

    return date("M d, Y", $timestamp);
}

function getNotificationIcon($type) {
    $type = strtolower($type ?? '');

    if ($type === 'membership') {
        return '<svg fill="none" viewBox="0 0 24 24" stroke-width="2"><path d="M20 7h-8m8 5h-8m8 5h-8M4 7h4v4H4zM4 13h4v4H4z"/></svg>';
    }

    if ($type === 'session') {
        return '<svg fill="none" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>';
    }

    if ($type === 'system') {
        return '<svg fill="none" viewBox="0 0 24 24" stroke-width="2"><path d="M12 9v4m0 4h.01"/><circle cx="12" cy="12" r="9"/></svg>';
    }

    return '<svg fill="none" viewBox="0 0 24 24" stroke-width="2"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/><path d="M10 21h4"/></svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - KSYN Gym: FitConnect</title>

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
            justify-content: center;
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
            flex-shrink: 0;
        }

        .brand-text-main {
            font-size: 22px;
            font-weight: 800;
            color: #ff7a00;
            line-height: 1;
            white-space: nowrap;
        }

        .brand-text-sub {
            font-size: 10px;
            color: #aaaaaa;
            letter-spacing: 1.5px;
            margin-top: 5px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .user-nav-right {
            display: flex;
            align-items: center;
            position: relative;
            gap: 0;
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
            border: 2px solid rgba(255,122,0,0.75);
        }

        .user-avatar img {
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
            z-index: 9999;
        }

        .profile-dropdown.show {
            display: block;
        }

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

        .dropdown-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .dropdown-profile-card h4 {
            margin: 0 0 3px;
            font-size: 15px;
            color: #ffffff;
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
            color: inherit;
            flex-shrink: 0;
            font-size: 14px;
        }

        .dropdown-divider {
            height: 1px;
            background: rgba(255,255,255,0.08);
            margin: 8px 0;
        }

        .user-nav-menu {
            display: flex;
            gap: 25px;
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

        .notifications-container {
            flex: 1;
            padding: 40px;
            max-width: 1100px;
            width: 100%;
            margin: 0 auto;
        }

        .glass-card {
            background: rgba(20,20,20,0.88);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 18px 50px rgba(0,0,0,0.35);
            border-radius: 18px;
        }

        .notifications-header {
            padding: 34px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .notifications-header h1 {
            margin: 0 0 10px;
            font-size: 32px;
        }

        .notifications-header p {
            margin: 0;
            color: #bbbbbb;
            font-size: 14px;
            line-height: 1.6;
        }

        .mark-all-btn {
            background: linear-gradient(90deg, #ff7a00, #ff3c00);
            border: none;
            color: #ffffff;
            padding: 13px 18px;
            border-radius: 999px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .8px;
            white-space: nowrap;
        }

        .notifications-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .notification-item {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 18px;
            align-items: flex-start;
            padding: 22px;
            transition: 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .notification-item.unread {
            border-color: rgba(255,122,0,0.35);
            background: rgba(255,122,0,0.08);
        }

        .notification-item.unread::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            background: linear-gradient(#ff7a00, #ff3c00);
        }

        .notification-icon {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            background: rgba(255,122,0,0.13);
            border: 1px solid rgba(255,122,0,0.28);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ff7a00;
        }

        .notification-icon svg {
            width: 28px;
            height: 28px;
            stroke: currentColor;
        }

        .notification-content h3 {
            margin: 0 0 8px;
            font-size: 16px;
        }

        .notification-message {
            margin: 0 0 12px;
            color: #cfcfcf;
            font-size: 13px;
            line-height: 1.6;
        }

        .notification-meta {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .notification-type {
            display: inline-flex;
            padding: 6px 11px;
            border-radius: 999px;
            background: rgba(255,122,0,0.16);
            color: #ffb56b;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .7px;
        }

        .notification-type.system {
            background: rgba(100,150,255,0.15);
            color: #9bbcff;
        }

        .notification-type.general {
            background: rgba(100,200,150,0.15);
            color: #7ee787;
        }

        .notification-type.membership {
            background: rgba(255,152,0,0.15);
            color: #ffb74d;
        }

        .notification-type.session {
            background: rgba(156,39,176,0.15);
            color: #ce93d8;
        }

        .notification-date {
            color: #888888;
            font-size: 12px;
        }

        .mark-read-btn {
            padding: 9px 13px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,122,0,0.45);
            color: #ff7a00;
            border-radius: 999px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
            text-transform: uppercase;
        }

        .read-label {
            color: #777777;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .empty-state {
            text-align: center;
            padding: 70px 25px;
        }

        .empty-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 18px;
            border-radius: 22px;
            background: rgba(255,122,0,0.13);
            border: 1px solid rgba(255,122,0,0.28);
            display: flex;
            justify-content: center;
            align-items: center;
            color: #ff7a00;
        }

        .empty-icon svg {
            width: 34px;
            height: 34px;
            stroke: currentColor;
        }

        .empty-state h3 {
            margin: 0 0 10px;
            font-size: 20px;
        }

        .empty-state p {
            margin: 0;
            color: #aaaaaa;
            font-size: 14px;
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

        .modal-content p {
            color: #aaaaaa;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
        }

        .btn-cancel,
        .btn-confirm {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            color: #ffffff;
        }

        .btn-cancel { background: #333333; }
        .btn-confirm { background: linear-gradient(90deg, #ff7a00, #ff3c00); }

        @media (max-width: 768px) {
            body {
                background-attachment: scroll;
            }

            .user-nav {
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
                width: auto;
                justify-content: center;
                text-align: left;
                gap: 10px;
            }

            .brand-logo {
                width: 42px;
                height: 42px;
            }

            .brand-text-main {
                font-size: 17px;
                line-height: 1.2;
            }

            .brand-text-sub {
                font-size: 9px;
                letter-spacing: 1px;
            }

            .user-avatar {
                width: 40px;
                height: 40px;
            }

            .profile-dropdown {
                top: 54px;
                right: 0;
                width: min(92vw, 330px);
            }

            .user-nav-menu {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .user-nav-menu a {
                width: 100%;
                text-align: center;
                font-size: 11px;
                padding: 9px 8px;
                background: rgba(255,255,255,0.06);
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 999px;
            }

            .user-nav-menu a.active {
                background: rgba(255,122,0,0.16);
                border-color: rgba(255,122,0,0.35);
            }

            .notif-link {
                justify-content: center;
            }

            .notifications-container {
                padding: 24px 14px;
            }

            .notifications-header {
                flex-direction: column;
                align-items: stretch;
                padding: 24px 18px;
                text-align: center;
            }

            .notifications-header h1 {
                font-size: 26px;
            }

            .mark-all-btn,
            .mark-read-btn {
                width: 100%;
            }

            .notification-item {
                grid-template-columns: 1fr;
                gap: 14px;
                padding: 20px 16px;
                text-align: center;
            }

            .notification-icon {
                margin: 0 auto;
            }

            .notification-meta {
                justify-content: center;
            }

            .modal-actions {
                flex-direction: column;
            }

            .modal-content {
                width: 100%;
                max-width: 360px;
            }
        }

        @media (max-width: 420px) {
            .notifications-container {
                padding: 20px 10px;
            }

            .brand-text-main {
                font-size: 15px;
            }

            .brand-text-sub {
                font-size: 8px;
            }

            .user-nav-menu a {
                font-size: 10px;
                padding: 7px 9px;
            }

            .notifications-header h1 {
                font-size: 22px;
            }

            .profile-dropdown {
                right: -6px;
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
                <a href="user-notifications.php" class="notif-link active">
                    Notifications
                    <?php if ($unreadNotifCount > 0): ?>
                        <span class="notif-badge"><?php echo $unreadNotifCount > 9 ? '9+' : $unreadNotifCount; ?></span>
                    <?php endif; ?>
                </a>
                <a href="user-profile.php">Profile</a>
            </nav>
        </div>
    </div>

    <main class="notifications-container">
        <section class="notifications-header glass-card">
            <div>
                <h1>Notifications</h1>
                <p>View your latest account updates, membership alerts, session reminders, and system messages.</p>
            </div>

            <?php if ($unreadNotifCount > 0): ?>
                <form method="POST">
                    <?php echo app_csrf_field(); ?>
                    <button type="submit" name="mark_all_read" class="mark-all-btn">Mark All as Read</button>
                </form>
            <?php endif; ?>
        </section>

        <div class="notifications-list">
            <?php if (!empty($notifications)): ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item glass-card <?php echo (int)$notification['is_read'] === 0 ? 'unread' : ''; ?>">
                        <div class="notification-icon">
                            <?php echo getNotificationIcon($notification['type'] ?? 'general'); ?>
                        </div>

                        <div class="notification-content">
                            <h3><?php echo htmlspecialchars($notification['title'] ?? 'Notification'); ?></h3>
                            <p class="notification-message"><?php echo htmlspecialchars($notification['message'] ?? ''); ?></p>

                            <div class="notification-meta">
                                <span class="notification-type <?php echo htmlspecialchars($notification['type'] ?? 'general'); ?>">
                                    <?php echo htmlspecialchars($notification['type'] ?? 'general'); ?>
                                </span>
                                <span class="notification-date">
                                    <?php echo htmlspecialchars(timeAgo($notification['created_at'])); ?>
                                </span>
                            </div>
                        </div>

                        <div>
                            <?php if ((int)$notification['is_read'] === 0): ?>
                                <form method="POST">
                                    <?php echo app_csrf_field(); ?>
                                    <input type="hidden" name="mark_read_id" value="<?php echo (int)$notification['id']; ?>">
                                    <button type="submit" class="mark-read-btn">Mark Read</button>
                                </form>
                            <?php else: ?>
                                <span class="read-label">Read</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state glass-card">
                    <div class="empty-icon">
                        <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                            <path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"/>
                            <path d="M10 21h4"/>
                        </svg>
                    </div>
                    <h3>No Notifications</h3>
                    <p>You are all caught up. New updates will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<div id="logoutModal" class="modal">
    <div class="modal-content">
        <h3>Logout</h3>
        <p>Are you sure you want to logout?</p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeLogout()">Cancel</button>
            <button class="btn-confirm" onclick="logoutNow()">Logout</button>
        </div>
    </div>
</div>

<script>
function toggleProfileMenu(event) {
    event.stopPropagation();
    const dropdown = document.getElementById("profileDropdown");
    if (dropdown) dropdown.classList.toggle("show");
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
    if (e.target === modal) closeLogout();
};
</script>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>