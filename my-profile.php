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

// ============================================
// HANDLE PROFILE UPDATE
// ============================================
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $message = 'This form has expired or is invalid. Please refresh the page and try again.';
        $messageType = 'error';
    } else {
    $action = $_POST['action'];

    // ============================================
    // UPDATE PERSONAL INFO
    // ============================================
    if ($action === 'update_info') {
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $dob = $_POST['date_of_birth'] ?? null;
        $gender = $_POST['gender'] ?? null;
        $address = trim($_POST['address'] ?? '');
        $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
        $emergencyPhone = trim($_POST['emergency_contact_phone'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        $errors = [];

        if (empty($fullname) || strlen($fullname) < 2) {
            $errors[] = 'Please enter your full name.';
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        // Check if email is already used by another user
        if (empty($errors)) {
            $result = safeQuery($conn,
                "SELECT id FROM users WHERE email = ? AND id != ?",
                "si", [$email, $userId]
            );
            if ($result && $result->num_rows > 0) {
                $errors[] = 'This email is already in use by another account.';
            }
        }

        if (empty($errors)) {
            $stmt = $conn->prepare(
                "UPDATE users SET 
                    fullname = ?, email = ?, phone = ?, date_of_birth = ?, 
                    gender = ?, address = ?, emergency_contact_name = ?, 
                    emergency_contact_phone = ?, bio = ?, updated_at = NOW()
                 WHERE id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("sssssssssi",
                    $fullname, $email, $phone, $dob, $gender,
                    $address, $emergencyName, $emergencyPhone, $bio, $userId
                );
                if ($stmt->execute()) {
                    $message = 'Profile updated successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update profile. Please try again.';
                    $messageType = 'error';
                }
                $stmt->close();
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
    }

    // ============================================
    // CHANGE PASSWORD
    // ============================================
    elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $errors = [];

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $errors[] = 'All password fields are required.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            $errors[] = 'Password must contain uppercase, lowercase, and numbers.';
        }

        if (empty($errors)) {
            $result = safeQuery($conn,
                "SELECT password FROM users WHERE id = ? LIMIT 1",
                "i", [$userId]
            );
            if ($result && $row = $result->fetch_assoc()) {
                if (!password_verify($currentPassword, $row['password'])) {
                    $errors[] = 'Current password is incorrect.';
                }
            } else {
                $errors[] = 'User not found.';
            }
        }

        if (empty($errors)) {
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $conn->prepare(
                "UPDATE users SET password = ?, password_last_changed = NOW() WHERE id = ?"
            );
            if ($stmt) {
                $stmt->bind_param("si", $newHash, $userId);
                if ($stmt->execute()) {
                    $message = 'Password changed successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to change password.';
                    $messageType = 'error';
                }
                $stmt->close();
            }
        } else {
            $message = implode('<br>', $errors);
            $messageType = 'error';
        }
    }

    // ============================================
    // UPLOAD PROFILE PICTURE
    // ============================================
    elseif ($action === 'upload_avatar') {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $maxSize = 3 * 1024 * 1024; // 3MB

            if (!in_array($file['type'], $allowed)) {
                $message = 'Only JPG, PNG, WEBP, or GIF files allowed.';
                $messageType = 'error';
            } elseif ($file['size'] > $maxSize) {
                $message = 'Image must be smaller than 3MB.';
                $messageType = 'error';
            } else {
                $uploadDir = __DIR__ . '/uploads/avatars/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'avatar_' . $userId . '_' . time() . '.' . strtolower($ext);
                $filepath = $uploadDir . $filename;
                $dbPath = 'uploads/avatars/' . $filename;

                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    // Delete old avatar
                    $result = safeQuery($conn, "SELECT profile_image FROM users WHERE id = ?", "i", [$userId]);
                    if ($result && $row = $result->fetch_assoc()) {
                        if (!empty($row['profile_image']) && file_exists(__DIR__ . '/' . $row['profile_image'])) {
                            @unlink(__DIR__ . '/' . $row['profile_image']);
                        }
                    }

                    $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("si", $dbPath, $userId);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $message = 'Profile picture updated!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to upload image.';
                    $messageType = 'error';
                }
            }
        }
    }
    }
}

