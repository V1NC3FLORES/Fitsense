<?php
// attendance.php
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

function executeQuery($conn, $sql, $types = '', $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt === false) return false;
        if (!empty($types)) $stmt->bind_param($types, ...$params);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    } catch (mysqli_sql_exception $e) {
        error_log("ExecuteQuery Error: " . $e->getMessage());
        return false;
    }
}

function tableExists($conn, $tableName) {
    $tableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    return $result && $result->num_rows > 0;
}

// ============================================
// HANDLE QR SCAN / CHECK-IN / CHECK-OUT
// ============================================
$scanResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $scanResult = ['success' => false, 'message' => 'This form has expired or is invalid. Please refresh the page and try again.'];
    } else {

    // ============================================
    // PROCESS QR SCAN
    // ============================================
    if ($_POST['action'] === 'process_scan') {
        $qrData = trim($_POST['qr_data'] ?? '');

        if (empty($qrData)) {
            $scanResult = ['success' => false, 'message' => 'Invalid QR code. No data received.'];
        } else {
            // QR code format options:
            // 1. Just user_id: "17"
            // 2. Formatted: "FIT-00017" or "USER-17"
            // 3. Full JSON: {"user_id": 17}
            $scannedUserId = 0;

            // Try to parse as JSON first
            $json = json_decode($qrData, true);
            if (is_array($json) && isset($json['user_id'])) {
                $scannedUserId = (int)$json['user_id'];
            }
            // Try FIT-XXXXX format
            elseif (preg_match('/FIT-?0*(\d+)/i', $qrData, $m)) {
                $scannedUserId = (int)$m[1];
            }
            // Try USER-XX format
            elseif (preg_match('/USER-?0*(\d+)/i', $qrData, $m)) {
                $scannedUserId = (int)$m[1];
            }
            // Just a number
            elseif (preg_match('/^\d+$/', $qrData)) {
                $scannedUserId = (int)$qrData;
            }

            if ($scannedUserId <= 0) {
                $scanResult = ['success' => false, 'message' => 'Invalid QR code format. Please use the official member QR code.'];
            } else {
                // Look up the user
                $r = safeQuery($conn,
                    "SELECT id, fullname, username, profile_image, account_status FROM users WHERE id = ? AND role = 'user' LIMIT 1",
                    "i", [$scannedUserId]
                );

                if (!$r || $r->num_rows === 0) {
                    $scanResult = ['success' => false, 'message' => 'Member not found. This QR code is not registered.'];
                } else {
                    $member = $r->fetch_assoc();

                    if ($member['account_status'] !== 'Active') {
                        $scanResult = ['success' => false, 'message' => 'Member account is inactive. Please contact admin.'];
                    } else {
                        // Check if member has an active membership
                        $membershipValid = false;
                        $membershipMsg = '';

                        if (tableExists($conn, 'memberships')) {
                            $r = safeQuery($conn, "
                                SELECT membership_type, status, end_date 
                                FROM memberships 
                                WHERE user_id = ? 
                                AND status = 'Active'
                                AND (end_date IS NULL OR end_date >= CURDATE())
                                ORDER BY end_date DESC 
                                LIMIT 1
                            ", "i", [$scannedUserId]);

                            if ($r && $r->num_rows > 0) {
                                $membershipValid = true;
                                $mem = $r->fetch_assoc();
                                $membershipMsg = $mem['membership_type'] . ' plan active';
                            } else {
                                $membershipMsg = 'No active membership';
                            }
                        }

                        // Check-in / Check-out logic
                        // Look for today's open attendance record for this user
                        $r = safeQuery($conn, "
                            SELECT id, check_in_time 
                            FROM attendance 
                            WHERE user_id = ? 
                            AND DATE(check_in_time) = CURDATE() 
                            AND check_out_time IS NULL 
                            ORDER BY check_in_time DESC 
                            LIMIT 1
                        ", "i", [$scannedUserId]);

                        if ($r && $r->num_rows > 0) {
                            // CHECK-OUT
                            $attendance = $r->fetch_assoc();
                            $ok = executeQuery($conn,
                                "UPDATE attendance SET check_out_time = NOW() WHERE id = ?",
                                "i", [$attendance['id']]
                            );

                            if ($ok) {
                                $checkIn = strtotime($attendance['check_in_time']);
                                $duration = round((time() - $checkIn) / 60);

                                // Notify the member
                                if (tableExists($conn, 'notifications')) {
                                    $notifTitle = "Checked Out Successfully";
                                    $notifMsg = "You checked out at " . date('g:i A') . ". Total session: {$duration} minutes. Great job!";
                                    executeQuery($conn,
                                        "INSERT INTO notifications (user_id, type, title, message, created_at) 
                                         VALUES (?, 'general', ?, ?, NOW())",
                                        "iss", [$scannedUserId, $notifTitle, $notifMsg]
                                    );
                                }

                                $scanResult = [
                                    'success' => true,
                                    'action' => 'check_out',
                                    'message' => 'Check-out recorded successfully!',
                                    'member' => $member,
                                    'duration' => $duration,
                                    'time' => date('g:i A'),
                                    'membership_status' => $membershipValid
                                ];
                            } else {
                                $scanResult = ['success' => false, 'message' => 'Failed to record check-out. Please try again.'];
                            }
                        } else {
                            // CHECK-IN
                            $ok = executeQuery($conn,
                                "INSERT INTO attendance (user_id, check_in_time) VALUES (?, NOW())",
                                "i", [$scannedUserId]
                            );

                            if ($ok) {
                                // Notify the member
                                if (tableExists($conn, 'notifications')) {
                                    $notifTitle = "Checked In Successfully";
                                    $notifMsg = "Welcome! You checked in at " . date('g:i A') . ". Have a great workout!";
                                    executeQuery($conn,
                                        "INSERT INTO notifications (user_id, type, title, message, created_at) 
                                         VALUES (?, 'general', ?, ?, NOW())",
                                        "iss", [$scannedUserId, $notifTitle, $notifMsg]
                                    );
                                }

                                $scanResult = [
                                    'success' => true,
                                    'action' => 'check_in',
                                    'message' => 'Check-in recorded successfully!',
                                    'member' => $member,
                                    'time' => date('g:i A'),
                                    'membership_status' => $membershipValid,
                                    'membership_msg' => $membershipMsg
                                ];
                            } else {
                                $scanResult = ['success' => false, 'message' => 'Failed to record check-in. Please try again.'];
                            }
                        }
                    }
                }
            }
        }

        // If AJAX request, return JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode($scanResult);
            exit;
        }
    }
    }
}

