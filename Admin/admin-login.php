<?php
// Admin/admin-login.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/app_env.php';
require_once __DIR__ . '/../config/session_security.php';
require_once __DIR__ . '/../config/error_handling.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start(['use_strict_mode' => true, 'gc_maxlifetime' => 1800]);

include __DIR__ . '/../conn.php';
require __DIR__ . '/../vendor/autoload.php';

// ============================================
// SMTP CONFIGURATION
// ============================================
define('SMTP_USERNAME', app_env('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', app_env('SMTP_PASSWORD', ''));

// ============================================
// REDIRECT IF ALREADY LOGGED IN AS ADMIN
// ============================================
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
    if ($role === 'admin' || $role === 'super_admin') {
        header("Location: admin-dashboard.php");
        exit;
    }
}

$message = "";
$messageType = "error";

// ============================================
// EMAIL SENDER
// ============================================
function sendAdminOtpEmail($toEmail, $toName, $otp, $subject, $purposeText) {
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

        $mail->setFrom(SMTP_USERNAME, 'Umak Gym: Fitsenses');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;

        $mail->Body = "
            <div style='font-family: Arial, sans-serif; background:#f4f6f9; padding:40px 20px;'>
                <div style='max-width:560px; margin:0 auto; background:white; border-radius:16px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.08);'>

                    <div style='background:linear-gradient(135deg,#005b9f,#003a6b); padding:30px; text-align:center;'>
                        <h1 style='color:#ffce00; margin:0 0 8px; font-size:26px; letter-spacing:2px;'>FITSENSE</h1>
                        <p style='color:rgba(255,255,255,0.85); margin:0; font-size:13px; letter-spacing:2px; text-transform:uppercase;'>ADMIN PORTAL</p>
                    </div>

                    <div style='padding:40px 30px;'>
                        <h2 style='color:#111827; margin:0 0 16px; font-size:22px;'>Hello, Administrator!</h2>
                        <p style='color:#4b5563; font-size:15px; line-height:1.7; margin:0 0 16px;'>
                            Hi <strong>" . htmlspecialchars($toName) . "</strong>,
                        </p>
                        <p style='color:#4b5563; font-size:15px; line-height:1.7; margin:0 0 24px;'>
                            {$purposeText}
                        </p>

                        <div style='background:#f0f7fc; border:2px dashed #005b9f; padding:24px; text-align:center; border-radius:12px; margin:24px 0;'>
                            <p style='margin:0 0 8px; color:#6b7280; font-size:12px; text-transform:uppercase; letter-spacing:1.5px; font-weight:700;'>Verification Code</p>
                            <h1 style='letter-spacing:10px; color:#005b9f; margin:0; font-size:38px; font-weight:900; font-family:monospace;'>{$otp}</h1>
                        </div>

                        <p style='color:#6b7280; font-size:13px; line-height:1.6; margin:0 0 8px;'>
                            This code will expire in <strong>10 minutes</strong>.
                        </p>
                        <p style='color:#6b7280; font-size:13px; line-height:1.6; margin:0;'>
                            If you did not request this, you can safely ignore this email.
                        </p>
                    </div>

                    <div style='background:#f9fafb; padding:20px; text-align:center; border-top:1px solid #e5e7eb;'>
                        <p style='color:#9ca3af; font-size:12px; margin:0;'>
                            &copy; " . date('Y') . " Umak Gym: Fitsenses · All rights reserved
                        </p>
                    </div>

                </div>
            </div>
        ";

        $mail->AltBody = "Hello {$toName},\n\n{$purposeText}\n\nYour code: {$otp}\n\nThis code expires in 10 minutes.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        app_log_error('Admin OTP email failed to send.', [
            'to' => $toEmail,
            'subject' => $subject,
            'message' => $e->getMessage(),
            'smtp_error' => $mail->ErrorInfo ?? null,
        ]);
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
    // STEP 1: LOGIN (Username + Password)
    // ============================================
    if (isset($_POST['admin_login'])) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $allowedRoles = ['admin', 'super_admin'];

        if ($username === '' || $password === '') {
            $message = "Please complete all fields.";
        } else {
            $stmt = $conn->prepare("
                SELECT *
                FROM users
                WHERE (username = ? OR email = ?)
                AND role IN ('admin', 'super_admin')
                LIMIT 1
            ");
            $stmt->bind_param("ss", $username, $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();

                // Check account status
                if (isset($user['account_status']) && $user['account_status'] !== 'Active') {
                    $message = "Your account is " . strtolower($user['account_status']) . ". Please contact the super administrator.";
                }
                elseif (app_user_login_locked($user)) {
                    $remaining = app_login_lock_window_seconds() - (time() - strtotime($user['last_failed_login']));
                    $message = "This admin account is temporarily locked due to repeated failed login attempts. Please try again in " . ceil($remaining / 60) . " minute(s).";
                }
                // Verify password
                elseif (password_verify($password, $user['password'])) {
                    app_clear_failed_login($conn, (int)$user['id']);

                    // Generate OTP
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

                    // Different purpose text for first-time vs login
                    if ((int)$user['is_verified'] !== 1) {
                        $subject = "Verify Your FitSense Admin Account";
                        $purpose = "Your admin account is not yet verified. Use this code to verify your account:";
                    } else {
                        $subject = "Your FitSense Admin Login Code";
                        $purpose = "Your admin login verification code is:";
                    }

                    if (sendAdminOtpEmail($user['email'], $user['fullname'], $otp, $subject, $purpose)) {
                        $_SESSION['pending_admin_login_id'] = $user['id'];
                        $message = "Verification code sent to your email.";
                        $messageType = "success";
                    } else {
                        $message = app_safe_error_message('Unable to send verification email. Please try again.', 'email');
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
                    sleep(1);
                }
            } else {
                $message = "Admin account not found.";
                sleep(1);
            }

            $stmt->close();
        }
    }

    // ============================================
    // STEP 2: VERIFY OTP
    // ============================================
    if (isset($_POST['verify_admin_code'])) {
        $otp = trim($_POST['otp'] ?? '');

        if (!isset($_SESSION['pending_admin_login_id'])) {
            $message = "Session expired. Please login again.";
        } else {
            $pendingAdminId = (int)$_SESSION['pending_admin_login_id'];

            $stmt = $conn->prepare("
                SELECT *
                FROM users
                WHERE id = ?
                AND role IN ('admin', 'super_admin')
                LIMIT 1
            ");
            $stmt->bind_param("i", $pendingAdminId);
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
                    // Clear OTP + mark verified
                    $clearStmt = $conn->prepare("
                        UPDATE users 
                        SET two_factor_code = NULL, 
                            two_factor_expiry = NULL, 
                            is_verified = 1,
                            last_login = NOW(),
                            failed_attempts = 0,
                            last_failed_login = NULL
                        WHERE id = ?
                    ");
                    $clearStmt->bind_param("i", $pendingAdminId);
                    $clearStmt->execute();
                    $clearStmt->close();

                    // Regenerate session ID
                    session_regenerate_id(true);

                    // Store session data
                    $_SESSION['user_id']        = $user['id'];
                    $_SESSION['fullname']       = $user['fullname'];
                    $_SESSION['username']       = $user['username'];
                    $_SESSION['email']          = $user['email'];
                    $_SESSION['role']           = $user['role'];
                    $_SESSION['branch_id']      = $user['branch_id'] ?? 0;
                    $_SESSION['profile_image']  = $user['profile_image'] ?? null;
                    $_SESSION['permissions']    = $user['permissions'] ?? null;
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['login_time']     = time();

                    unset($_SESSION['pending_admin_login_id']);

                    // Force password change?
                    if (!empty($user['must_change_password'])) {
                        header("Location: admin-change-password.php");
                        exit();
                    }

                    // Redirect to admin dashboard
                    header("Location: admin-dashboard.php");
                    exit();

                } else {
                    $message = "Invalid or expired verification code.";
                }
            } else {
                $message = "Admin account not found.";
            }

            $stmt->close();
        }
    }

    // ============================================
    // STEP 3: RESEND OTP
    // ============================================
    if (isset($_POST['resend_admin_otp'])) {
        if (!isset($_SESSION['pending_admin_login_id'])) {
            $message = "Session expired. Please login again.";
        } else {
            $pendingAdminId = (int)$_SESSION['pending_admin_login_id'];

            $stmt = $conn->prepare("SELECT id, fullname, email FROM users WHERE id = ? AND role IN ('admin','super_admin') LIMIT 1");
            $stmt->bind_param("i", $pendingAdminId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();
                $newOtp = (string)random_int(100000, 999999);
                $newExpiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

                $update = $conn->prepare("
                    UPDATE users
                    SET two_factor_code = ?, two_factor_expiry = ?
                    WHERE id = ?
                ");
                $update->bind_param("ssi", $newOtp, $newExpiry, $pendingAdminId);
                $update->execute();
                $update->close();

                if (sendAdminOtpEmail($user['email'], $user['fullname'], $newOtp, "Your New FitSense Admin Login Code", "Your new admin login verification code is:")) {
                    $message = "A new verification code has been sent to your email.";
                    $messageType = "success";
                } else {
                    $message = "Unable to send verification email.";
                }
            } else {
                $message = "Admin account not found.";
            }

            $stmt->close();
        }
    }
    }
}

