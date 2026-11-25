<?php
// login.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/config/app_env.php';
require_once __DIR__ . '/config/session_security.php';
require_once __DIR__ . '/config/error_handling.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start(['use_strict_mode' => true, 'gc_maxlifetime' => 1800]);

include 'conn.php';
require 'vendor/autoload.php';

define('SMTP_USERNAME', app_env('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', app_env('SMTP_PASSWORD', ''));

$message = "";
$messageType = "error";

function sendOtpEmail($toEmail, $toName, $otp, $subject, $purposeText) {
    if (SMTP_USERNAME === '' || SMTP_PASSWORD === '') {
        error_log('SMTP credentials are missing. Please configure SMTP_USERNAME and SMTP_PASSWORD.');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = app_env('SMTP_HOST', 'smtp.gmail.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = app_env('SMTP_ENCRYPTION', PHPMailer::ENCRYPTION_STARTTLS);
        $mail->Port       = (int)app_env('SMTP_PORT', 587);

        $mail->setFrom(SMTP_USERNAME, 'Umak Gym: Fitsense');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; color:#111; padding:20px;'>
                <h2 style='color:#005b9f;'>Umak Gym: Fitsense</h2>
                <p>Hello <strong>" . htmlspecialchars($toName) . "</strong>,</p>
                <p>{$purposeText}</p>
                <h1 style='letter-spacing:5px;color:#005b9f;'>{$otp}</h1>
                <p>This code will expire in 10 minutes.</p>
                <p>If you did not request this, you may ignore this email.</p>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        app_log_error('User OTP email failed to send.', [
            'to' => $toEmail,
            'subject' => $subject,
            'message' => $e->getMessage(),
        ]);
        return false;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfValid = app_validate_csrf_token($_POST['csrf_token'] ?? null);
    if (!$csrfValid) {
        $message = "This form has expired or is invalid. Please refresh the page and try again.";
        $messageType = "error";
    } else {
        if (isset($_POST['login_submit'])) {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = 'user';

            if ($username === '' || $password === '') {
                $message = "Please complete all fields.";
            } else {
                // Removed branch_id from the query
                $stmt = $conn->prepare("
                    SELECT u.*
                    FROM users u
                    WHERE u.username = ?
                    AND u.role = ?
                    LIMIT 1
                ");
                $stmt->bind_param("ss", $username, $role);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result && $result->num_rows > 0) {
                    $user = $result->fetch_assoc();

                    if (app_user_login_locked($user)) {
                        $remaining = app_login_lock_window_seconds() - (time() - strtotime($user['last_failed_login']));
                        $message = "This account is temporarily locked due to repeated failed login attempts. Please try again in " . ceil($remaining / 60) . " minute(s).";
                    } elseif (password_verify($password, $user['password'])) {
                        app_clear_failed_login($conn, (int)$user['id']);

                        $otp = (string)random_int(100000, 999999);
                        $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

                        $otpStmt = $conn->prepare("
                            UPDATE users 
                            SET two_factor_code = ?, two_factor_expiry = ?
                            WHERE id = ?
                        ");
                        $otpStmt->bind_param("ssi", $otp, $expiry, $user['id']);
                        $otpStmt->execute();
                        $otpStmt->close();

                        if ((int)$user['is_verified'] !== 1) {
                            $subject = "Verify Your Umak Gym Account";
                            $purpose = "Your account is not yet verified. Use this code to verify your account:";
                        } else {
                            $subject = "Your Umak Gym Login Verification Code";
                            $purpose = "Your login verification code is:";
                        }

                        if (sendOtpEmail($user['email'], $user['fullname'], $otp, $subject, $purpose)) {
                            $_SESSION['pending_login_user_id'] = $user['id'];
                            $message = "Verification code sent to your email.";
                            $messageType = "success";
                        } else {
                            $message = app_safe_error_message('Unable to send verification email.', 'email');
                        }
                    } else {
                        app_record_failed_login($conn, (int)$user['id']);

                        $updatedUser = $conn->query("SELECT failed_attempts, last_failed_login FROM users WHERE id = " . (int)$user['id'] . " LIMIT 1");
                        $failedState = $updatedUser ? $updatedUser->fetch_assoc() : null;
                        $remaining = app_login_attempt_limit() - (int)($failedState['failed_attempts'] ?? 0);

                        if ($remaining <= 0) {
                            $message = "Too many failed login attempts. This account is temporarily locked.";
                        } else {
                            $message = "Incorrect password. " . max(1, $remaining) . " attempts remaining before lockout.";
                        }
                    }
                } else {
                    $message = "User not found.";
                }

                $stmt->close();
            }
        }

        if (isset($_POST['verify_login_code'])) {
        $otp = trim($_POST['otp'] ?? '');

        if (!isset($_SESSION['pending_login_user_id'])) {
            $message = "Session expired. Please login again.";
        } else {
            $pendingUserId = (int)$_SESSION['pending_login_user_id'];

            // Removed branch join
            $stmt = $conn->prepare("
                SELECT u.*
                FROM users u
                WHERE u.id = ?
                AND u.role = 'user'
                LIMIT 1
            ");
            $stmt->bind_param("i", $pendingUserId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();

                if ($user['two_factor_code'] === $otp && strtotime($user['two_factor_expiry']) >= time()) {
                    $clearStmt = $conn->prepare("
                        UPDATE users 
                        SET two_factor_code = NULL, two_factor_expiry = NULL, is_verified = 1, failed_attempts = 0, last_failed_login = NULL
                        WHERE id = ?
                    ");
                    $clearStmt->bind_param("i", $pendingUserId);
                    $clearStmt->execute();
                    $clearStmt->close();

                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['fullname'] = $user['fullname'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];

                    unset($_SESSION['pending_login_user_id']);

                    header("Location: user-dashboard.php");
                    exit();
                } else {
                    $message = "Invalid or expired verification code.";
                }
            } else {
                $message = "User account not found.";
            }

            $stmt->close();
        }
    }

    if (isset($_POST['send_reset_code'])) {
        $identifier = trim($_POST['identifier'] ?? '');

        if ($identifier === '') {
            $message = "Please enter your username or email.";
        } else {
            $stmt = $conn->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND role = 'user' LIMIT 1");
            $stmt->bind_param("ss", $identifier, $identifier);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();

                if (empty($user['email'])) {
                    $message = "This account has no email address.";
                } else {
                    $otp = (string)random_int(100000, 999999);
                    $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

                    $otpStmt = $conn->prepare("
                        UPDATE users 
                        SET two_factor_code = ?, two_factor_expiry = ?
                        WHERE id = ?
                    ");
                    $otpStmt->bind_param("ssi", $otp, $expiry, $user['id']);
                    $otpStmt->execute();
                    $otpStmt->close();

                    if (sendOtpEmail($user['email'], $user['fullname'], $otp, "Umak Gym Password Reset Code", "Your password reset verification code is:")) {
                        $_SESSION['pending_reset_user_id'] = $user['id'];
                        $message = "Password reset code sent to your email.";
                        $messageType = "success";
                    } else {
                        $message = app_safe_error_message('Unable to send reset code.', 'email');
                    }
                }
            } else {
                $message = "No user account found with that username or email.";
            }

            $stmt->close();
        }
    }

    if (isset($_POST['reset_password'])) {
        $otp = trim($_POST['reset_otp'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!isset($_SESSION['pending_reset_user_id'])) {
            $message = "Reset session expired. Please try again.";
        } elseif ($newPassword !== $confirmPassword) {
            $message = "Passwords do not match.";
        } elseif (strlen($newPassword) < 8) {
            $message = "Password must be at least 8 characters.";
        } else {
            $resetUserId = (int)$_SESSION['pending_reset_user_id'];

            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'user' LIMIT 1");
            $stmt->bind_param("i", $resetUserId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();

                if ($user['two_factor_code'] === $otp && strtotime($user['two_factor_expiry']) >= time()) {
                    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

                    $update = $conn->prepare("
                        UPDATE users 
                        SET password = ?, two_factor_code = NULL, two_factor_expiry = NULL
                        WHERE id = ?
                    ");
                    $update->bind_param("si", $hashed, $resetUserId);

                    if ($update->execute()) {
                        unset($_SESSION['pending_reset_user_id']);
                        $message = "Password reset successfully. You may now login.";
                        $messageType = "success";
                    } else {
                        $message = "Unable to reset password.";
                    }

                    $update->close();
                } else {
                    $message = "Invalid or expired reset code.";
                }
            } else {
                $message = "User account not found.";
            }

            $stmt->close();
        }
    }
    }
}

$showOtpForm = isset($_SESSION['pending_login_user_id']);
$showResetForm = isset($_SESSION['pending_reset_user_id']);
$showForgot = isset($_GET['forgot']) || $showResetForm;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Umak Gym: Fitsense - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

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
            background: #ffce00; /* Yellow */
            border-bottom: 4px solid #005b9f; /* Blue */
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
            color: #000000; /* Black */
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
            color: #005b9f; /* Blue */
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
            align-items: center;
            padding: 45px 20px;
        }

        .auth-card {
            width: 100%;
            max-width: 470px;
            background: #ffffff; /* White */
            border: 2px solid #e0e0e0;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border-top: 6px solid #005b9f; /* Blue */
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
            color: #005b9f; /* Blue */
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
            color: #333333; /* Black */
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: .6px;
        }

        input {
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

        input:focus {
            border-color: #005b9f; /* Blue */
            box-shadow: 0 0 0 3px rgba(0, 91, 159, 0.15);
        }

        .auth-btn {
            width: 100%;
            padding: 14px;
            border: 2px solid #000000; /* Black */
            border-radius: 10px;
            background: #ffce00; /* Yellow */
            color: #000000; /* Black */
            font-weight: 900;
            cursor: pointer;
            margin-top: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s;
        }

        .auth-btn:hover {
            background: #005b9f; /* Blue */
            color: #ffffff;
            border-color: #005b9f;
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
            color: #005b9f; /* Blue */
            text-decoration: none;
            font-weight: 700;
        }
        
        .links a:hover {
            text-decoration: underline;
        }

        .main-footer {
            background: #000000; /* Black */
            border-top: 4px solid #ffce00; /* Yellow */
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
            color: #ffce00; /* Yellow */
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
            <div class="auth-brand-title">Umak Gym: Fitsense</div>
            <div class="auth-brand-sub">FITSENSE GYM SYSTEM</div>
        </div>
    </a>

    <nav class="auth-header-links">
        <a href="login.php" class="active">Login</a>
        <a href="user-register.php">Register</a>
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
                if ($showOtpForm) echo "Email Verification";
                elseif ($showForgot) echo "Forgot Password";
                else echo "Login Account";
                ?>
            </h2>

            <?php if (!empty($message)): ?>
                <div class="alert <?php echo htmlspecialchars($messageType); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($showOtpForm): ?>
                <form method="POST">
                    <?php echo app_csrf_field(); ?>
                    <div class="form-group">
                        <label><i class="bi bi-shield-check"></i> Enter Verification Code</label>
                        <input type="text" name="otp" maxlength="6" placeholder="6-digit code" required>
                    </div>
                    <button type="submit" name="verify_login_code" class="auth-btn"><i class="bi bi-check2-circle"></i> Verify Code</button>
                </form>

            <?php elseif ($showForgot): ?>
                <?php if (!$showResetForm): ?>
                    <form method="POST">
                        <?php echo app_csrf_field(); ?>
                        <div class="form-group">
                            <label><i class="bi bi-person-badge"></i> Username or Email</label>
                            <input type="text" name="identifier" placeholder="Enter username or email" required>
                        </div>
                        <button type="submit" name="send_reset_code" class="auth-btn"><i class="bi bi-envelope-at"></i> Send Reset Code</button>
                    </form>
                <?php else: ?>
                    <form method="POST">
                        <?php echo app_csrf_field(); ?>
                        <div class="form-group">
                            <label><i class="bi bi-shield-check"></i> Reset Code</label>
                            <input type="text" name="reset_otp" maxlength="6" placeholder="6-digit code" required>
                        </div>

                        <div class="form-group">
                            <label><i class="bi bi-lock"></i> New Password</label>
                            <input type="password" name="new_password" placeholder="Minimum 8 characters" required>
                        </div>

                        <div class="form-group">
                            <label><i class="bi bi-lock-fill"></i> Confirm Password</label>
                            <input type="password" name="confirm_password" required>
                        </div>

                        <button type="submit" name="reset_password" class="auth-btn"><i class="bi bi-arrow-repeat"></i> Reset Password</button>
                    </form>
                <?php endif; ?>

            <?php else: ?>
                <form method="POST">
                    <?php echo app_csrf_field(); ?>
                    <div class="form-group">
                        <label><i class="bi bi-person"></i> Username</label>
                        <input type="text" name="username" placeholder="Enter username" required>
                    </div>

                    <div class="form-group">
                        <label><i class="bi bi-lock"></i> Password</label>
                        <input type="password" name="password" placeholder="Enter password" required>
                    </div>

                    <!-- Branch selection removed entirely -->

                    <button type="submit" name="login_submit" class="auth-btn"><i class="bi bi-box-arrow-in-right"></i> Login</button>
                </form>
            <?php endif; ?>

            <div class="links">
                <?php if (!$showForgot && !$showOtpForm): ?>
                    <a href="login.php?forgot=1">Forgot Password?</a><br>
                <?php endif; ?>
                Don't have an account? <a href="user-register.php">Create one here</a><br>
            </div>
        </div>
    </main>

    <footer class="main-footer">
        <div class="footer-container">
            <div class="footer-section">
                <h4>Umak Gym: Fitsense</h4>
                <p>Premium fitness experience with modern equipment, expert coaching, and a strong fitness community.</p>
            </div>

            <div class="footer-section">
                <h4>Quick Links</h4>
                <a href="login.php">Login</a>
                <a href="user-register.php">Register</a>
                <a href="user-franchise.php">Franchise</a>
                <a href="Admin/admin-login.php">Admin Portal</a>
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
            © <?php echo date("Y"); ?> Umak Gym: Fitsense. All rights reserved.
        </div>
    </footer>
</div>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>