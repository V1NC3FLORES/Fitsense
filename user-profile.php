<?php
// user-profile.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();
include 'conn.php';
require_once __DIR__ . '/config/app_env.php';
require_once __DIR__ . '/config/session_security.php';
require 'vendor/autoload.php';

define('SMTP_USERNAME', app_env('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', app_env('SMTP_PASSWORD', ''));

$userSession = app_require_session_user($conn, 'user', 'login.php');
$userId = (int)$_SESSION['user_id'];
$message = "";
$messageType = "success";
$activeTab = $_GET['tab'] ?? 'personal';

function sendOtpEmail($toEmail, $toName, $otp) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom(SMTP_USERNAME, 'KSYN Gym: FitConnect');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = "KSYN Password Update Verification Code";
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; color:#111; padding:20px;'>
                <h2 style='color:#ff7a00;'>KSYN Gym: FitConnect</h2>
                <p>Hello <strong>" . htmlspecialchars($toName) . "</strong>,</p>
                <p>Your password update verification code is:</p>
                <h1 style='letter-spacing:5px;color:#ff7a00;'>{$otp}</h1>
                <p>This code will expire in 10 minutes.</p>
            </div>
        ";

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}

function getUser($conn, $userId) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user;
}

$user = getUser($conn, $userId);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

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
        $message = "This form has expired or is invalid. Please refresh the page and try again.";
        $messageType = "error";
    } else {
    if (isset($_POST['update_profile'])) {
        $activeTab = 'personal';

        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $zip = trim($_POST['zip'] ?? '');

        if ($fullname === "" || $email === "" || $phone === "") {
            $message = "Full name, email, and phone are required.";
            $messageType = "error";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
            $messageType = "error";
        } else {
            $stmt = $conn->prepare("
                UPDATE users 
                SET fullname = ?, email = ?, phone = ?, city = ?, state = ?, country = ?, zip = ?
                WHERE id = ?
            ");
            $stmt->bind_param("sssssssi", $fullname, $email, $phone, $city, $state, $country, $zip, $userId);

            if ($stmt->execute()) {
                $_SESSION['fullname'] = $fullname;
                $message = "Profile information updated successfully.";
                $messageType = "success";
            } else {
                $message = "Unable to update profile.";
                $messageType = "error";
            }

            $stmt->close();
        }
    }

    if (isset($_POST['update_health'])) {
        $activeTab = 'health';

        $weight = $_POST['weight'] !== "" ? (float)$_POST['weight'] : null;
        $height = $_POST['height'] !== "" ? (float)$_POST['height'] : null;
        $fitnessGoal = trim($_POST['fitness_goal'] ?? '');
        $activityLevel = trim($_POST['activity_level'] ?? '');
        $emergencyContact = trim($_POST['emergency_contact'] ?? '');
        $emergencyPhone = trim($_POST['emergency_phone'] ?? '');

        $stmt = $conn->prepare("
            UPDATE users 
            SET weight = ?, height = ?, fitness_goal = ?, activity_level = ?, emergency_contact = ?, emergency_phone = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ddssssi", $weight, $height, $fitnessGoal, $activityLevel, $emergencyContact, $emergencyPhone, $userId);

        if ($stmt->execute()) {
            $message = "Fitness and health information updated successfully.";
            $messageType = "success";
        } else {
            $message = "Unable to update fitness information.";
            $messageType = "error";
        }

        $stmt->close();
    }

    if (isset($_POST['upload_profile_image'])) {
        $activeTab = 'photo';

        if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            $message = "Please select a valid image.";
            $messageType = "error";
        } else {
            $uploadDir = "uploads/profile/";

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            $fileName = $_FILES['profile_image']['name'];
            $fileTmp = $_FILES['profile_image']['tmp_name'];
            $fileSize = $_FILES['profile_image']['size'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed)) {
                $message = "Only JPG, JPEG, PNG, and WEBP files are allowed.";
                $messageType = "error";
            } elseif ($fileSize > 2 * 1024 * 1024) {
                $message = "Image must not exceed 2MB.";
                $messageType = "error";
            } else {
                $newFileName = "user_" . $userId . "_" . time() . "." . $ext;
                $targetPath = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmp, $targetPath)) {
                    if (!empty($user['profile_image']) && file_exists($user['profile_image'])) {
                        unlink($user['profile_image']);
                    }

                    $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $stmt->bind_param("si", $targetPath, $userId);
                    $stmt->execute();
                    $stmt->close();

                    $message = "Profile photo uploaded successfully.";
                    $messageType = "success";
                } else {
                    $message = "Unable to upload image.";
                    $messageType = "error";
                }
            }
        }
    }

    if (isset($_POST['delete_profile_image'])) {
        $activeTab = 'photo';

        if (!empty($user['profile_image']) && file_exists($user['profile_image'])) {
            unlink($user['profile_image']);
        }

        $stmt = $conn->prepare("UPDATE users SET profile_image = NULL WHERE id = ?");
        $stmt->bind_param("i", $userId);

        if ($stmt->execute()) {
            $message = "Profile photo deleted successfully.";
            $messageType = "success";
        } else {
            $message = "Unable to delete profile photo.";
            $messageType = "error";
        }

        $stmt->close();
    }

    if (isset($_POST['send_password_otp'])) {
        $activeTab = 'security';

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
            $message = "All password fields are required.";
            $messageType = "error";
        } elseif (!password_verify($currentPassword, $user['password'])) {
            $message = "Current password is incorrect.";
            $messageType = "error";
        } elseif (strlen($newPassword) < 8) {
            $message = "New password must be at least 8 characters long.";
            $messageType = "error";
        } elseif ($newPassword !== $confirmPassword) {
            $message = "New password and confirm password do not match.";
            $messageType = "error";
        } else {
            $otp = (string)random_int(100000, 999999);
            $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

            $stmt = $conn->prepare("UPDATE users SET two_factor_code = ?, two_factor_expiry = ? WHERE id = ?");
            $stmt->bind_param("ssi", $otp, $expiry, $userId);
            $stmt->execute();
            $stmt->close();

            if (sendOtpEmail($user['email'], $user['fullname'], $otp)) {
                $_SESSION['pending_password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                $_SESSION['password_update_pending'] = true;
                $message = "Verification code sent to your email. Enter the code to update your password.";
                $messageType = "success";
            } else {
                $message = "Unable to send verification code.";
                $messageType = "error";
            }
        }
    }

    if (isset($_POST['verify_password_otp'])) {
        $activeTab = 'security';

        $otp = trim($_POST['otp'] ?? '');

        if (!isset($_SESSION['password_update_pending'], $_SESSION['pending_password_hash'])) {
            $message = "Password update session expired. Please try again.";
            $messageType = "error";
        } else {
            $freshUser = getUser($conn, $userId);

            if (
                $freshUser &&
                $freshUser['two_factor_code'] === $otp &&
                !empty($freshUser['two_factor_expiry']) &&
                strtotime($freshUser['two_factor_expiry']) >= time()
            ) {
                $newHash = $_SESSION['pending_password_hash'];

                $stmt = $conn->prepare("
                    UPDATE users 
                    SET password = ?, two_factor_code = NULL, two_factor_expiry = NULL
                    WHERE id = ?
                ");
                $stmt->bind_param("si", $newHash, $userId);

                if ($stmt->execute()) {
                    unset($_SESSION['pending_password_hash'], $_SESSION['password_update_pending']);
                    $message = "Password updated successfully.";
                    $messageType = "success";
                } else {
                    $message = "Unable to update password.";
                    $messageType = "error";
                }

                $stmt->close();
            } else {
                $message = "Invalid or expired verification code.";
                $messageType = "error";
            }
        }
    }
    }
}