// ============================================
// FETCH USER DATA
// ============================================
$userData = null;
$stmtUser = $conn->prepare(
    "SELECT id, fullname, username, email, profile_image, 
            phone, date_of_birth, gender, address, 
            emergency_contact_name, emergency_contact_phone, bio,
            COALESCE(account_status, status) AS status, created_at, last_login, password_last_changed
     FROM users WHERE id = ? LIMIT 1"
);
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
// FETCH MEMBERSHIP INFO
// ============================================
$membership = null;
if (tableExists($conn, 'memberships')) {
    $result = safeQuery($conn,
        "SELECT membership_type AS plan_name, status, start_date, end_date AS expiry_date 
         FROM memberships 
         WHERE user_id = ? AND status = 'Active' AND end_date >= CURDATE()
         ORDER BY end_date DESC LIMIT 1",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $membership = $row;
    }
}

// ============================================
// FETCH PROFILE STATS
// ============================================
$profileStats = [
    'total_visits' => 0,
    'total_workouts' => 0,
    'days_active' => 0,
    'achievements' => 0,
];

if (tableExists($conn, 'attendance')) {
    $result = safeQuery($conn,
        "SELECT COUNT(*) AS total FROM attendance WHERE user_id = ?",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $profileStats['total_visits'] = (int)$row['total'];
    }
}

if (tableExists($conn, 'workout_completions')) {
    $result = safeQuery($conn,
        "SELECT COUNT(DISTINCT completion_date) AS total FROM workout_completions WHERE user_id = ?",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) {
        $profileStats['total_workouts'] = (int)$row['total'];
    }
}

if (!empty($userData['created_at'])) {
    $profileStats['days_active'] = max(1, (int)((time() - strtotime($userData['created_at'])) / 86400));
}