// ============================================
// DETERMINE WHICH FORM TO SHOW
// ============================================
$showOtpForm = isset($_SESSION['pending_admin_login_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - FitSense | UMak Gym</title>
    <link rel="icon" type="image/png" href="../uploads/umak-gym-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --umak-blue: #005b9f;
            --umak-blue-dark: #00467a;
            --umak-yellow: #ffce00;
            --umak-red: #d32f2f;
            --umak-green: #2e7d32;
            --border: #e5e7eb;
        }

        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ============================================
           BACKGROUND
           ============================================ */
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0a1628 0%, #00467a 50%, #005b9f 100%);
            position: relative;
            padding: 30px 20px;
        }

        body::before {
            content: '';
            position: fixed;
            top: -200px; right: -200px;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(255,206,0,0.15) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: fixed;
            bottom: -200px; left: -200px;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(0,132,209,0.2) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        /* ============================================
           LOGIN CARD
           ============================================ */
        .login-wrapper {
            width: 100%;
            max-width: 960px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0,0,0,0.4);
            position: relative;
            z-index: 2;
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ============================================
           LEFT: BRAND PANEL
           ============================================ */
        .login-brand-panel {
            background: linear-gradient(160deg, #005b9f 0%, #003a6b 100%);
            color: white;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .login-brand-panel::before {
            content: '';
            position: absolute;
            top: -100px; right: -100px;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(255,206,0,0.2) 0%, transparent 70%);
            border-radius: 50%;
        }

        .login-brand-panel::after {
            content: '';
            position: absolute;
            bottom: -80px; left: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(74,222,128,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .brand-header {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-header img {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            object-fit: cover;
            background: rgba(255,255,255,0.1);
            padding: 4px;
            border: 1px solid rgba(255,255,255,0.15);
        }

        .brand-header-text h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 900;
            letter-spacing: 1.5px;
            line-height: 1;
        }

        .brand-header-text p {
            margin: 4px 0 0;
            font-size: 11px;
            letter-spacing: 2px;
            text-transform: uppercase;
            opacity: 0.7;
        }

        .brand-message {
            position: relative;
            z-index: 2;
            margin: 40px 0;
        }

        .brand-message .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,206,0,0.15);
            border: 1px solid rgba(255,206,0,0.3);
            color: var(--umak-yellow);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 20px;
        }

        .brand-message h2 {
            margin: 0 0 16px;
            font-size: 32px;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -0.5px;
        }

        .brand-message h2 span { color: var(--umak-yellow); }

        .brand-message p {
            margin: 0;
            font-size: 14.5px;
            line-height: 1.7;
            color: rgba(255,255,255,0.8);
        }

        .brand-features {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .brand-feature {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            color: rgba(255,255,255,0.85);
            font-weight: 500;
        }

        .brand-feature-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: rgba(255,206,0,0.15);
            color: var(--umak-yellow);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        /* ============================================
           RIGHT: FORM PANEL
           ============================================ */
        .login-form-panel {
            padding: 50px 45px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-form-header { margin-bottom: 30px; }

        .login-form-header h3 {
            margin: 0 0 8px;
            font-size: 26px;
            font-weight: 900;
            color: #111827;
            letter-spacing: -0.5px;
        }

        .login-form-header p {
            margin: 0;
            font-size: 14px;
            color: #6b7280;
            line-height: 1.5;
        }

        /* Alerts */
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: shake 0.4s ease;
            line-height: 1.5;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-6px); }
            75% { transform: translateX(6px); }
        }

        .alert-error {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
            border-left: 4px solid var(--umak-red);
        }

        .alert-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border-left: 4px solid var(--umak-green);
        }

        .alert i { font-size: 18px; flex-shrink: 0; }

        /* OTP Info Box */
        .otp-info {
            background: #f0f7fc;
            border-left: 4px solid #005b9f;
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: 13.5px;
            color: #1e40af;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            line-height: 1.6;
        }

        .otp-info i {
            font-size: 18px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        /* Form */
        .form-group { margin-bottom: 18px; }

        .form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 800;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .form-label i { font-size: 14px; color: var(--umak-blue); }

        .input-wrapper { position: relative; }

        .input-wrapper .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 16px;
            pointer-events: none;
            transition: color 0.2s;
        }

        .input-wrapper .form-control {
            width: 100%;
            padding: 15px 16px 15px 48px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            font-weight: 500;
            color: #111827;
            background: #f9fafb;
            transition: all 0.25s ease;
            font-family: inherit;
            outline: none;
        }

        .input-wrapper .form-control:focus {
            border-color: var(--umak-blue);
            background: white;
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        .input-wrapper.has-toggle .form-control { padding-right: 50px; }

        .input-wrapper .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            font-size: 18px;
            padding: 4px;
            transition: color 0.2s;
        }

        .input-wrapper .password-toggle:hover { color: var(--umak-blue); }

        /* OTP Input Special Styling */
        .otp-input {
            width: 100%;
            padding: 20px 16px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 28px;
            font-weight: 900;
            letter-spacing: 12px;
            text-align: center;
            color: var(--umak-blue);
            background: #f9fafb;
            font-family: 'Courier New', monospace;
            transition: all 0.25s ease;
            outline: none;
        }

        .otp-input:focus {
            border-color: var(--umak-blue);
            background: white;
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        .otp-input::placeholder {
            color: #d1d5db;
            letter-spacing: 12px;
        }

        /* Submit Button */
        .btn-login {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, #005b9f 0%, #0084d1 100%);
            color: white;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.25s ease;
            box-shadow: 0 8px 24px rgba(0,91,159,0.35);
            font-family: inherit;
            margin-top: 8px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(0,91,159,0.45);
        }

        .btn-login i { font-size: 17px; }

        /* Secondary Button (Resend) */
        .btn-secondary {
            width: 100%;
            padding: 13px;
            border: 2px solid #005b9f;
            border-radius: 12px;
            background: transparent;
            color: #005b9f;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s ease;
            font-family: inherit;
            margin-top: 10px;
        }

        .btn-secondary:hover {
            background: #005b9f;
            color: white;
        }

        /* Form Row */
        .form-row-between {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .form-check { display: flex; align-items: center; gap: 8px; }

        .form-check input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--umak-blue);
            cursor: pointer;
            margin: 0;
        }

        .form-check label {
            font-size: 13px;
            color: #4b5563;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
        }

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 26px 0 18px;
            color: #9ca3af;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* Footer Links */
        .form-footer {
            text-align: center;
            font-size: 13px;
            color: #6b7280;
            line-height: 1.7;
        }

        .form-footer a {
            color: var(--umak-blue);
            text-decoration: none;
            font-weight: 700;
            transition: color 0.2s;
        }

        .form-footer a:hover { text-decoration: underline; }

        /* Security Note */
        .security-note {
            margin-top: 20px;
            padding: 12px 16px;
            background: #f0f7fc;
            border-left: 3px solid var(--umak-blue);
            border-radius: 8px;
            font-size: 12px;
            color: #1e40af;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.5;
        }

        .security-note i {
            font-size: 15px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 900px) {
            .login-wrapper {
                grid-template-columns: 1fr;
                max-width: 480px;
            }

            .login-brand-panel { padding: 32px 28px; }

            .brand-message { margin: 20px 0; }

            .brand-message h2 { font-size: 24px; }

            .brand-features { display: none; }
        }

        @media (max-width: 480px) {
            body { padding: 15px 10px; }
            .login-form-panel { padding: 32px 24px; }
            .login-form-header h3 { font-size: 22px; }
            .brand-header img { width: 46px; height: 46px; }
            .otp-input { font-size: 22px; letter-spacing: 8px; }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css">
<link rel="stylesheet" href="/PBL/css/admin-content-icons.css"></head>
<body>

<div class="login-wrapper">

    <!-- ============================================
         LEFT: BRAND PANEL
         ============================================ -->
    <div class="login-brand-panel">
        <div class="brand-header">
            <img src="../uploads/umak-gym-logo.png" alt="UMak Gym Logo">
            <div class="brand-header-text">
                <h1>FITSENSE</h1>
                <p>UMAK GYM</p>
            </div>
        </div>

        <div class="brand-message">
            <div class="badge">
                <i class="bi bi-shield-lock-fill"></i>
                Admin Portal
            </div>
            <h2>Welcome back, <span>Administrator</span></h2>
            <p>Access the management dashboard to oversee members, memberships, payments, and gym operations.</p>
        </div>

        <div class="brand-features">
            <div class="brand-feature">
                <div class="brand-feature-icon"><i class="bi bi-people-fill"></i></div>
                Manage members & memberships
            </div>
            <div class="brand-feature">
                <div class="brand-feature-icon"><i class="bi bi-credit-card-fill"></i></div>
                Process payments & transactions
            </div>
            <div class="brand-feature">
                <div class="brand-feature-icon"><i class="bi bi-bar-chart-fill"></i></div>
                View reports & analytics
            </div>
        </div>
    </div>

    <!-- ============================================
         RIGHT: FORM PANEL
         ============================================ -->
    <div class="login-form-panel">

        <div class="login-form-header">
            <h3>
                <?php
                if ($showOtpForm) {
                    echo "Verify Your Email";
                } else {
                    echo "Sign In";
                }
                ?>
            </h3>
            <p>
                <?php
                if ($showOtpForm) {
                    echo "Enter the 6-digit code we sent to your email address.";
                } else {
                    echo "Enter your administrator credentials to continue.";
                }
                ?>
            </p>
        </div>

        <!-- Alerts -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($showOtpForm): ?>
            <!-- ============================================
                 OTP VERIFICATION FORM
                 ============================================ -->
            <div class="otp-info">
                <i class="bi bi-envelope-check-fill"></i>
                <span>We've sent a 6-digit verification code to your email. Enter it below to sign in.</span>
            </div>

            <form method="POST" autocomplete="off">
                <?php echo app_csrf_field(); ?>
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-shield-lock-fill"></i>
                        Verification Code
                    </label>
                    <input type="text" name="otp" class="otp-input"
                           maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                           placeholder="000000" required autofocus>
                </div>

                <button type="submit" name="verify_admin_code" class="btn-login">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Verify & Sign In
                </button>

                <button type="submit" name="resend_admin_otp" class="btn-secondary" formnovalidate>
                    <i class="bi bi-arrow-clockwise"></i>
                    Resend Code
                </button>
            </form>

            <div class="divider">Secure Access</div>

            <div class="form-footer">
                Wrong account? <a href="admin-login.php?restart=1">Start over</a>
            </div>

        <?php else: ?>
            <!-- ============================================
                 LOGIN FORM
                 ============================================ -->
            <form method="POST" autocomplete="off">
                <?php echo app_csrf_field(); ?>
                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-person-fill"></i>
                        Username or Email
                    </label>
                    <div class="input-wrapper">
                        <input type="text" name="username" class="form-control"
                               placeholder="Enter your username or email"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               required autofocus autocomplete="username">
                        <i class="bi bi-person-fill input-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <i class="bi bi-lock-fill"></i>
                        Password
                    </label>
                    <div class="input-wrapper has-toggle">
                        <input type="password" name="password" id="passwordInput"
                               class="form-control"
                               placeholder="Enter your password"
                               required autocomplete="current-password">
                        <i class="bi bi-lock-fill input-icon"></i>
                        <button type="button" class="password-toggle"
                                onclick="togglePassword()" title="Show/Hide password">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="form-row-between">
                    <div class="form-check">
                        <input type="checkbox" name="remember" id="rememberMe">
                        <label for="rememberMe">Remember me</label>
                    </div>
                </div>

                <button type="submit" name="admin_login" class="btn-login">
                    <i class="bi bi-shield-lock-fill"></i>
                    Continue
                </button>
            </form>

            <div class="divider">Secure Access</div>

            <div class="form-footer">
                Not an admin? <a href="../login.php">Go to member login</a>
            </div>

            <div class="security-note">
                <i class="bi bi-shield-check"></i>
                <span>Two-factor email verification is required. You'll receive a 6-digit code to confirm your identity.</span>
            </div>
        <?php endif; ?>

    </div>

</div>

<script>
    // ============================================
    // PASSWORD VISIBILITY TOGGLE
    // ============================================
    function togglePassword() {
        const input = document.getElementById('passwordInput');
        const icon = document.getElementById('toggleIcon');

        if (!input) return;

        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    }

    // ============================================
    // AUTO-DISMISS ALERTS
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s, transform 0.5s';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function() { alert.style.display = 'none'; }, 500);
            }, 5000);
        });

        // OTP input: digits only + auto-focus
        const otpInput = document.querySelector('input[name="otp"]');
        if (otpInput) {
            otpInput.focus();
            otpInput.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
            });
        }
    });
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>