$user = getUser($conn, $userId);

$displayName = $user['fullname'] ?? $_SESSION['fullname'] ?? '';
$username = $user['username'] ?? $_SESSION['username'] ?? '';
$profileImage = !empty($user['profile_image']) ? $user['profile_image'] : "";
$userInitial = strtoupper(substr($displayName ?: $username, 0, 1));
$joinDate = !empty($user['created_at']) ? date("F d, Y", strtotime($user['created_at'])) : "N/A";

$_SESSION['fullname'] = $displayName;
$_SESSION['username'] = $username;

$bmi = null;
if (!empty($user['weight']) && !empty($user['height']) && (float)$user['height'] > 0) {
    $heightMeters = ((float)$user['height']) / 100;
    $bmi = round(((float)$user['weight']) / ($heightMeters * $heightMeters), 1);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - KSYN Gym: FitConnect</title>

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
            z-index: 999;
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

        .dropdown-icon svg {
            width: 17px;
            height: 17px;
            stroke: currentColor;
        }

        .dropdown-divider {
            height: 1px;
            background: rgba(255,255,255,0.08);
            margin: 8px 0;
        }

        .profile-container {
            flex: 1;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px;
        }

        .glass-card {
            background: rgba(20,20,20,0.88);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 18px 50px rgba(0,0,0,0.35);
            border-radius: 18px;
        }

        .profile-hero {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 28px;
            align-items: center;
            padding: 34px;
            margin-bottom: 24px;
        }

        .profile-avatar-large {
            width: 120px;
            height: 120px;
            border-radius: 28px;
            background: linear-gradient(135deg, #ff7a00, #ff3c00);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: 900;
            overflow: hidden;
            border: 1px solid rgba(255,122,0,0.35);
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-hero h1 {
            margin: 0 0 8px;
            font-size: 32px;
        }

        .profile-hero p {
            margin: 4px 0;
            color: #bbbbbb;
            font-size: 13px;
        }

        .profile-stat {
            text-align: right;
        }

        .profile-stat span {
            display: block;
            font-size: 12px;
            color: #aaaaaa;
            margin-bottom: 8px;
        }

        .profile-stat strong {
            font-size: 32px;
            color: #ff7a00;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .alert.success {
            background: rgba(76,175,80,0.15);
            color: #7ee787;
            border: 1px solid rgba(76,175,80,0.35);
        }

        .alert.error {
            background: rgba(244,67,54,0.15);
            color: #ff9b9b;
            border: 1px solid rgba(244,67,54,0.35);
        }

        .tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .tab-link {
            padding: 13px 18px;
            border-radius: 999px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            color: #aaaaaa;
            text-decoration: none;
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .7px;
        }

        .tab-link.active,
        .tab-link:hover {
            color: #ffffff;
            background: rgba(255,122,0,0.16);
            border-color: rgba(255,122,0,0.45);
        }

        .profile-section {
            padding: 30px;
            margin-bottom: 24px;
        }

        .section-title {
            margin: 0 0 22px;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-left: 4px solid #ff7a00;
            padding-left: 14px;
        }

        .form-row,
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .info-grid {
            grid-template-columns: repeat(3, 1fr);
            margin-top: 15px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 800;
            color: #bbbbbb;
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: .6px;
        }

        input, select {
            width: 100%;
            padding: 13px;
            border-radius: 10px;
            border: 1px solid #333;
            background: rgba(15,15,15,0.95);
            color: #ffffff;
            outline: none;
        }

        input:focus, select:focus {
            border-color: #ff7a00;
            box-shadow: 0 0 0 3px rgba(255,122,0,0.12);
        }

        input[type="file"] {
            padding: 11px;
        }

        .muted {
            color: #aaaaaa;
            font-size: 12px;
            line-height: 1.6;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 13px 18px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 900;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .8px;
            text-decoration: none;
            display: inline-flex;
            justify-content: center;
            align-items: center;
        }

        .btn-primary {
            background: linear-gradient(90deg, #ff7a00, #ff3c00);
            color: #ffffff;
        }

        .btn-secondary {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.12);
            color: #ffffff;
        }

        .btn-danger {
            background: rgba(244,67,54,0.14);
            border: 1px solid rgba(244,67,54,0.4);
            color: #ff9b9b;
        }

        .info-card {
            padding: 18px;
            border-radius: 14px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .info-card span {
            color: #aaaaaa;
            font-size: 12px;
            display: block;
            margin-bottom: 8px;
        }

        .info-card strong {
            color: #ffffff;
            font-size: 20px;
        }

        .photo-preview-card {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 18px;
            border-radius: 14px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 20px;
        }

        .photo-preview {
            width: 90px;
            height: 90px;
            border-radius: 22px;
            background: linear-gradient(135deg, #ff7a00, #ff3c00);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-size: 34px;
            font-weight: 900;
            flex-shrink: 0;
        }

        .photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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

        .btn-confirm {
            background: linear-gradient(90deg, #ff7a00, #ff3c00);
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

        @media (max-width: 768px) {
            body {
                background-attachment: scroll;
            }

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
                width: auto;
                justify-content: center;
                text-align: center;
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

            .profile-container {
                padding: 24px 14px;
            }

            .profile-hero {
                grid-template-columns: 1fr;
                text-align: center;
                padding: 24px 18px;
            }

            .profile-avatar-large {
                margin: 0 auto;
            }

            .profile-stat {
                text-align: center;
            }

            .tabs {
                justify-content: center;
                gap: 8px;
            }

            .tab-link {
                font-size: 11px;
                padding: 10px 12px;
            }

            .profile-section {
                padding: 24px 18px;
            }

            .form-row,
            .info-grid,
            .footer-container {
                grid-template-columns: 1fr !important;
            }

            .button-group,
            .modal-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .photo-preview-card {
                flex-direction: column;
                text-align: center;
            }

            .modal-content {
                width: 100%;
                max-width: 360px;
            }

            .footer-container {
                text-align: center;
            }
        }

        @media (max-width: 420px) {
            .profile-container {
                padding: 20px 10px;
            }

            .brand-text-main {
                font-size: 15px;
            }

            .user-nav-menu a {
                font-size: 10px;
                padding: 7px 9px;
            }

            .profile-hero h1 {
                font-size: 22px;
            }

            .profile-avatar-large {
                width: 100px;
                height: 100px;
                font-size: 40px;
            }

            .profile-section {
                padding: 20px 14px;
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
                            <span class="dropdown-icon">
                                <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                                    <path d="M20 21a8 8 0 0 0-16 0"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                            </span>
                            See Profile
                        </a>

                        <a href="user-profile.php?tab=security" class="dropdown-item">
                            <span class="dropdown-icon">
                                <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                                    <circle cx="12" cy="12" r="3"/>
                                    <path d="M19.4 15a1.8 1.8 0 0 0 .36 1.98l.05.05a2.1 2.1 0 0 1-2.97 2.97l-.05-.05A1.8 1.8 0 0 0 15 19.4a1.8 1.8 0 0 0-1 .6 1.8 1.8 0 0 0-.4 1.1V21a2.1 2.1 0 0 1-4.2 0v-.08A1.8 1.8 0 0 0 8 19.4a1.8 1.8 0 0 0-1.98.36l-.05.05A2.1 2.1 0 0 1 3 16.84l.05-.05A1.8 1.8 0 0 0 3.6 15a1.8 1.8 0 0 0-.6-1 1.8 1.8 0 0 0-1.1-.4H1.8a2.1 2.1 0 0 1 0-4.2h.08A1.8 1.8 0 0 0 3.6 8a1.8 1.8 0 0 0-.36-1.98l-.05-.05A2.1 2.1 0 0 1 6.16 3l.05.05A1.8 1.8 0 0 0 8 3.6a1.8 1.8 0 0 0 1-.6 1.8 1.8 0 0 0 .4-1.1V1.8a2.1 2.1 0 0 1 4.2 0v.08A1.8 1.8 0 0 0 15 3.6a1.8 1.8 0 0 0 1.98-.36l.05-.05A2.1 2.1 0 0 1 20 6.16l-.05.05A1.8 1.8 0 0 0 19.4 8a1.8 1.8 0 0 0 .6 1 1.8 1.8 0 0 0 1.1.4h.08a2.1 2.1 0 0 1 0 4.2h-.08A1.8 1.8 0 0 0 19.4 15z"/>
                                </svg>
                            </span>
                            Settings
                        </a>

                        <div class="dropdown-divider"></div>

                        <button type="button" class="dropdown-item" onclick="openLogout()">
                            <span class="dropdown-icon">
                                <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                                    <path d="M16 17l5-5-5-5"/>
                                    <path d="M21 12H9"/>
                                </svg>
                            </span>
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
                <a href="user-profile.php" class="active">Profile</a>
            </nav>
        </div>
    </div>

    <main class="profile-container">
        <section class="profile-hero glass-card">
            <div class="profile-avatar-large">
                <?php if ($profileImage): ?>
                    <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo htmlspecialchars($userInitial); ?>
                <?php endif; ?>
            </div>

            <div>
                <h1><?php echo htmlspecialchars($displayName); ?></h1>
                <p><?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
                <p>Member since <?php echo htmlspecialchars($joinDate); ?></p>
            </div>

            <div class="profile-stat">
                <span>Current BMI</span>
                <strong><?php echo $bmi !== null ? htmlspecialchars($bmi) : "N/A"; ?></strong>
            </div>
        </section>

        <?php if (!empty($message)): ?>
            <div class="alert <?php echo htmlspecialchars($messageType); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="tabs">
            <a class="tab-link <?php echo $activeTab === 'personal' ? 'active' : ''; ?>" href="user-profile.php?tab=personal">Personal Info</a>
            <a class="tab-link <?php echo $activeTab === 'photo' ? 'active' : ''; ?>" href="user-profile.php?tab=photo">Profile Photo</a>
            <a class="tab-link <?php echo $activeTab === 'health' ? 'active' : ''; ?>" href="user-profile.php?tab=health">Fitness Info</a>
            <a class="tab-link <?php echo $activeTab === 'security' ? 'active' : ''; ?>" href="user-profile.php?tab=security">Security</a>
        </div>

        <?php if ($activeTab === 'personal'): ?>
            <section class="profile-section glass-card">
                <h2 class="section-title">Personal Information</h2>

                <form method="POST">
                    <?php echo app_csrf_field(); ?>
                    <input type="hidden" name="update_profile" value="1">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" name="fullname" value="<?php echo htmlspecialchars($user['fullname'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>City</label>
                            <input type="text" name="city" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>State / Province</label>
                            <input type="text" name="state" value="<?php echo htmlspecialchars($user['state'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="country" value="<?php echo htmlspecialchars($user['country'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Zip Code</label>
                        <input type="text" name="zip" value="<?php echo htmlspecialchars($user['zip'] ?? ''); ?>">
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="user-profile.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($activeTab === 'photo'): ?>
            <section class="profile-section glass-card">
                <h2 class="section-title">Profile Photo</h2>

                <div class="photo-preview-card">
                    <div class="photo-preview">
                        <?php if ($profileImage): ?>
                            <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo htmlspecialchars($userInitial); ?>
                        <?php endif; ?>
                    </div>

                    <div>
                        <strong>Current Profile Photo</strong>
                        <p class="muted">Upload a clear photo for your account. Allowed formats: JPG, JPEG, PNG, WEBP. Maximum size: 2MB.</p>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <?php echo app_csrf_field(); ?>
                    <input type="hidden" name="upload_profile_image" value="1">

                    <div class="form-group">
                        <label>Upload New Photo</label>
                        <input type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp" required>
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn btn-primary">Upload Photo</button>
                    </div>
                </form>

                <?php if ($profileImage): ?>
                    <form method="POST" onsubmit="return confirm('Delete your current profile photo?');">
                        <?php echo app_csrf_field(); ?>
                        <input type="hidden" name="delete_profile_image" value="1">
                        <div class="button-group">
                            <button type="submit" class="btn btn-danger">Delete Current Photo</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($activeTab === 'health'): ?>
            <section class="profile-section glass-card">
                <h2 class="section-title">Fitness and Health Information</h2>

                <div class="info-grid">
                    <div class="info-card">
                        <span>Weight</span>
                        <strong><?php echo !empty($user['weight']) ? htmlspecialchars($user['weight']) . " kg" : "N/A"; ?></strong>
                    </div>

                    <div class="info-card">
                        <span>Height</span>
                        <strong><?php echo !empty($user['height']) ? htmlspecialchars($user['height']) . " cm" : "N/A"; ?></strong>
                    </div>

                    <div class="info-card">
                        <span>BMI</span>
                        <strong><?php echo $bmi !== null ? htmlspecialchars($bmi) : "N/A"; ?></strong>
                    </div>
                </div>

                <br>

                <form method="POST">
                    <?php echo app_csrf_field(); ?>
                    <input type="hidden" name="update_health" value="1">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Weight (kg)</label>
                            <input type="number" name="weight" step="0.01" min="1" value="<?php echo htmlspecialchars($user['weight'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label>Height (cm)</label>
                            <input type="number" name="height" step="0.01" min="1" value="<?php echo htmlspecialchars($user['height'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Fitness Goal</label>
                            <select name="fitness_goal">
                                <?php $goal = $user['fitness_goal'] ?? ''; ?>
                                <option value="">Select goal</option>
                                <option value="Lose Weight" <?php echo $goal === 'Lose Weight' ? 'selected' : ''; ?>>Lose Weight</option>
                                <option value="Build Muscle" <?php echo $goal === 'Build Muscle' ? 'selected' : ''; ?>>Build Muscle</option>
                                <option value="Improve Endurance" <?php echo $goal === 'Improve Endurance' ? 'selected' : ''; ?>>Improve Endurance</option>
                                <option value="Maintain Fitness" <?php echo $goal === 'Maintain Fitness' ? 'selected' : ''; ?>>Maintain Fitness</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Activity Level</label>
                            <select name="activity_level">
                                <?php $level = $user['activity_level'] ?? ''; ?>
                                <option value="">Select activity level</option>
                                <option value="Beginner" <?php echo $level === 'Beginner' ? 'selected' : ''; ?>>Beginner</option>
                                <option value="Moderate" <?php echo $level === 'Moderate' ? 'selected' : ''; ?>>Moderate</option>
                                <option value="Active" <?php echo $level === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Athlete" <?php echo $level === 'Athlete' ? 'selected' : ''; ?>>Athlete</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Emergency Contact Name</label>
                            <input type="text" name="emergency_contact" value="<?php echo htmlspecialchars($user['emergency_contact'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label>Emergency Contact Phone</label>
                            <input type="text" name="emergency_phone" value="<?php echo htmlspecialchars($user['emergency_phone'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn btn-primary">Save Fitness Info</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($activeTab === 'security'): ?>
            <section class="profile-section glass-card">
                <h2 class="section-title">Security and Password</h2>
                <p class="muted">For security, changing your password requires a verification code sent to your registered email.</p>

                <?php if (!isset($_SESSION['password_update_pending'])): ?>
                    <form method="POST">
                        <?php echo app_csrf_field(); ?>
                        <input type="hidden" name="send_password_otp" value="1">

                        <div class="form-group">
                            <label>Current Password</label>
                            <input type="password" name="current_password" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" name="new_password" required>
                            </div>

                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" name="confirm_password" required>
                            </div>
                        </div>

                        <div class="button-group">
                            <button type="submit" class="btn btn-primary">Send Verification Code</button>
                        </div>
                    </form>
                <?php else: ?>
                    <form method="POST">
                        <?php echo app_csrf_field(); ?>
                        <input type="hidden" name="verify_password_otp" value="1">

                        <div class="form-group">
                            <label>Enter 6-Digit Verification Code</label>
                            <input type="text" name="otp" maxlength="6" placeholder="6-digit code" required>
                        </div>

                        <div class="button-group">
                            <button type="submit" class="btn btn-primary">Verify and Update Password</button>
                            <a href="user-profile.php?tab=security" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
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
            <button class="btn-cancel" onclick="closeLogout()">Cancel</button>
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