<?php
// Admin/admin-logout.php
session_start();

// ============================================
// CAPTURE SESSION DATA BEFORE CLEARING
// ============================================
$wasLoggedIn = isset($_SESSION['user_id']);
$userName = $_SESSION['fullname'] ?? 'Admin';
$userRole = $_SESSION['role'] ?? 'admin';
$userId = (int)($_SESSION['user_id'] ?? 0);

// ============================================
// LOG THE LOGOUT
// ============================================
if ($wasLoggedIn && $userId > 0) {
    // File log
    $logDir = __DIR__ . '/../logs/';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logEntry = sprintf(
        "[%s] LOGOUT | Admin: %s | Role: %s | IP: %s | UA: %s\n",
        date('Y-m-d H:i:s'),
        $userName,
        $userRole,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    );
    @file_put_contents($logDir . 'admin_auth.log', $logEntry, FILE_APPEND | LOCK_EX);

    // DB log
    try {
        include __DIR__ . '/../conn.php';

        $tblCheck = $conn->query("SHOW TABLES LIKE 'admin_logs'");
        if ($tblCheck && $tblCheck->num_rows > 0) {
            $stmt = $conn->prepare("
                INSERT INTO admin_logs (admin_id, action, details, ip_address, created_at) 
                VALUES (?, 'logout', 'Admin logged out', ?, NOW())
            ");
            if ($stmt) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $stmt->bind_param("is", $userId, $ip);
                $stmt->execute();
                $stmt->close();
            }
        }
        if (isset($conn) && $conn instanceof mysqli) $conn->close();
    } catch (Exception $e) {
        error_log("Logout log error: " . $e->getMessage());
    }
}

// ============================================
// CLEAR SESSION
// ============================================
$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

if (isset($_COOKIE['admin_remember'])) {
    setcookie('admin_remember', '', time() - 3600, '/');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out - FitSense Admin</title>
    <link rel="icon" type="image/png" href="../uploads/umak-gym-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Auto redirect after 3 seconds -->
    <meta http-equiv="refresh" content="3;url=admin-login.php">
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0a1628 0%, #00467a 50%, #005b9f 100%);
            padding: 30px 20px;
            position: relative;
            overflow: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: -200px; right: -200px;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(255,206,0,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }

        body::after {
            content: '';
            position: fixed;
            bottom: -200px; left: -200px;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(0,132,209,0.2) 0%, transparent 70%);
            border-radius: 50%;
        }

        .logout-card {
            background: white;
            border-radius: 24px;
            padding: 50px 45px;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 30px 80px rgba(0,0,0,0.35);
            position: relative;
            z-index: 2;
            animation: fadeInUp 0.5s ease;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand-mini {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 30px;
        }

        .brand-mini img {
            width: 52px; height: 52px;
            border-radius: 12px;
            object-fit: cover;
            background: white;
            padding: 3px;
            border: 2px solid var(--umak-yellow, #ffce00);
        }

        .brand-mini-text h3 {
            margin: 0;
            color: #005b9f;
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 1.5px;
        }

        .brand-mini-text p {
            margin: 4px 0 0;
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .logout-icon {
            width: 90px; height: 90px;
            margin: 0 auto 22px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2e7d32, #4ade80);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 44px;
            color: white;
            box-shadow: 0 15px 40px rgba(46,125,50,0.35);
            animation: pop 0.5s ease 0.15s backwards;
        }

        @keyframes pop {
            0% { transform: scale(0) rotate(-180deg); }
            50% { transform: scale(1.15) rotate(10deg); }
            100% { transform: scale(1) rotate(0); }
        }

        h1 {
            margin: 0 0 10px;
            color: #111827;
            font-size: 26px;
            font-weight: 900;
            letter-spacing: -0.5px;
        }

        .subtitle {
            margin: 0 0 28px;
            color: #6b7280;
            font-size: 14.5px;
            line-height: 1.6;
        }

        .countdown-info {
            background: #f0f7fc;
            border-left: 4px solid #005b9f;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 25px;
            font-size: 13.5px;
            color: #1e40af;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
        }

        .countdown-info strong {
            color: #005b9f;
            font-weight: 800;
            font-size: 16px;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            flex-direction: column;
        }

        .btn {
            padding: 15px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.25s ease;
            text-decoration: none;
            font-family: inherit;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .btn-primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 8px 24px rgba(0,91,159,0.35);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(0,91,159,0.45);
            color: white;
        }

        .btn-outline {
            background: white;
            color: #005b9f;
            border: 2px solid #005b9f;
        }

        .btn-outline:hover {
            background: #005b9f;
            color: white;
        }

        .footer-note {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #9ca3af;
            line-height: 1.6;
        }

        @media (max-width: 480px) {
            .logout-card { padding: 35px 25px; }
            h1 { font-size: 22px; }
            .logout-icon { width: 75px; height: 75px; font-size: 38px; }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css">
<link rel="stylesheet" href="/PBL/css/admin-content-icons.css"></head>
<body>

<div class="logout-card">

    <div class="brand-mini">
        <img src="../uploads/umak-gym-logo.png" alt="UMak Gym">
        <div class="brand-mini-text">
            <h3>FITSENSE</h3>
            <p>Admin Panel</p>
        </div>
    </div>

    <div class="logout-icon">
        <i class="bi bi-check-lg"></i>
    </div>

    <h1>You've Been Logged Out</h1>
    <p class="subtitle">
        Your admin session has been securely terminated.<br>
        Thank you for using FitSense.
    </p>

    <div class="countdown-info">
        <i class="bi bi-clock-history"></i>
        Redirecting to login in <strong id="countdown">3</strong> seconds...
    </div>

    <div class="btn-group">
        <a href="admin-login.php" class="btn btn-primary">
            <i class="bi bi-box-arrow-in-right"></i>
            Login Again
        </a>
        <a href="../login.php" class="btn btn-outline">
            <i class="bi bi-house-door-fill"></i>
            Go to Member Site
        </a>
    </div>

    <div class="footer-note">
        <i class="bi bi-shield-check"></i>
        For security, please close this tab if you're done.
    </div>

</div>

<script>
    // Live countdown
    let seconds = 3;
    const countdownEl = document.getElementById('countdown');

    const timer = setInterval(function() {
        seconds--;
        if (seconds >= 0) {
            countdownEl.textContent = seconds;
        }
        if (seconds <= 0) {
            clearInterval(timer);
            window.location.href = 'admin-login.php';
        }
    }, 1000);

    // Cancel redirect if user clicks a button
    document.querySelectorAll('.btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            clearInterval(timer);
        });
    });
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>