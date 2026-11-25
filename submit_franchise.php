<?php
// submit_franchise.php
session_start();
include 'conn.php';
require_once __DIR__ . '/config/session_security.php';

$userSession = app_require_session_user($conn, 'user', 'login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: user-franchise.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

$first_name     = trim($_POST['first_name'] ?? '');
$last_name      = trim($_POST['last_name'] ?? '');
$email          = trim($_POST['email'] ?? '');
$phone          = trim($_POST['phone'] ?? '');
$market         = trim($_POST['market'] ?? '');
$other_market   = trim($_POST['other_market'] ?? '');
$site_reference = trim($_POST['site_reference'] ?? '');
$preferred_date = trim($_POST['preferred_date'] ?? '');
$preferred_time = trim($_POST['preferred_time'] ?? '');
$message        = trim($_POST['message'] ?? '');

if (
    $first_name === '' ||
    $last_name === '' ||
    $email === '' ||
    $phone === '' ||
    $market === '' ||
    $site_reference === '' ||
    $preferred_date === '' ||
    $preferred_time === ''
) {
    header("Location: user-franchise.php?error=1");
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: user-franchise.php?error=1");
    exit();
}

$userStmt = $conn->prepare("SELECT branch_id, fullname FROM users WHERE id = ? AND role = 'user' LIMIT 1");
$userStmt->bind_param("i", $user_id);
$userStmt->execute();
$userResult = $userStmt->get_result();

if (!$userResult || $userResult->num_rows === 0) {
    $userStmt->close();
    header("Location: login.php");
    exit();
}

$user = $userResult->fetch_assoc();
$branch_id = (int)$user['branch_id'];
$fullname = $user['fullname'] ?? ($first_name . ' ' . $last_name);
$userStmt->close();

$checkStmt = $conn->prepare("SELECT id, status FROM franchise_applications WHERE user_id = ? LIMIT 1");
$checkStmt->bind_param("i", $user_id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult && $checkResult->num_rows > 0) {
    $existing = $checkResult->fetch_assoc();
    $checkStmt->close();

    header("Location: user-franchise.php?duplicate=1&status=" . urlencode($existing['status']));
    exit();
}
$checkStmt->close();

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("
        INSERT INTO franchise_applications
        (
            user_id,
            first_name,
            last_name,
            email,
            phone,
            market,
            other_market,
            site_reference,
            preferred_date,
            preferred_time,
            message,
            status,
            created_at,
            branch_id
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), ?)
    ");

    $stmt->bind_param(
        "issssssssssi",
        $user_id,
        $first_name,
        $last_name,
        $email,
        $phone,
        $market,
        $other_market,
        $site_reference,
        $preferred_date,
        $preferred_time,
        $message,
        $branch_id
    );

    if (!$stmt->execute()) {
        throw new Exception("Failed to submit franchise application.");
    }

    $stmt->close();

    $userNotifTitle = "Franchise Application Submitted";
    $userNotifMessage = "Your franchise application has been submitted and is now pending review.";

    $notifStmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
        VALUES (?, 'general', ?, ?, 0, NOW())
    ");
    $notifStmt->bind_param("iss", $user_id, $userNotifTitle, $userNotifMessage);

    if (!$notifStmt->execute()) {
        throw new Exception("Failed to create user notification.");
    }

    $notifStmt->close();

    $adminStmt = $conn->prepare("
        SELECT id 
        FROM users 
        WHERE role = 'admin' 
        AND branch_id = ?
    ");
    $adminStmt->bind_param("i", $branch_id);
    $adminStmt->execute();
    $adminResult = $adminStmt->get_result();

    $adminNotifTitle = "New Franchise Application";
    $adminNotifMessage = $fullname . " submitted a franchise application for your branch.";

    while ($admin = $adminResult->fetch_assoc()) {
        $admin_id = (int)$admin['id'];

        $adminNotifStmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, title, message, is_read, created_at)
            VALUES (?, 'general', ?, ?, 0, NOW())
        ");
        $adminNotifStmt->bind_param("iss", $admin_id, $adminNotifTitle, $adminNotifMessage);

        if (!$adminNotifStmt->execute()) {
            throw new Exception("Failed to create admin notification.");
        }

        $adminNotifStmt->close();
    }

    $adminStmt->close();

    $conn->commit();

    header("Location: user-franchise.php?success=1");
    exit();

} catch (mysqli_sql_exception $e) {
    $conn->rollback();

    if (str_contains($e->getMessage(), 'unique_user_franchise')) {
        header("Location: user-franchise.php?duplicate=1");
        exit();
    }

    header("Location: user-franchise.php?error=1");
    exit();

} catch (Exception $e) {
    $conn->rollback();

    header("Location: user-franchise.php?error=1");
    exit();
}
?>