$profileStats['achievements'] = 
    ($profileStats['total_workouts'] >= 10 ? 1 : 0) +
    ($profileStats['total_workouts'] >= 50 ? 1 : 0) +
    ($profileStats['total_visits'] >= 20 ? 1 : 0) +
    (!empty($membership) ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - UMak Gym: Fitsense</title>
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
        .container { max-width: 1200px; margin: 30px auto; padding: 0 25px; }

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
           PROFILE HERO
           ============================================ */
        .profile-hero {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 24px;
            padding: 40px;
            color: white;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0,91,159,0.25);
        }

        .profile-hero::before {
            content: '';
            position: absolute;
            top: -100px; right: -100px;
            width: 350px; height: 350px;
            background: radial-gradient(circle, rgba(255,206,0,0.18) 0%, transparent 70%);
            border-radius: 50%;
        }

        .profile-hero::after {
            content: '';
            position: absolute;
            bottom: -80px; left: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(74,222,128,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .profile-hero-content {
            display: flex;
            gap: 30px;
            align-items: center;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }

        .profile-avatar-wrapper {
            position: relative;
            flex-shrink: 0;
        }

        .profile-avatar-large {
            width: 130px;
            height: 130px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ffce00, #ffb800);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 52px;
            font-weight: 900;
            color: #333;
            border: 5px solid rgba(255,255,255,0.15);
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            overflow: hidden;
            position: relative;
        }

        .profile-avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-upload-btn {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: white;
            color: var(--umak-blue);
            border: 3px solid var(--umak-blue);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .avatar-upload-btn:hover {
            background: var(--umak-blue);
            color: white;
            transform: scale(1.1);
        }

        .profile-hero-info { flex: 1; min-width: 250px; }

        .profile-hero-name {
            font-size: 36px;
            font-weight: 800;
            margin: 0 0 8px;
            letter-spacing: -0.8px;
            line-height: 1.1;
        }

        .profile-hero-username {
            font-size: 15px;
            color: rgba(255,255,255,0.75);
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .profile-hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 13px;
        }

        .profile-hero-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255,255,255,0.85);
            font-weight: 600;
        }

        .profile-hero-meta-item i {
            color: var(--umak-yellow);
            font-size: 15px;
        }

        .profile-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .profile-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .profile-badge.active { background: rgba(74,222,128,0.2); color: #4ade80; border: 1px solid rgba(74,222,128,0.4); }
        .profile-badge.premium { background: rgba(255,206,0,0.2); color: var(--umak-yellow); border: 1px solid rgba(255,206,0,0.4); }
        .profile-badge.student { background: rgba(139,92,246,0.2); color: #a78bfa; border: 1px solid rgba(139,92,246,0.4); }

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

        .stat-box-icon.blue { background: linear-gradient(135deg, rgba(0,91,159,0.12), rgba(0,132,209,0.18)); color: var(--umak-blue); }
        .stat-box-icon.green { background: linear-gradient(135deg, rgba(46,125,50,0.12), rgba(74,222,128,0.18)); color: var(--umak-green); }
        .stat-box-icon.orange { background: linear-gradient(135deg, rgba(245,158,11,0.12), rgba(251,191,36,0.18)); color: #b45309; }
        .stat-box-icon.purple { background: linear-gradient(135deg, rgba(139,92,246,0.12), rgba(167,139,250,0.18)); color: #7c3aed; }

        .stat-box-info { flex: 1; min-width: 0; }
        .stat-box-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .stat-box-value { font-size: 24px; font-weight: 800; color: #111827; line-height: 1.1; letter-spacing: -0.5px; }
        .stat-box-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }

        /* ============================================
           MAIN GRID
           ============================================ */
        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .panel {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 28px 30px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
            gap: 12px;
            flex-wrap: wrap;
        }

        .panel-title {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .panel-title i { font-size: 20px; }

        /* ============================================
           FORM STYLING
           ============================================ */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group.full { grid-column: 1 / -1; }

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

        .form-control:disabled {
            background: #f9fafb;
            color: var(--text-muted);
            cursor: not-allowed;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 80px;
            line-height: 1.5;
        }

        select.form-control {
            cursor: pointer;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 15px;
            pointer-events: none;
        }

        .input-with-icon .form-control { padding-left: 42px; }

        /* ============================================
           BUTTONS
           ============================================ */
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 13.5px;
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
            letter-spacing: 0.3px;
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

        .btn-outline {
            background: white;
            color: var(--umak-blue);
            border: 2px solid var(--umak-blue);
        }

        .btn-outline:hover {
            background: var(--umak-blue);
            color: white;
        }

        .btn-yellow {
            background: linear-gradient(135deg, #ffce00, #ffb800);
            color: #333;
            box-shadow: 0 4px 12px rgba(255,206,0,0.3);
        }

        .btn-yellow:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255,206,0,0.4);
        }

        .btn i { font-size: 15px; }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        /* ============================================
           INFO LIST (Sidebar)
           ============================================ */
        .info-list {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 12px;
            border-bottom: 1px dashed var(--border);
            gap: 12px;
        }

        .info-item:last-child { border-bottom: none; }

        .info-label {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .info-label i {
            font-size: 14px;
            color: var(--umak-blue);
        }

        .info-value {
            font-size: 13.5px;
            font-weight: 700;
            color: #111827;
            text-align: right;
            word-break: break-word;
        }

        .info-value.status-active { color: var(--umak-green); }
        .info-value.status-premium { color: #b45309; }

        /* ============================================
           PASSWORD SECTION
           ============================================ */
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 16px;
            padding: 4px;
            transition: color 0.2s;
        }

        .password-toggle:hover { color: var(--umak-blue); }

        .input-with-icon.has-toggle .form-control { padding-right: 42px; }

        /* ============================================
           DANGER ZONE
           ============================================ */
        .danger-zone {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border-radius: 16px;
            padding: 20px 24px;
            border: 2px dashed rgba(211,47,47,0.3);
            margin-top: 20px;
        }

        .danger-zone-header {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 800;
            color: #991b1b;
            margin-bottom: 10px;
        }

        .danger-zone-header i { font-size: 18px; }

        .danger-zone p {
            margin: 0 0 16px;
            font-size: 13px;
            color: #b91c1c;
            line-height: 1.6;
        }

        .btn-danger {
            background: linear-gradient(135deg, #d32f2f, #ef4444);
            color: white;
            box-shadow: 0 4px 12px rgba(211,47,47,0.25);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(211,47,47,0.35);
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
            .profile-hero { padding: 26px 22px; }
            .profile-hero-content { gap: 20px; }
            .profile-avatar-large { width: 95px; height: 95px; font-size: 38px; }
            .profile-hero-name { font-size: 26px; }
            .profile-hero-meta { flex-direction: column; gap: 8px; }
            .panel { padding: 22px 18px; }
            .form-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 576px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
            .form-actions .btn { flex: 1; }
            .profile-hero { padding: 22px 18px; }
            .profile-avatar-large { width: 80px; height: 80px; font-size: 32px; }
            .profile-hero-name { font-size: 22px; }
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
        <a href="payments.php" class="nav-item">
            <i class="bi bi-credit-card-fill"></i><span>Payments & Billing</span>
        </a>
        <a href="notifications.php" class="nav-item">
            <i class="bi bi-bell-fill"></i><span>Notifications</span>
            <?php if ($unreadNotifCount > 0): ?>
                <span class="badge-count"><?= $unreadNotifCount ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section-title">Account</div>
        <a href="my-profile.php" class="nav-item active">
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
                <h2>My Profile</h2>
                <p class="subtitle">Manage your personal information</p>
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
            <button class="avatar-btn">
                <div class="avatar">
                    <?php if (!empty($userAvatar)): ?>
                        <img src="<?= htmlspecialchars($userAvatar) ?>" alt="" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                    <?php else: ?>
                        <?= htmlspecialchars($userInitial) ?>
                    <?php endif; ?>
                </div>
            </button>
        </div>
    </header>

    <!-- PAGE CONTENT -->
    <main class="container">

        <h1 class="page-title">
            <i class="bi bi-person-circle"></i>
            My Profile
        </h1>
        <p class="page-subtitle">View and update your personal information, security settings, and account preferences.</p>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>" style="font-size: 20px;"></i>
                <div><?= $message ?></div>
            </div>
        <?php endif; ?>

        <!-- ============================================
             PROFILE HERO
             ============================================ -->
        <div class="profile-hero">
            <div class="profile-hero-content">
                <div class="profile-avatar-wrapper">
                    <div class="profile-avatar-large">
                        <?php if (!empty($userAvatar)): ?>
                            <img src="<?= htmlspecialchars($userAvatar) ?>" alt="<?= htmlspecialchars($displayName) ?>">
                        <?php else: ?>
                            <?= htmlspecialchars($userInitial) ?>
                        <?php endif; ?>
                    </div>
                    <form method="POST" enctype="multipart/form-data" style="display: contents;" id="avatarForm">
                        <input type="hidden" name="action" value="upload_avatar">
                        <?php echo app_csrf_field(); ?>
                        <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display: none;">
                        <label class="avatar-upload-btn" for="avatarInput" title="Change profile picture">
                            <i class="bi bi-camera-fill"></i>
                        </label>
                    </form>
                </div>

                <div class="profile-hero-info">
                    <h2 class="profile-hero-name"><?= htmlspecialchars($displayName) ?></h2>
                    <div class="profile-hero-username">
                        <i class="bi bi-at"></i>
                        <?= htmlspecialchars($userData['username'] ?? 'user') ?>
                    </div>

                    <div class="profile-hero-meta">
                        <div class="profile-hero-meta-item">
                            <i class="bi bi-envelope-fill"></i>
                            <?= htmlspecialchars($userEmail) ?>
                        </div>
                        <?php if (!empty($userData['phone'])): ?>
                            <div class="profile-hero-meta-item">
                                <i class="bi bi-telephone-fill"></i>
                                <?= htmlspecialchars($userData['phone']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="profile-hero-meta-item">
                            <i class="bi bi-calendar-check-fill"></i>
                            Joined <?= !empty($userData['created_at']) ? date('M Y', strtotime($userData['created_at'])) : 'Recently' ?>
                        </div>
                    </div>

                    <div class="profile-badges">
                        <span class="profile-badge active">
                            <i class="bi bi-check-circle-fill"></i>
                            <?= ucfirst($userData['status'] ?? 'Active') ?>
                        </span>
                        <?php if ($membership): ?>
                            <span class="profile-badge premium">
                                <i class="bi bi-star-fill"></i>
                                <?= htmlspecialchars($membership['plan_name']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================
             STATS ROW
             ============================================ -->
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-box-icon blue">
                    <i class="bi bi-calendar-check-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Total Visits</div>
                    <div class="stat-box-value"><?= $profileStats['total_visits'] ?></div>
                    <div class="stat-box-sub">gym check-ins</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon green">
                    <i class="bi bi-activity"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Workouts</div>
                    <div class="stat-box-value"><?= $profileStats['total_workouts'] ?></div>
                    <div class="stat-box-sub">completed</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon orange">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Days Active</div>
                    <div class="stat-box-value"><?= $profileStats['days_active'] ?></div>
                    <div class="stat-box-sub">since joining</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon purple">
                    <i class="bi bi-trophy-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Achievements</div>
                    <div class="stat-box-value"><?= $profileStats['achievements'] ?></div>
                    <div class="stat-box-sub">badges earned</div>
                </div>
            </div>
        </div>

        <!-- ============================================
             MAIN GRID
             ============================================ -->
        <div class="main-grid">
            <!-- LEFT: Personal Information Form -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-person-vcard"></i>
                        Personal Information
                    </h3>
                    <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;">
                        <i class="bi bi-info-circle"></i> Required fields marked with *
                    </span>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_info">
                    <?php echo app_csrf_field(); ?>

                    <div class="form-grid">
                        <!-- Full Name -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-person-fill"></i>
                                Full Name <span class="required">*</span>
                            </label>
                            <input type="text" name="fullname" class="form-control"
                                   value="<?= htmlspecialchars($userData['fullname'] ?? '') ?>" required>
                        </div>

                        <!-- Username (readonly) -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-at"></i>
                                Username
                            </label>
                            <input type="text" class="form-control"
                                   value="<?= htmlspecialchars($userData['username'] ?? '') ?>" disabled>
                        </div>

                        <!-- Email -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-envelope-fill"></i>
                                Email <span class="required">*</span>
                            </label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($userData['email'] ?? '') ?>" required>
                        </div>

                        <!-- Phone -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-telephone-fill"></i>
                                Phone Number
                            </label>
                            <input type="tel" name="phone" class="form-control"
                                   value="<?= htmlspecialchars($userData['phone'] ?? '') ?>"
                                   placeholder="+63 9XX XXX XXXX">
                        </div>

                        <!-- Date of Birth -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-calendar-heart-fill"></i>
                                Date of Birth
                            </label>
                            <input type="date" name="date_of_birth" class="form-control"
                                   value="<?= htmlspecialchars($userData['date_of_birth'] ?? '') ?>">
                        </div>

                        <!-- Gender -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-gender-ambiguous"></i>
                                Gender
                            </label>
                            <select name="gender" class="form-control">
                                <option value="">Prefer not to say</option>
                                <option value="Male" <?= ($userData['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= ($userData['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                <option value="Other" <?= ($userData['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>

                        <!-- Address -->
                        <div class="form-group full">
                            <label class="form-label">
                                <i class="bi bi-geo-alt-fill"></i>
                                Address
                            </label>
                            <input type="text" name="address" class="form-control"
                                   value="<?= htmlspecialchars($userData['address'] ?? '') ?>"
                                   placeholder="Street, Barangay, City">
                        </div>

                        <!-- Emergency Contact Name -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-person-plus-fill"></i>
                                Emergency Contact Name
                            </label>
                            <input type="text" name="emergency_contact_name" class="form-control"
                                   value="<?= htmlspecialchars($userData['emergency_contact_name'] ?? '') ?>"
                                   placeholder="Full name">
                        </div>

                        <!-- Emergency Contact Phone -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="bi bi-telephone-plus-fill"></i>
                                Emergency Contact Phone
                            </label>
                            <input type="tel" name="emergency_contact_phone" class="form-control"
                                   value="<?= htmlspecialchars($userData['emergency_contact_phone'] ?? '') ?>"
                                   placeholder="+63 9XX XXX XXXX">
                        </div>

                        <!-- Bio -->
                        <div class="form-group full">
                            <label class="form-label">
                                <i class="bi bi-chat-quote-fill"></i>
                                Bio / Fitness Goals
                            </label>
                            <textarea name="bio" class="form-control" rows="3"
                                      placeholder="Tell us about your fitness goals and motivation..."><?= htmlspecialchars($userData['bio'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-outline">
                            <i class="bi bi-arrow-clockwise"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle-fill"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- RIGHT: Account Info -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="bi bi-info-circle-fill"></i>
                        Account Info
                    </h3>
                </div>

                <div class="info-list">
                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-person-badge-fill"></i>
                            User ID
                        </span>
                        <span class="info-value">#<?= str_pad($userId, 5, '0', STR_PAD_LEFT) ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-circle-fill"></i>
                            Status
                        </span>
                        <span class="info-value status-active">
                            <?= ucfirst($userData['status'] ?? 'Active') ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-calendar-plus-fill"></i>
                            Joined
                        </span>
                        <span class="info-value">
                            <?= !empty($userData['created_at']) ? date('M j, Y', strtotime($userData['created_at'])) : 'N/A' ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-clock-history"></i>
                            Last Login
                        </span>
                        <span class="info-value">
                            <?= !empty($userData['last_login']) ? date('M j, Y g:i A', strtotime($userData['last_login'])) : 'First session' ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-shield-lock-fill"></i>
                            Password Changed
                        </span>
                        <span class="info-value">
                            <?= !empty($userData['password_last_changed']) ? date('M j, Y', strtotime($userData['password_last_changed'])) : 'Never' ?>
                        </span>
                    </div>

                    <?php if ($membership): ?>
                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-star-fill"></i>
                            Membership
                        </span>
                        <span class="info-value status-premium">
                            <?= htmlspecialchars($membership['plan_name']) ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">
                            <i class="bi bi-calendar-x-fill"></i>
                            Expires
                        </span>
                        <span class="info-value">
                            <?= date('M j, Y', strtotime($membership['expiry_date'])) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div style="margin-top: 22px; padding-top: 20px; border-top: 2px solid var(--border);">
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="my-membership.php" class="btn btn-outline" style="justify-content: flex-start;">
                            <i class="bi bi-ticket-perforated-fill"></i> View Membership
                        </a>
                        <a href="payments.php" class="btn btn-outline" style="justify-content: flex-start;">
                            <i class="bi bi-credit-card-fill"></i> Billing History
                        </a>
                        <a href="notifications.php" class="btn btn-outline" style="justify-content: flex-start;">
                            <i class="bi bi-bell-fill"></i> Notifications
                            <?php if ($unreadNotifCount > 0): ?>
                                <span style="margin-left: auto; background: var(--umak-red); color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 800;">
                                    <?= $unreadNotifCount ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================
             SECURITY / PASSWORD CHANGE
             ============================================ -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">
                    <i class="bi bi-shield-lock-fill"></i>
                    Security Settings
                </h3>
                <span style="font-size: 12px; color: var(--text-muted); font-weight: 600;">
                    <i class="bi bi-lock-fill"></i> Keep your account secure
                </span>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="change_password">
                <?php echo app_csrf_field(); ?>

                <div class="form-grid">
                    <!-- Current Password -->
                    <div class="form-group full">
                        <label class="form-label">
                            <i class="bi bi-key-fill"></i>
                            Current Password <span class="required">*</span>
                        </label>
                        <div class="input-with-icon has-toggle">
                            <i class="bi bi-lock-fill input-icon"></i>
                            <input type="password" name="current_password" id="currentPassword"
                                   class="form-control" required autocomplete="current-password">
                            <button type="button" class="password-toggle" onclick="togglePass('currentPassword', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- New Password -->
                    <div class="form-group">
                        <label class="form-label">
                            <i class="bi bi-lock-fill"></i>
                            New Password <span class="required">*</span>
                        </label>
                        <div class="input-with-icon has-toggle">
                            <i class="bi bi-shield-lock-fill input-icon"></i>
                            <input type="password" name="new_password" id="newPassword"
                                   class="form-control" required autocomplete="new-password">
                            <button type="button" class="password-toggle" onclick="togglePass('newPassword', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Confirm New Password -->
                    <div class="form-group">
                        <label class="form-label">
                            <i class="bi bi-check-circle-fill"></i>
                            Confirm New Password <span class="required">*</span>
                        </label>
                        <div class="input-with-icon has-toggle">
                            <i class="bi bi-check2-circle input-icon"></i>
                            <input type="password" name="confirm_password" id="confirmPassword"
                                   class="form-control" required autocomplete="new-password">
                            <button type="button" class="password-toggle" onclick="togglePass('confirmPassword', this)">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Password Requirements -->
                    <div class="form-group full">
                        <div style="background: #f9fafb; border-radius: 10px; padding: 14px 16px; border-left: 4px solid var(--umak-yellow);">
                            <div style="font-size: 12px; font-weight: 800; color: #92400e; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                                <i class="bi bi-info-circle-fill"></i>
                                Password Requirements
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 6px; font-size: 12px; color: #78350f; font-weight: 600;">
                                <div>✓ At least 8 characters</div>
                                <div>✓ One uppercase letter</div>
                                <div>✓ One lowercase letter</div>
                                <div>✓ One number</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-yellow">
                        <i class="bi bi-shield-check"></i> Update Password
                    </button>
                </div>
            </form>

            <!-- Danger Zone -->
            <div class="danger-zone">
                <div class="danger-zone-header">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    Danger Zone
                </div>
                <p>
                    Deactivating your account will hide your profile and prevent login. 
                    You can reactivate anytime by contacting the gym office. 
                    All your data will be preserved.
                </p>
                <button type="button" class="btn btn-danger" onclick="alert('Please contact the gym office to deactivate your account.');">
                    <i class="bi bi-power"></i> Deactivate Account
                </button>
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
    // PASSWORD VISIBILITY TOGGLE
    // ============================================
    function togglePass(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon = btn.querySelector('i');
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
    // AUTO-SUBMIT AVATAR UPLOAD
    // ============================================
    document.getElementById('avatarInput').addEventListener('change', function() {
        if (this.files.length > 0) {
            document.getElementById('avatarForm').submit();
        }
    });

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
            }, 4000);
        });
    });
</script>
<?php include 'chat_widget.php'; ?>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>