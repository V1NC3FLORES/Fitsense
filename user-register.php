<?php
// user-register.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();
include 'conn.php';
require_once __DIR__ . '/config/app_env.php';
require_once __DIR__ . '/config/session_security.php';
require 'vendor/autoload.php';

// ============================================
// SMTP CONFIGURATION
// ============================================
define('SMTP_USERNAME', app_env('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', app_env('SMTP_PASSWORD', ''));

$message = "";
$messageType = "error";
$accountVerified = false;

// ============================================
// HELPER: SEND OTP EMAIL
// ============================================
function sendOtpEmail($toEmail, $toName, $otp)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom(SMTP_USERNAME, 'Umak Gym: Fitsenses');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Umak Gym Account';

        $mail->Body = "
            <div style='font-family: Arial, sans-serif; color:#111; padding:20px; max-width:600px;'>
                <div style='background:#ffce00; padding:18px; border-radius:8px 8px 0 0; border-bottom:4px solid #005b9f;'>
                    <h2 style='color:#005b9f; margin:0;'>Umak Gym: Fitsenses</h2>
                </div>
                <div style='background:#f9fafb; padding:24px; border-radius:0 0 8px 8px; border:1px solid #e5e7eb;'>
                    <p>Hello <strong>" . htmlspecialchars($toName) . "</strong>,</p>
                    <p>Thank you for registering! Please use the verification code below to activate your account:</p>
                    <div style='background:white; border:2px dashed #005b9f; padding:20px; text-align:center; border-radius:8px; margin:20px 0;'>
                        <h1 style='letter-spacing:8px; color:#005b9f; margin:0; font-size:36px;'>{$otp}</h1>
                    </div>
                    <p style='color:#6b7280; font-size:13px;'>This code will expire in <strong>10 minutes</strong>.</p>
                    <p style='color:#6b7280; font-size:13px;'>If you didn't request this, you can safely ignore this email.</p>
                    <hr style='border:none; border-top:1px solid #e5e7eb; margin:20px 0;'>
                    <p style='color:#9ca3af; font-size:12px; margin:0;'>Umak Gym: Fitsenses &middot; Stay fit, stay healthy</p>
                </div>
            </div>
        ";

        $mail->AltBody = "Your Umak Gym verification code is: {$otp}\n\nThis code expires in 10 minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("OTP Email Error: " . $mail->ErrorInfo);
        return false;
    }
}