// ============================================
// FETCH USER DATA
// ============================================
$userData = null;
$stmtUser = $conn->prepare("SELECT id, fullname, username, email, profile_image FROM users WHERE id = ? LIMIT 1");
if ($stmtUser) {
    $stmtUser->bind_param("i", $userId);
    $stmtUser->execute();
    $userData = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();
}

$displayName = $userData['fullname'] ?? $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'User';
$firstName = explode(' ', trim($displayName))[0];
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
// FILTERS
// ============================================
$viewMode = $_GET['view'] ?? 'month';
$selectedMonth = $_GET['month'] ?? date('Y-m');
$currentYear = date('Y');

// ============================================
// FETCH ATTENDANCE DATA
// ============================================
$attendanceRecords = [];
$monthlyVisits = 0;
$weeklyVisits = 0;
$yearlyVisits = 0;
$totalVisits = 0;

if (tableExists($conn, 'attendance')) {
    $result = safeQuery($conn,
        "SELECT COUNT(*) AS total FROM attendance 
         WHERE user_id = ? AND MONTH(check_in_time) = MONTH(CURDATE()) 
         AND YEAR(check_in_time) = YEAR(CURDATE())",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) $monthlyVisits = (int)$row['total'];

    $result = safeQuery($conn,
        "SELECT COUNT(*) AS total FROM attendance 
         WHERE user_id = ? AND YEARWEEK(check_in_time, 1) = YEARWEEK(CURDATE(), 1)",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) $weeklyVisits = (int)$row['total'];

    $result = safeQuery($conn,
        "SELECT COUNT(*) AS total FROM attendance 
         WHERE user_id = ? AND YEAR(check_in_time) = YEAR(CURDATE())",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) $yearlyVisits = (int)$row['total'];

    $result = safeQuery($conn,
        "SELECT COUNT(*) AS total FROM attendance WHERE user_id = ?",
        "i", [$userId]
    );
    if ($result && $row = $result->fetch_assoc()) $totalVisits = (int)$row['total'];

    // Fetch records (filtered)
    $whereClause = "";
    $params = [$userId];
    $types = "i";

    if ($viewMode === 'month') {
        $whereClause = " AND DATE_FORMAT(check_in_time, '%Y-%m') = ?";
        $params[] = $selectedMonth;
        $types .= "s";
    } elseif ($viewMode === 'week') {
        $whereClause = " AND YEARWEEK(check_in_time, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($viewMode === 'year') {
        $whereClause = " AND YEAR(check_in_time) = ?";
        $params[] = $currentYear;
        $types .= "i";
    }

    $result = safeQuery($conn,
        "SELECT id, check_in_time, check_out_time,
                TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time) AS duration_min
         FROM attendance 
         WHERE user_id = ?" . $whereClause . "
         ORDER BY check_in_time DESC 
         LIMIT 50",
        $types, $params
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $attendanceRecords[] = $row;
        }
    }
}

// ============================================
// CHECK IF CURRENTLY CHECKED IN
// ============================================
$currentlyCheckedIn = false;
$currentCheckIn = null;
if (tableExists($conn, 'attendance')) {
    $r = safeQuery($conn, "
        SELECT id, check_in_time 
        FROM attendance 
        WHERE user_id = ? 
        AND DATE(check_in_time) = CURDATE() 
        AND check_out_time IS NULL 
        LIMIT 1
    ", "i", [$userId]);
    if ($r && $r->num_rows > 0) {
        $currentlyCheckedIn = true;
        $currentCheckIn = $r->fetch_assoc();
    }
}

// ============================================
// CALCULATE STATS
// ============================================
$avgDuration = 0;
if (!empty($attendanceRecords)) {
    $totalMin = 0;
    $count = 0;
    foreach ($attendanceRecords as $rec) {
        if (!empty($rec['duration_min'])) {
            $totalMin += (int)$rec['duration_min'];
            $count++;
        }
    }
    $avgDuration = $count > 0 ? round($totalMin / $count) : 0;
}

// Current streak calculation
$streak = 0;
if (!empty($attendanceRecords)) {
    $dates = array_unique(array_map(fn($r) => date('Y-m-d', strtotime($r['check_in_time'])), $attendanceRecords));
    sort($dates);
    $dates = array_reverse($dates);
    
    $checkDate = new DateTime('today');
    foreach ($dates as $date) {
        $d = new DateTime($date);
        $diff = $checkDate->diff($d)->days;
        if ($diff <= 1) {
            $streak++;
            $checkDate = $d;
        } else {
            break;
        }
    }
}

$longestVisit = 0;
foreach ($attendanceRecords as $rec) {
    if (!empty($rec['duration_min']) && (int)$rec['duration_min'] > $longestVisit) {
        $longestVisit = (int)$rec['duration_min'];
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function formatDuration($minutes) {
    if (empty($minutes) || $minutes <= 0) return '—';
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($hours > 0) {
        return $hours . 'h ' . ($mins > 0 ? $mins . 'm' : '');
    }
    return $mins . 'm';
}

function formatTime($datetime) {
    if (empty($datetime)) return '—';
    return date('g:i A', strtotime($datetime));
}

function formatDayName($datetime) {
    return date('D', strtotime($datetime));
}

function formatDate($datetime) {
    return date('M j', strtotime($datetime));
}

function getDurationBadgeClass($minutes) {
    if ($minutes >= 90) return 'long';
    if ($minutes >= 45) return 'medium';
    return 'short';
}

// ============================================
// GENERATE USER'S OWN QR CODE URL
// ============================================
// This is the data encoded in the QR code
$myQrData = 'FIT-' . str_pad($userId, 5, '0', STR_PAD_LEFT);
// Using QR Server API to generate the QR image
$myQrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($myQrData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance - UMak Gym: Fitsense</title>
    <link rel="icon" type="image/png" href="uploads/umak-gym-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- QR Code Scanner Library -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
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
           SIDEBAR NAVIGATION
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
            overflow: hidden;
        }

        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar:hover { transform: scale(1.05); }

        /* ============================================
           PAGE CONTENT
           ============================================ */
        .container { max-width: 1300px; margin: 30px auto; padding: 0 25px; }

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
           SCANNER HERO CARD
           ============================================ */
        .scanner-hero {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 24px;
            padding: 40px;
            color: white;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0,91,159,0.25);
        }

        .scanner-hero::before {
            content: '';
            position: absolute;
            top: -100px; right: -100px;
            width: 350px; height: 350px;
            background: radial-gradient(circle, rgba(255,206,0,0.18) 0%, transparent 70%);
            border-radius: 50%;
        }

        .scanner-hero::after {
            content: '';
            position: absolute;
            bottom: -80px; left: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(74,222,128,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .scanner-hero-content {
            position: relative; z-index: 2;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            align-items: center;
        }

        .scanner-hero-left { display: flex; flex-direction: column; gap: 20px; }

        .scanner-hero-title {
            font-size: 30px;
            font-weight: 800;
            margin: 0 0 8px;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .scanner-hero-title i {
            font-size: 32px;
            color: var(--umak-yellow);
        }

        .scanner-hero-desc {
            font-size: 14.5px;
            color: rgba(255,255,255,0.85);
            line-height: 1.7;
            margin: 0 0 20px;
        }

        .btn-scan {
            background: var(--umak-yellow);
            color: #333;
            border: none;
            padding: 16px 32px;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.25s ease;
            box-shadow: 0 8px 24px rgba(255,206,0,0.35);
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .btn-scan:hover {
            background: #ffb800;
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(255,206,0,0.45);
        }

        .btn-scan:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-scan i { font-size: 18px; }

        .my-qr-block {
            text-align: center;
            background: rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 22px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.15);
        }

        .my-qr-block img {
            width: 200px;
            height: 200px;
            background: white;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 14px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        }

        .my-qr-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.75);
            font-weight: 700;
            margin-bottom: 6px;
        }

        .my-qr-code {
            font-family: 'Courier New', monospace;
            font-size: 16px;
            font-weight: 800;
            color: var(--umak-yellow);
            letter-spacing: 2px;
        }

        /* Currently checked-in banner */
        .checked-in-banner {
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 18px;
            color: white;
            box-shadow: 0 10px 30px rgba(16,185,129,0.3);
            flex-wrap: wrap;
        }

        .checked-in-icon {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            flex-shrink: 0;
        }

        .checked-in-info { flex: 1; min-width: 200px; }
        .checked-in-info h4 { margin: 0 0 4px; font-size: 16px; font-weight: 800; }
        .checked-in-info p { margin: 0; font-size: 13px; color: rgba(255,255,255,0.9); }

        .checked-in-timer {
            background: rgba(255,255,255,0.2);
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-family: 'Courier New', monospace;
            font-size: 18px;
            letter-spacing: 1px;
            flex-shrink: 0;
        }

        /* ============================================
           SCANNER MODAL
           ============================================ */
        .scanner-modal {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.85);
            backdrop-filter: blur(8px);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .scanner-modal.open { display: flex; }

        .scanner-modal-content {
            background: white;
            border-radius: 24px;
            max-width: 520px;
            width: 100%;
            max-height: 95vh;
            overflow-y: auto;
            box-shadow: 0 25px 70px rgba(0,0,0,0.5);
            animation: modalIn 0.3s ease;
        }

        @keyframes modalIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .scanner-modal-header {
            padding: 20px 24px;
            border-bottom: 2px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .scanner-modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            color: var(--umak-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .scanner-modal-close {
            background: #f3f4f6;
            border: none;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: var(--text-muted);
            transition: all 0.2s;
        }

        .scanner-modal-close:hover { background: #e5e7eb; transform: rotate(90deg); }

        .scanner-modal-body { padding: 24px; }

        #qr-reader {
            width: 100%;
            border-radius: 16px;
            overflow: hidden;
            background: #000;
            margin-bottom: 16px;
        }

        #qr-reader video {
            width: 100% !important;
            height: auto !important;
            display: block;
        }

        #qr-reader__scan_region { border: none !important; }
        #qr-reader__scan_region img { display: none !important; }

        .scanner-status {
            text-align: center;
            padding: 12px 18px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 16px;
            line-height: 1.5;
        }

        .scanner-status.info { background: #dbeafe; color: #1e40af; }
        .scanner-status.success { background: #d1fae5; color: #065f46; }
        .scanner-status.error { background: #fee2e2; color: #991b1b; }

        .scanner-divider {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 20px 0;
            color: #9ca3af;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .scanner-divider::before,
        .scanner-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .manual-input-group {
            display: flex;
            gap: 10px;
        }

        .manual-input-group input {
            flex: 1;
            padding: 13px 16px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            font-weight: 600;
            outline: none;
            transition: 0.2s;
        }

        .manual-input-group input:focus {
            border-color: var(--umak-blue);
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        .manual-input-group button {
            padding: 0 24px;
            border: none;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            font-family: inherit;
            transition: 0.2s;
        }

        .manual-input-group button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,91,159,0.35);
        }

        /* Scan Result Card */
        .scan-result {
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 20px;
            text-align: center;
            animation: resultPop 0.4s ease;
        }

        @keyframes resultPop {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .scan-result.success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 2px solid #10b981; }
        .scan-result.error { background: linear-gradient(135deg, #fee2e2, #fecaca); border: 2px solid #ef4444; }

        .scan-result-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 14px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            animation: popIn 0.5s ease;
        }

        @keyframes popIn {
            0% { transform: scale(0); }
            50% { transform: scale(1.15); }
            100% { transform: scale(1); }
        }

        .scan-result.success .scan-result-icon { background: #10b981; color: white; }
        .scan-result.error .scan-result-icon { background: #ef4444; color: white; }

        .scan-result-title {
            font-size: 20px;
            font-weight: 800;
            margin: 0 0 6px;
        }

        .scan-result.success .scan-result-title { color: #065f46; }
        .scan-result.error .scan-result-title { color: #991b1b; }

        .scan-result-message {
            font-size: 14px;
            margin: 0;
            font-weight: 600;
            line-height: 1.5;
        }

        .scan-result.success .scan-result-message { color: #065f46; }
        .scan-result.error .scan-result-message { color: #991b1b; }

        .scan-member-info {
            display: flex;
            align-items: center;
            gap: 14px;
            background: rgba(255,255,255,0.7);
            border-radius: 12px;
            padding: 14px;
            margin-top: 16px;
        }

        .scan-member-avatar {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 20px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .scan-member-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .scan-member-detail { text-align: left; flex: 1; min-width: 0; }
        .scan-member-detail h4 { margin: 0 0 3px; font-size: 15px; font-weight: 800; color: #111827; }
        .scan-member-detail p { margin: 0; font-size: 12.5px; color: #4b5563; font-weight: 600; }

        .scan-meta {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .scan-meta-item {
            background: rgba(255,255,255,0.7);
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* ============================================
           STATS ROW
           ============================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        .stat-box-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }

        /* ============================================
           ATTENDANCE LIST
           ============================================ */
        .attendance-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 30px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 25px;
        }

        .attendance-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border);
        }

        .attendance-card-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 18px;
            font-weight: 800;
            color: var(--umak-blue);
            margin: 0;
        }

        .attendance-card-title i { font-size: 22px; }

        .filter-tabs {
            display: flex;
            background: #f3f4f6;
            border-radius: 25px;
            padding: 4px;
            gap: 2px;
        }

        .filter-tab {
            padding: 8px 18px;
            border-radius: 20px;
            border: none;
            background: transparent;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-muted);
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .filter-tab:hover { color: var(--umak-blue); }

        .filter-tab.active {
            background: white;
            color: var(--umak-blue);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .attendance-list {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .attendance-row {
            display: flex;
            align-items: center;
            padding: 18px 20px;
            border-radius: 12px;
            transition: all 0.2s ease;
            gap: 20px;
            border-bottom: 1px solid var(--border);
        }

        .attendance-row:last-child { border-bottom: none; }

        .attendance-row:hover {
            background: #f9fafb;
            transform: translateX(3px);
        }

        .att-date {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-width: 70px;
            padding: 10px 12px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(0,91,159,0.08), rgba(0,132,209,0.12));
            border: 1px solid rgba(0,91,159,0.12);
            flex-shrink: 0;
        }

        .att-day {
            font-size: 11px;
            font-weight: 800;
            color: var(--umak-blue);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 2px;
        }

        .att-date-num {
            font-size: 20px;
            font-weight: 800;
            color: #111827;
            line-height: 1;
        }

        .att-date-month {
            font-size: 10px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-top: 2px;
        }

        .att-time {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .att-time-range {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 600;
            color: #1f2937;
            flex-wrap: wrap;
        }

        .att-time-range .time-dot {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .att-time-range .time-dot i { font-size: 12px; }
        .att-time-range .time-arrow { color: var(--text-muted); font-size: 12px; }
        .att-time-range .time-in { color: var(--umak-green); }
        .att-time-range .time-out { color: var(--umak-red); }

        .att-duration {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 800;
            flex-shrink: 0;
            letter-spacing: 0.3px;
        }

        .att-duration.long { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; }
        .att-duration.medium { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1e40af; }
        .att-duration.short { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #92400e; }

        /* ============================================
           EMPTY STATE
           ============================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: rgba(0,91,159,0.08);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: var(--umak-blue);
        }

        .empty-state h3 {
            margin: 0 0 10px;
            color: var(--umak-blue);
            font-size: 18px;
        }

        .empty-state p {
            color: var(--text-muted);
            font-size: 14px;
            margin: 0;
        }

        /* ============================================
           ACTION BAR
           ============================================ */
        .action-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 25px;
        }

        .btn {
            padding: 13px 26px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.25s ease;
            text-decoration: none;
            letter-spacing: 0.3px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 15px rgba(0,91,159,0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,91,159,0.4);
        }

        .btn-outline {
            background: transparent;
            color: var(--umak-blue);
            border: 2px solid var(--umak-blue);
        }

        .btn-outline:hover {
            background: var(--umak-blue);
            color: white;
        }

        .btn i { font-size: 16px; }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
            .scanner-hero-content { grid-template-columns: 1fr; }
            .my-qr-block { max-width: 320px; margin: 0 auto; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .btn-chat-open span.label { display: none; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .scanner-hero { padding: 26px 22px; }
            .scanner-hero-title { font-size: 22px; }
            .attendance-card { padding: 20px 15px; }
            .attendance-row { padding: 14px 8px; gap: 12px; flex-wrap: wrap; }
            .att-date { min-width: 60px; padding: 8px 10px; }
            .att-date-num { font-size: 17px; }
            .att-time-range { font-size: 13.5px; }
            .att-duration { padding: 5px 10px; font-size: 11.5px; }
            .stats-row { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 576px) {
            .filter-tabs { width: 100%; justify-content: center; flex-wrap: wrap; }
            .filter-tab { padding: 6px 12px; font-size: 12px; }
            .att-time { flex-direction: column; align-items: flex-start; gap: 6px; }
            .stats-row { grid-template-columns: 1fr; }
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
        <a href="attendance.php" class="nav-item active">
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
        <a href="my-profile.php" class="nav-item">
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

    <header class="top-header">
        <div class="header-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h2>My Attendance</h2>
                <p class="subtitle">Scan QR code to check in or out</p>
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
                <div class="avatar"><?= htmlspecialchars($userInitial) ?></div>
            </button>
        </div>
    </header>

    <main class="container">

        <h1 class="page-title">
            <i class="bi bi-bar-chart-fill"></i>
            My Attendance
        </h1>
        <p class="page-subtitle">Scan your QR code to check in or out. View your gym visit history below.</p>

        <!-- ============================================
             SCANNER HERO
             ============================================ -->
        <div class="scanner-hero">
            <div class="scanner-hero-content">

                <!-- LEFT: Scan Button + Info -->
                <div class="scanner-hero-left">
                    <h2 class="scanner-hero-title">
                        <i class="bi bi-qr-code-scan"></i>
                        QR Code Check-In
                    </h2>
                    <p class="scanner-hero-desc">
                        <?php if ($currentlyCheckedIn): ?>
                            You are currently <strong>checked in</strong>. Scan again to record your check-out.
                        <?php else: ?>
                            Scan your personal QR code at the gym entrance to record your attendance. Works on any device with a camera.
                        <?php endif; ?>
                    </p>
                    <button class="btn-scan" onclick="openScanner()">
                        <i class="bi bi-camera-fill"></i>
                        <?= $currentlyCheckedIn ? 'Scan to Check Out' : 'Scan to Check In' ?>
                    </button>
                </div>

                <!-- RIGHT: User's Own QR Code -->
                <div class="my-qr-block">
                    <div class="my-qr-label">Your QR Code</div>
                    <img src="<?= htmlspecialchars($myQrImageUrl) ?>" alt="My QR Code">
                    <div class="my-qr-code"><?= htmlspecialchars($myQrData) ?></div>
                    <div style="font-size: 11px; color: rgba(255,255,255,0.6); margin-top: 8px;">
                        Show this at the gym scanner
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
                    <div class="stat-box-label">This Month</div>
                    <div class="stat-box-value"><?= $monthlyVisits ?></div>
                    <div class="stat-box-sub">visits in <?= date('F Y') ?></div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon green">
                    <i class="bi bi-fire"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Current Streak</div>
                    <div class="stat-box-value"><?= $streak ?><span style="font-size: 14px; color: var(--text-muted); font-weight: 600;"> day<?= $streak != 1 ? 's' : '' ?></span></div>
                    <div class="stat-box-sub"><?= $streak > 0 ? 'Keep it up!' : 'Start your streak today!' ?></div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon orange">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Avg. Session</div>
                    <div class="stat-box-value"><?= $avgDuration > 0 ? formatDuration($avgDuration) : '—' ?></div>
                    <div class="stat-box-sub">per visit</div>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-box-icon purple">
                    <i class="bi bi-trophy-fill"></i>
                </div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Total Visits</div>
                    <div class="stat-box-value"><?= $totalVisits ?></div>
                    <div class="stat-box-sub">all time</div>
                </div>
            </div>
        </div>

        <!-- ============================================
             ATTENDANCE LIST
             ============================================ -->
        <div class="attendance-card">
            <div class="attendance-card-header">
                <h2 class="attendance-card-title">
                    <i class="bi bi-list-check"></i>
                    <?php
                    $titleMap = [
                        'month' => 'This Month',
                        'week' => 'This Week',
                        'year' => 'This Year',
                        'all' => 'All Records',
                    ];
                    echo $titleMap[$viewMode] ?? 'Attendance Records';
                    ?>
                </h2>

                <div class="filter-tabs">
                    <a href="?view=week" class="filter-tab <?= $viewMode === 'week' ? 'active' : '' ?>">
                        <i class="bi bi-calendar-week"></i> Week
                    </a>
                    <a href="?view=month" class="filter-tab <?= $viewMode === 'month' ? 'active' : '' ?>">
                        <i class="bi bi-calendar-month"></i> Month
                    </a>
                    <a href="?view=year" class="filter-tab <?= $viewMode === 'year' ? 'active' : '' ?>">
                        <i class="bi bi-calendar-range"></i> Year
                    </a>
                    <a href="?view=all" class="filter-tab <?= $viewMode === 'all' ? 'active' : '' ?>">
                        <i class="bi bi-infinity"></i> All
                    </a>
                </div>
            </div>

            <?php if (empty($attendanceRecords)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="bi bi-calendar-x"></i>
                    </div>
                    <h3>No Attendance Records</h3>
                    <p>
                        <?php if ($viewMode === 'month'): ?>
                            No visits recorded for this month yet. Scan your QR code at the gym to check in!
                        <?php elseif ($viewMode === 'week'): ?>
                            No visits recorded this week yet.
                        <?php elseif ($viewMode === 'year'): ?>
                            No visits recorded this year yet.
                        <?php else: ?>
                            You haven't checked in yet. Scan your QR code to start tracking!
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="attendance-list">
                    <?php foreach ($attendanceRecords as $rec):
                        $dayName = formatDayName($rec['check_in_time']);
                        $dateNum = date('j', strtotime($rec['check_in_time']));
                        $dateMonth = date('M', strtotime($rec['check_in_time']));
                        $timeIn = formatTime($rec['check_in_time']);
                        $timeOut = $rec['check_out_time'] ? formatTime($rec['check_out_time']) : 'Active';
                        $duration = $rec['duration_min'] ?? 0;
                        $durationClass = getDurationBadgeClass($duration);
                    ?>
                        <div class="attendance-row">
                            <div class="att-date">
                                <div class="att-day"><?= $dayName ?></div>
                                <div class="att-date-num"><?= $dateNum ?></div>
                                <div class="att-date-month"><?= $dateMonth ?></div>
                            </div>

                            <div class="att-time">
                                <div class="att-time-range">
                                    <span class="time-dot time-in">
                                        <i class="bi bi-box-arrow-in-right"></i>
                                        <?= $timeIn ?>
                                    </span>
                                    <span class="time-arrow">→</span>
                                    <span class="time-dot time-out">
                                        <i class="bi bi-box-arrow-right"></i>
                                        <?= $timeOut ?>
                                    </span>
                                </div>
                            </div>

                            <?php if ($duration > 0): ?>
                                <div class="att-duration <?= $durationClass ?>">
                                    <i class="bi bi-clock-fill"></i>
                                    <?= formatDuration($duration) ?>
                                </div>
                            <?php elseif ($rec['check_out_time'] === null): ?>
                                <div class="att-duration medium">
                                    <i class="bi bi-broadcast"></i>
                                    In Progress
                                </div>
                            <?php else: ?>
                                <div class="att-duration short">
                                    <i class="bi bi-dash-circle"></i>
                                    No Duration
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="action-bar">
                <a href="?view=all" class="btn btn-primary">
                    <i class="bi bi-clock-history"></i> View Full History
                </a>
                <button class="btn btn-outline" onclick="window.print()">
                    <i class="bi bi-printer-fill"></i> Print Report
                </button>
                <a href="user-dashboard.php" class="btn btn-outline">
                    <i class="bi bi-house-door"></i> Back to Dashboard
                </a>
            </div>
        </div>

    </main>
</div>

<!-- ============================================
     SCANNER MODAL
     ============================================ -->
<div class="scanner-modal" id="scannerModal">
    <div class="scanner-modal-content">

        <div class="scanner-modal-header">
            <h3>
                <i class="bi bi-qr-code-scan"></i>
                QR Code Scanner
            </h3>
            <button class="scanner-modal-close" onclick="closeScanner()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="scanner-modal-body">

            <!-- Scan Result -->
            <div id="scanResultContainer"></div>

            <!-- Status Message -->
            <div class="scanner-status info" id="scannerStatus">
                <i class="bi bi-info-circle-fill"></i>
                Point your camera at the member's QR code
            </div>

            <!-- QR Reader -->
            <div id="qr-reader"></div>

            <!-- Manual Entry -->
            <div class="scanner-divider">Or enter code manually</div>

            <div class="manual-input-group">
                <input type="text" id="manualQrInput" placeholder="e.g., FIT-00017" autocomplete="off">
                <button onclick="submitManualCode()">
                    <i class="bi bi-send-fill"></i> Submit
                </button>
            </div>

        </div>
    </div>
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
    // LIVE TIMER FOR CURRENT SESSION
    // ============================================
    const liveTimer = document.getElementById('liveTimer');
    if (liveTimer) {
        const startTime = parseInt(liveTimer.dataset.start) * 1000;

        function updateTimer() {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            const hours = Math.floor(elapsed / 3600);
            const mins = Math.floor((elapsed % 3600) / 60);
            const secs = elapsed % 60;

            let display = '';
            if (hours > 0) {
                display = hours + 'h ' + String(mins).padStart(2, '0') + 'm ' + String(secs).padStart(2, '0') + 's';
            } else {
                display = String(mins).padStart(2, '0') + 'm ' + String(secs).padStart(2, '0') + 's';
            }
            liveTimer.textContent = display;
        }
        updateTimer();
        setInterval(updateTimer, 1000);
    }

    // ============================================
    // QR SCANNER
    // ============================================
    let html5QrCode = null;
    let isScanning = false;

    function openScanner() {
        document.getElementById('scannerModal').classList.add('open');
        document.body.style.overflow = 'hidden';
        startScanner();
    }

    function closeScanner() {
        document.getElementById('scannerModal').classList.remove('open');
        document.body.style.overflow = '';
        stopScanner();
        // Reset state
        document.getElementById('scanResultContainer').innerHTML = '';
        document.getElementById('scannerStatus').className = 'scanner-status info';
        document.getElementById('scannerStatus').innerHTML = '<i class="bi bi-info-circle-fill"></i> Point your camera at the member\'s QR code';
        document.getElementById('manualQrInput').value = '';
    }

    async function startScanner() {
        if (isScanning) return;

        try {
            html5QrCode = new Html5Qrcode("qr-reader");
            isScanning = true;

            const config = {
                fps: 10,
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0,
                showTorchButtonIfSupported: true,
                showZoomSliderIfSupported: true,
            };

            await html5QrCode.start(
                { facingMode: "environment" }, // prefer back camera
                config,
                onScanSuccess,
                onScanError
            );

        } catch (err) {
            console.error('Scanner start error:', err);
            document.getElementById('scannerStatus').className = 'scanner-status error';
            document.getElementById('scannerStatus').innerHTML = 
                '<i class="bi bi-exclamation-triangle-fill"></i> Could not access camera. Please allow camera permissions or use manual entry below.';
            isScanning = false;
        }
    }

    async function stopScanner() {
        if (html5QrCode && isScanning) {
            try {
                await html5QrCode.stop();
                html5QrCode.clear();
            } catch (err) {
                console.error('Stop scanner error:', err);
            }
            isScanning = false;
        }
    }

    // Debounce to avoid processing the same QR multiple times
    let lastScannedCode = '';
    let lastScanTime = 0;

    async function onScanSuccess(decodedText, decodedResult) {
        const now = Date.now();

        // Ignore duplicates within 3 seconds
        if (decodedText === lastScannedCode && (now - lastScanTime) < 3000) {
            return;
        }
        lastScannedCode = decodedText;
        lastScanTime = now;

        // Visual + audio feedback
        if (navigator.vibrate) navigator.vibrate(100);

        // Update status
        document.getElementById('scannerStatus').className = 'scanner-status info';
        document.getElementById('scannerStatus').innerHTML = 
            '<i class="bi bi-arrow-clockwise" style="animation: spin 1s linear infinite; display: inline-block;"></i> Processing...';

        // Submit to server
        await submitScan(decodedText);
    }

    function onScanError(errorMessage) {
        // Silently ignore — this fires constantly during normal scanning
    }

    // ============================================
    // SUBMIT SCAN
    // ============================================
    async function submitScan(qrData) {
        try {
            const formData = new FormData();
            formData.append('action', 'process_scan');
            formData.append('csrf_token', <?= json_encode(app_csrf_token()) ?>);
            formData.append('qr_data', qrData);

            const res = await fetch('', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });

            const data = await res.json();
            showScanResult(data);

            // On success, stop scanning and auto-refresh after a short delay
            if (data.success) {
                setTimeout(async () => {
                    await stopScanner();
                    // Auto-reload page to update attendance table
                    setTimeout(() => window.location.reload(), 2000);
                }, 1000);
            }
        } catch (err) {
            console.error('Submit error:', err);
            showScanResult({
                success: false,
                message: 'Network error. Please try again.'
            });
        }
    }

    function submitManualCode() {
        const input = document.getElementById('manualQrInput');
        const code = input.value.trim();

        if (!code) {
            alert('Please enter a QR code.');
            return;
        }

        document.getElementById('scannerStatus').className = 'scanner-status info';
        document.getElementById('scannerStatus').innerHTML = 
            '<i class="bi bi-arrow-clockwise" style="animation: spin 1s linear infinite; display: inline-block;"></i> Processing...';

        submitScan(code);
    }

    // ============================================
    // SHOW SCAN RESULT
    // ============================================
    function showScanResult(data) {
        const container = document.getElementById('scanResultContainer');
        const status = document.getElementById('scannerStatus');

        if (data.success) {
            // Success card
            const member = data.member || {};
            const initials = (member.fullname || 'U').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();

            let avatarHtml = '';
            if (member.profile_image) {
                avatarHtml = `<img src="${escapeHtml(member.profile_image)}" alt="">`;
            } else {
                avatarHtml = escapeHtml(initials);
            }

            const actionLabel = data.action === 'check_in' ? 'CHECKED IN' : 'CHECKED OUT';
            const actionIcon = data.action === 'check_in' ? 'box-arrow-in-right' : 'box-arrow-out-right';

            let metaHtml = '';
            metaHtml += `<span class="scan-meta-item"><i class="bi bi-clock-fill"></i> ${escapeHtml(data.time)}</span>`;
            if (data.duration !== undefined) {
                metaHtml += `<span class="scan-meta-item"><i class="bi bi-hourglass-split"></i> ${data.duration} min</span>`;
            }
            if (data.membership_status) {
                metaHtml += `<span class="scan-meta-item"><i class="bi bi-check-circle-fill" style="color: #10b981;"></i> Active Member</span>`;
            } else if (data.membership_msg) {
                metaHtml += `<span class="scan-meta-item"><i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b;"></i> ${escapeHtml(data.membership_msg)}</span>`;
            }

            container.innerHTML = `
                <div class="scan-result success">
                    <div class="scan-result-icon">
                        <i class="bi bi-${actionIcon}"></i>
                    </div>
                    <h3 class="scan-result-title">${actionLabel}</h3>
                    <p class="scan-result-message">${escapeHtml(data.message)}</p>
                    <div class="scan-member-info">
                        <div class="scan-member-avatar">${avatarHtml}</div>
                        <div class="scan-member-detail">
                            <h4>${escapeHtml(member.fullname || 'Unknown')}</h4>
                            <p>@${escapeHtml(member.username || '')}</p>
                        </div>
                    </div>
                    <div class="scan-meta">${metaHtml}</div>
                </div>
            `;

            status.className = 'scanner-status success';
            status.innerHTML = '<i class="bi bi-check-circle-fill"></i> Success! Refreshing page...';

        } else {
            // Error card
            container.innerHTML = `
                <div class="scan-result error">
                    <div class="scan-result-icon">
                        <i class="bi bi-x-circle-fill"></i>
                    </div>
                    <h3 class="scan-result-title">Scan Failed</h3>
                    <p class="scan-result-message">${escapeHtml(data.message || 'Unknown error')}</p>
                </div>
            `;

            status.className = 'scanner-status error';
            status.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Please try again';

            // Auto-clear result after 4 seconds so user can rescan
            setTimeout(() => {
                container.innerHTML = '';
                status.className = 'scanner-status info';
                status.innerHTML = '<i class="bi bi-info-circle-fill"></i> Point your camera at the member\'s QR code';
            }, 4000);
        }
    }

    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ============================================
    // MODAL CLOSE ON ESC
    // ============================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('scannerModal');
            if (modal.classList.contains('open')) closeScanner();
        }
    });

    // Click outside to close
    document.getElementById('scannerModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeScanner();
    });

    // Manual input: submit on Enter
    document.getElementById('manualQrInput')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') submitManualCode();
    });
</script>
<?php include 'chat_widget.php'; ?>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>