// ============================================
// HANDLE FORM SUBMISSIONS
// ============================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $message = "This form has expired or is invalid. Please refresh the page and try again.";
        $messageType = "error";
    } else {

    // ============================================
    // STEP 1: REGISTRATION
    // ============================================
    if (isset($_POST['register_submit'])) {
        $fullname = trim($_POST['fullname'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $role     = 'user';

        // Validation
        if ($password !== $confirm) {
            $message = "Passwords do not match.";
        } elseif (
            strlen($password) < 8 ||
            !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password) ||
            !preg_match('/[\W_]/', $password)
        ) {
            $message = "Password must be at least 8 characters and include uppercase, lowercase, number, and special character.";
        } elseif (!preg_match('/[A-Za-z]/', $username)) {
            $message = "Username must contain at least one letter.";
        } elseif (
            empty($fullname) ||
            empty($email) ||
            empty($phone) ||
            empty($username) ||
            empty($password)
        ) {
            $message = "Please complete all required fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
        } else {
            // Check for existing user
            $check = $conn->prepare("
                SELECT id
                FROM users
                WHERE username = ? OR email = ?
                LIMIT 1
            ");
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $checkResult = $check->get_result();

            if ($checkResult && $checkResult->num_rows > 0) {
                $message = "Username or email already exists.";
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $otp    = (string) random_int(100000, 999999);
                $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

                // Insert user (no branch_id needed)
                $stmt = $conn->prepare("
                    INSERT INTO users
                    (
                        fullname, email, phone, username, password, 
                        role, is_verified, two_factor_code, two_factor_expiry
                    )
                    VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
                ");
                $stmt->bind_param(
                    "ssssssss",
                    $fullname, $email, $phone, $username, $hashed,
                    $role, $otp, $expiry
                );

                if ($stmt->execute()) {
                    $newUserId = $stmt->insert_id;

                    if (sendOtpEmail($email, $fullname, $otp)) {
                        $_SESSION['pending_register_user_id'] = $newUserId;
                        $message = "Account created successfully. A verification code was sent to your email.";
                        $messageType = "success";
                    } else {
                        // Email failed but user was created — allow verification to be retried
                        $_SESSION['pending_register_user_id'] = $newUserId;
                        $message = "Account created, but the verification email could not be sent. Please check your email settings or try again.";
                        $messageType = "error";
                    }
                } else {
                    $message = "Error registering account. Please try again.";
                }
                $stmt->close();
            }
            $check->close();
        }
    }

    // ============================================
    // STEP 2: OTP VERIFICATION
    // ============================================
    if (isset($_POST['verify_register_code'])) {
        $otp = trim($_POST['otp'] ?? '');

        if (!isset($_SESSION['pending_register_user_id'])) {
            $message = "Session expired. Please register again.";
        } else {
            $pendingUserId = (int) $_SESSION['pending_register_user_id'];

            $stmt = $conn->prepare("SELECT id, two_factor_code, two_factor_expiry FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $pendingUserId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();

                if (
                    !empty($user['two_factor_code']) &&
                    $user['two_factor_code'] === $otp &&
                    !empty($user['two_factor_expiry']) &&
                    strtotime($user['two_factor_expiry']) >= time()
                ) {
                    $update = $conn->prepare("
                        UPDATE users
                        SET is_verified = 1, two_factor_code = NULL, two_factor_expiry = NULL
                        WHERE id = ?
                    ");
                    $update->bind_param("i", $pendingUserId);
                    $update->execute();
                    $update->close();

                    unset($_SESSION['pending_register_user_id']);
                    $accountVerified = true;
                    $message = "Account verified successfully. You may now login.";
                    $messageType = "success";
                } else {
                    $message = "Invalid or expired verification code.";
                }
            } else {
                $message = "Account not found. Please register again.";
            }
            $stmt->close();
        }
    }

    // ============================================
    // STEP 3: RESEND OTP
    // ============================================
    if (isset($_POST['resend_otp'])) {
        if (!isset($_SESSION['pending_register_user_id'])) {
            $message = "Session expired. Please register again.";
        } else {
            $pendingUserId = (int) $_SESSION['pending_register_user_id'];

            $stmt = $conn->prepare("SELECT id, fullname, email FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $pendingUserId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $newOtp    = (string) random_int(100000, 999999);
                $newExpiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

                $update = $conn->prepare("
                    UPDATE users
                    SET two_factor_code = ?, two_factor_expiry = ?
                    WHERE id = ?
                ");
                $update->bind_param("ssi", $newOtp, $newExpiry, $pendingUserId);
                $update->execute();
                $update->close();

                if (sendOtpEmail($user['email'], $user['fullname'], $newOtp)) {
                    $message = "A new verification code has been sent to your email.";
                    $messageType = "success";
                } else {
                    $message = "Could not send verification email. Please try again.";
                }
            } else {
                $message = "Account not found. Please register again.";
            }
            $stmt->close();
        }
    }
    }
}

// ============================================
// SHOW OTP FORM?
// ============================================
$showOtpForm = isset($_SESSION['pending_register_user_id']) && !$accountVerified;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Umak Gym: Fitsenses - Register</title>
    <style>
        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            color: #000000;
            background-color: #f4f6f9;
            overflow-x: hidden;
        }

        .auth-header {
            width: 100%;
            background: #ffce00;
            border-bottom: 4px solid #005b9f;
            padding: 14px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 999;
        }

        .auth-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .auth-brand img {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #005b9f;
            background: #fff;
        }

        .auth-brand-title {
            color: #000000;
            font-size: 21px;
            font-weight: 900;
        }

        .auth-brand-sub {
            color: #333333;
            font-size: 10px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-top: 4px;
        }

        .auth-header-links {
            display: flex;
            gap: 22px;
        }

        .auth-header-links a {
            color: #000000;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            transition: color 0.3s;
        }

        .auth-header-links a:hover,
        .auth-header-links a.active {
            color: #005b9f;
        }

        .page-wrapper {
            min-height: calc(100vh - 73px);
            display: flex;
            flex-direction: column;
        }

        .auth-main {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 45px 20px;
        }

        .auth-card {
            width: 100%;
            max-width: 470px;
            background: #ffffff;
            border: 2px solid #e0e0e0;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border-top: 6px solid #005b9f;
            border-radius: 12px;
            padding: 38px;
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            margin-bottom: 25px;
        }

        .brand img {
            width: 72px;
            height: 72px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #ffce00;
        }

        .brand-title {
            font-size: 30px;
            font-weight: 900;
            color: #005b9f;
            line-height: 1;
        }

        .brand-sub {
            font-size: 12px;
            color: #666666;
            letter-spacing: 2px;
            margin-top: 6px;
        }

        h2 {
            margin: 0 0 20px;
            font-size: 22px;
            text-align: center;
            color: #000000;
        }

        .alert {
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 13px;
            text-align: center;
            line-height: 1.5;
        }

        .alert.error {
            background: rgba(244,67,54,0.1);
            color: #d32f2f;
            border: 1px solid rgba(244,67,54,0.3);
        }

        .alert.success {
            background: rgba(76,175,80,0.1);
            color: #2e7d32;
            border: 1px solid rgba(76,175,80,0.3);
        }

        .form-group { margin-bottom: 17px; }

        label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #333333;
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: .6px;
        }

        input, select {
            width: 100%;
            padding: 13px;
            border-radius: 9px;
            border: 1px solid #cccccc;
            background: #ffffff;
            color: #000000;
            outline: none;
            font-size: 15px;
            transition: all 0.3s;
        }

        input:focus, select:focus {
            border-color: #005b9f;
            box-shadow: 0 0 0 3px rgba(0, 91, 159, 0.15);
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 50px;
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 22px;
            height: 22px;
            cursor: pointer;
            opacity: 0.6;
            transition: 0.2s ease;
        }

        .toggle-password:hover {
            opacity: 1;
        }

        .password-guide {
            color: #666666;
            display: block;
            margin-top: 8px;
            line-height: 1.6;
            font-size: 12px;
        }

        .auth-btn {
            width: 100%;
            padding: 14px;
            border: 2px solid #000000;
            border-radius: 10px;
            background: #ffce00;
            color: #000000;
            font-weight: 900;
            cursor: pointer;
            margin-top: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
            font-size: 14px;
        }

        .auth-btn:hover {
            background: #005b9f;
            color: #ffffff;
            border-color: #005b9f;
        }

        .auth-btn-outline {
            width: 100%;
            padding: 12px;
            border: 2px solid #005b9f;
            border-radius: 10px;
            background: transparent;
            color: #005b9f;
            font-weight: 800;
            cursor: pointer;
            margin-top: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
            font-size: 13px;
        }

        .auth-btn-outline:hover {
            background: #005b9f;
            color: #ffffff;
        }

        .otp-input {
            text-align: center;
            font-size: 28px;
            font-weight: 900;
            letter-spacing: 12px;
            padding: 18px 13px;
            color: #005b9f;
            font-family: 'Courier New', monospace;
        }

        .otp-info {
            background: #f0f7fc;
            border-left: 4px solid #005b9f;
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #1e40af;
            line-height: 1.6;
        }

        .otp-info i {
            font-style: normal;
            margin-right: 6px;
        }

        .success-box {
            text-align: center;
            padding: 20px 0;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2e7d32, #4ade80);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 44px;
            font-weight: 900;
            animation: pop 0.5s ease;
            box-shadow: 0 15px 40px rgba(46,125,50,0.35);
        }

        @keyframes pop {
            0% { transform: scale(0) rotate(-180deg); }
            50% { transform: scale(1.2) rotate(10deg); }
            100% { transform: scale(1) rotate(0); }
        }

        .success-box h3 {
            margin: 0 0 10px;
            font-size: 22px;
            font-weight: 900;
            color: #2e7d32;
        }

        .success-box p {
            margin: 0 0 24px;
            color: #6b7280;
            font-size: 14px;
            line-height: 1.6;
        }

        .links {
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid #eeeeee;
            text-align: center;
            color: #555555;
            font-size: 13px;
            line-height: 1.8;
        }

        .links a {
            color: #005b9f;
            text-decoration: none;
            font-weight: 700;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .main-footer {
            background: #000000;
            border-top: 4px solid #ffce00;
            color: #aaaaaa;
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.4fr 1fr 1.2fr 1fr;
            gap: 45px;
            padding: 45px 24px;
        }

        .footer-section h4 {
            margin: 0 0 14px;
            font-size: 17px;
            color: #ffce00;
            font-weight: 800;
        }

        .footer-section p,
        .footer-section a {
            font-size: 13px;
            color: #cccccc;
            text-decoration: none;
            display: block;
            margin-bottom: 10px;
            line-height: 1.7;
        }

        .footer-section a:hover { color: #ffffff; }

        .footer-bottom {
            text-align: center;
            padding: 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 13px;
            color: #888888;
        }

        @media (max-width: 768px) {
            .auth-header {
                flex-direction: column;
                gap: 14px;
                padding: 16px;
            }

            .auth-brand {
                justify-content: center;
                text-align: center;
            }

            .auth-brand-title {
                font-size: 18px;
            }

            .auth-header-links {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
                gap: 10px;
            }

            .auth-header-links a {
                font-size: 11px;
                padding: 8px 10px;
                background: rgba(0,0,0,0.05);
                border-radius: 999px;
            }

            .auth-main {
                align-items: flex-start;
                padding: 28px 14px;
            }

            .auth-card {
                max-width: 100%;
                padding: 28px 20px;
                border-radius: 16px;
            }

            .brand {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .brand img {
                width: 64px;
                height: 64px;
            }

            .brand-title {
                font-size: 26px;
            }

            h2 {
                font-size: 20px;
            }

            .otp-input {
                font-size: 22px;
                letter-spacing: 8px;
            }

            .footer-container {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 22px;
                padding: 32px 18px;
            }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css"></head>
<body>

<header class="auth-header">
    <a href="login.php" class="auth-brand">
        <img src="uploads/umak-gym-logo.png" alt="Umak Gym Logo">
        <div>
            <div class="auth-brand-title">Umak Gym: Fitsenses</div>
            <div class="auth-brand-sub">FITSENSE GYM SYSTEM</div>
        </div>
    </a>

    <nav class="auth-header-links">
        <a href="login.php">Login</a>
        <a href="user-register.php" class="active">Register</a>
    </nav>
</header>

<div class="page-wrapper">
    <main class="auth-main">
        <div class="auth-card">
            <div class="brand">
                <img src="uploads/umak-gym-logo.png" alt="Umak Gym Logo">
                <div>
                    <div class="brand-title">Umak Gym</div>
                    <div class="brand-sub">FITSENSE GYM SYSTEM</div>
                </div>
            </div>

            <h2>
                <?php
                if ($accountVerified) {
                    echo "Account Verified";
                } elseif ($showOtpForm) {
                    echo "Verify Your Email";
                } else {
                    echo "Create Account";
                }
                ?>
            </h2>

            <?php if (!empty($message)): ?>
                <div class="alert <?php echo htmlspecialchars($messageType); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($accountVerified): ?>
                <!-- ============================================
                     SUCCESS STATE
                     ============================================ -->
                <div class="success-box">
                    <div class="success-icon">&#10003;</div>
                    <h3>Account Verified</h3>
                    <p>Your account has been successfully verified.<br>You can now log in to FitSense.</p>
                    <a href="login.php" class="auth-btn" style="display: block; text-decoration: none; text-align: center;">
                        Go to Login
                    </a>
                </div>

            <?php elseif ($showOtpForm): ?>
                <!-- ============================================
                     OTP VERIFICATION FORM
                     ============================================ -->
                <div class="otp-info">
                    <i>&#9993;</i>
                    We've sent a 6-digit verification code to your email. Enter it below to verify your account.
                </div>

                <form method="POST">
                    <?php echo app_csrf_field(); ?>
                    <div class="form-group">
                        <label>Verification Code</label>
                        <input type="text" name="otp" class="otp-input"
                               maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                               placeholder="000000" required autofocus>
                    </div>

                    <button type="submit" name="verify_register_code" class="auth-btn">
                        Verify Account
                    </button>

                    <button type="submit" name="resend_otp" class="auth-btn-outline"
                            formnovalidate>
                        Resend Code
                    </button>
                </form>

                <div class="links">
                    Wrong email? <a href="user-register.php?cancel=1">Start over</a>
                </div>

            <?php else: ?>
                <!-- ============================================
                     REGISTRATION FORM
                     ============================================ -->
                <form method="POST">
                    <?php echo app_csrf_field(); ?>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="fullname"
                               value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="tel" name="phone" placeholder="+63 9XX XXX XXXX"
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username"
                               placeholder="Must contain at least one letter"
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label>Password *</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="password"
                                   placeholder="Example: Fitsense@123"
                                   pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$"
                                   title="Password must contain at least 8 characters, uppercase letter, lowercase letter, number, and special character."
                                   required>
                            <img src="https://cdn-icons-png.flaticon.com/512/709/709612.png"
                                 class="toggle-password"
                                 onclick="togglePassword('password', this)"
                                 alt="Show Password">
                        </div>
                        <small class="password-guide">
                            Password must contain:<br>
                            &bull; At least 8 characters<br>
                            &bull; Uppercase letter<br>
                            &bull; Lowercase letter<br>
                            &bull; Number<br>
                            &bull; Special character
                        </small>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <div class="password-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password" required>
                            <img src="https://cdn-icons-png.flaticon.com/512/709/709612.png"
                                 class="toggle-password"
                                 onclick="togglePassword('confirm_password', this)"
                                 alt="Show Password">
                        </div>
                    </div>

                    <button type="submit" name="register_submit" class="auth-btn">Create Account</button>
                </form>

                <div class="links">
                    Already have an account? <a href="login.php">Sign in here</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="main-footer">
        <div class="footer-container">
            <div class="footer-section">
                <h4>Umak Gym: Fitsenses</h4>
                <p>Premium fitness experience with modern equipment, expert coaching, and a strong fitness community.</p>
            </div>

            <div class="footer-section">
                <h4>Quick Links</h4>
                <a href="login.php">Login</a>
                <a href="user-register.php">Register</a>
                <a href="help.php">Help & Support</a>
            </div>

            <div class="footer-section">
                <h4>Contact</h4>
                <p>Email: support@umakgym.com</p>
                <p>Phone: +63 912 345 6789</p>
            </div>

            <div class="footer-section">
                <h4>Follow Us</h4>
                <a href="#">Facebook</a>
                <a href="#">Instagram</a>
                <a href="#">TikTok</a>
            </div>
        </div>

        <div class="footer-bottom">
            &copy; <?php echo date("Y"); ?> Umak Gym: Fitsenses. All rights reserved.
        </div>
    </footer>
</div>

<script>
function togglePassword(inputId, icon) {
    const input = document.getElementById(inputId);
    if (input.type === "password") {
        input.type = "text";
        icon.src = "https://cdn-icons-png.flaticon.com/512/565/565655.png";
    } else {
        input.type = "password";
        icon.src = "https://cdn-icons-png.flaticon.com/512/709/709612.png";
    }
}

// Auto-focus OTP input when page loads
document.addEventListener('DOMContentLoaded', function() {
    const otpInput = document.querySelector('input[name="otp"]');
    if (otpInput) otpInput.focus();
});

// Restrict OTP input to digits only
document.addEventListener('DOMContentLoaded', function() {
    const otpInput = document.querySelector('input[name="otp"]');
    if (otpInput) {
        otpInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });
    }
});
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>