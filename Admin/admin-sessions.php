<?php
// Admin/admin-sessions.php
session_start();
include __DIR__ . '/../conn.php';
require_once __DIR__ . '/../config/session_security.php';

// ============================================
// AUTHENTICATION CHECK
// ============================================
$userSession = app_require_session_user($conn, ['admin', 'super_admin'], 'admin-login.php');
$role = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];
$isSuperAdmin = ($role === 'super_admin');

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

function getTableColumns($conn, $tableName) {
    static $cache = [];
    if (isset($cache[$tableName])) return $cache[$tableName];
    $columns = [];
    $tableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW COLUMNS FROM `$tableName`");
    if ($result) {
        while ($row = $result->fetch_assoc()) $columns[] = $row['Field'];
    }
    $cache[$tableName] = $columns;
    return $columns;
}

function columnExists($conn, $tableName, $columnName) {
    return in_array($columnName, getTableColumns($conn, $tableName));
}

// ============================================
// CHECK SESSIONS TABLE EXISTS
// ============================================
if (!tableExists($conn, 'sessions')) {
    die("Sessions table not found in database.");
}

// ============================================
// FETCH ADMIN DATA
// ============================================
$adminData = null;
$stmtAdmin = $conn->prepare("SELECT id, fullname, username, profile_image, role FROM users WHERE id = ? LIMIT 1");
if ($stmtAdmin) {
    $stmtAdmin->bind_param("i", $userId);
    $stmtAdmin->execute();
    $adminData = $stmtAdmin->get_result()->fetch_assoc();
    $stmtAdmin->close();
}

$adminName = $adminData['fullname'] ?? 'Administrator';
$adminFirstName = explode(' ', trim($adminName))[0];
$adminInitial = strtoupper(substr($adminName, 0, 1));
$adminAvatar = $adminData['profile_image'] ?? '';

// ============================================
// FETCH LIVE GYM CAPACITY
// ============================================
function getGymCapacity($conn) {
    $maxCapacity = 20;

    // Try to fetch from system_settings first
    if (tableExists($conn, 'system_settings')) {
        $r = safeQuery($conn, "SELECT setting_value FROM system_settings WHERE setting_key = 'max_gym_capacity' LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) {
            $maxCapacity = (int)$row['setting_value'];
        }
    }

    // Count currently checked in (today, no check-out)
    $currentOccupancy = 0;
    $recentCheckins = 0;
    if (tableExists($conn, 'attendance')) {
        $r = safeQuery($conn, "
            SELECT COUNT(*) AS total FROM attendance 
            WHERE DATE(check_in_time) = CURDATE() 
            AND check_out_time IS NULL
        ");
        if ($r && $row = $r->fetch_assoc()) {
            $currentOccupancy = (int)$row['total'];
        }

        // Last 24 hours check-ins
        $r = safeQuery($conn, "
            SELECT COUNT(*) AS total FROM attendance 
            WHERE check_in_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        if ($r && $row = $r->fetch_assoc()) {
            $recentCheckins = (int)$row['total'];
        }
    }

    $percentage = $maxCapacity > 0 ? round(($currentOccupancy / $maxCapacity) * 100) : 0;

    // Status based on percentage
    $status = 'low';
    $statusLabel = 'Quiet';
    $statusColor = '#10b981';
    if ($percentage >= 80) {
        $status = 'high';
        $statusLabel = 'Full';
        $statusColor = '#ef4444';
    } elseif ($percentage >= 50) {
        $status = 'medium';
        $statusLabel = 'Busy';
        $statusColor = '#f59e0b';
    }

    return [
        'max' => $maxCapacity,
        'current' => $currentOccupancy,
        'percentage' => $percentage,
        'available' => max(0, $maxCapacity - $currentOccupancy),
        'recent_checkins' => $recentCheckins,
        'status' => $status,
        'status_label' => $statusLabel,
        'status_color' => $statusColor,
    ];
}

// ============================================
// HANDLE QR SCAN (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_qr') {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired scanner request.']);
        exit;
    }

    header('Content-Type: application/json');

    $qrData = trim($_POST['qr_data'] ?? '');

    if (empty($qrData)) {
        echo json_encode(['success' => false, 'message' => 'No QR data received.']);
        exit;
    }

    // Parse QR data
    $scannedUserId = 0;

    // Try JSON
    $json = json_decode($qrData, true);
    if (is_array($json) && isset($json['user_id'])) {
        $scannedUserId = (int)$json['user_id'];
    }
    // FIT-XXXXX
    elseif (preg_match('/FIT-?0*(\d+)/i', $qrData, $m)) {
        $scannedUserId = (int)$m[1];
    }
    // USER-XX
    elseif (preg_match('/USER-?0*(\d+)/i', $qrData, $m)) {
        $scannedUserId = (int)$m[1];
    }
    // Plain number
    elseif (preg_match('/^\d+$/', $qrData)) {
        $scannedUserId = (int)$qrData;
    }

    if ($scannedUserId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid QR code format.']);
        exit;
    }

    // Lookup user
    $r = safeQuery($conn,
        "SELECT id, fullname, username, profile_image, account_status FROM users WHERE id = ? AND role = 'user' LIMIT 1",
        "i", [$scannedUserId]
    );

    if (!$r || $r->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Member not found. QR code is not registered.']);
        exit;
    }

    $member = $r->fetch_assoc();

    if ($member['account_status'] !== 'Active') {
        echo json_encode(['success' => false, 'message' => 'Member account is inactive.']);
        exit;
    }

    // Check membership
    $membershipValid = false;
    $membershipMsg = '';
    if (tableExists($conn, 'memberships')) {
        $r = safeQuery($conn, "
            SELECT membership_type, end_date FROM memberships 
            WHERE user_id = ? AND status = 'Active' 
            AND (end_date IS NULL OR end_date >= CURDATE()) 
            ORDER BY end_date DESC LIMIT 1
        ", "i", [$scannedUserId]);
        if ($r && $r->num_rows > 0) {
            $membershipValid = true;
            $mem = $r->fetch_assoc();
            $membershipMsg = $mem['membership_type'] . ' plan';
        }
    }

    // Check-in vs check-out
    $r = safeQuery($conn, "
        SELECT id, check_in_time FROM attendance 
        WHERE user_id = ? AND DATE(check_in_time) = CURDATE() AND check_out_time IS NULL 
        ORDER BY check_in_time DESC LIMIT 1
    ", "i", [$scannedUserId]);

    if ($r && $r->num_rows > 0) {
        // CHECK-OUT
        $att = $r->fetch_assoc();
        $ok = executeQuery($conn, "UPDATE attendance SET check_out_time = NOW() WHERE id = ?", "i", [$att['id']]);

        if ($ok) {
            $duration = round((time() - strtotime($att['check_in_time'])) / 60);

            // Notify member
            if (tableExists($conn, 'notifications')) {
                executeQuery($conn,
                    "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'general', 'Checked Out', ?, NOW())",
                    "is", [$scannedUserId, "You checked out at " . date('g:i A') . ". Session: {$duration} minutes."]
                );
            }

            // Get fresh capacity
            $capacity = getGymCapacity($conn);

            echo json_encode([
                'success' => true,
                'action' => 'check_out',
                'message' => 'Check-out recorded!',
                'member' => [
                    'id' => $member['id'],
                    'fullname' => $member['fullname'],
                    'username' => $member['username'],
                    'profile_image' => $member['profile_image'],
                ],
                'duration' => $duration,
                'time' => date('g:i A'),
                'membership_valid' => $membershipValid,
                'capacity' => $capacity,
            ]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to record check-out.']);
            exit;
        }
    } else {
        // CHECK-IN
        $ok = executeQuery($conn, "INSERT INTO attendance (user_id, check_in_time) VALUES (?, NOW())", "i", [$scannedUserId]);

        if ($ok) {
            if (tableExists($conn, 'notifications')) {
                executeQuery($conn,
                    "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'general', 'Checked In', ?, NOW())",
                    "is", [$scannedUserId, "Welcome! You checked in at " . date('g:i A') . ". Have a great workout!"]
                );
            }

            $capacity = getGymCapacity($conn);

            echo json_encode([
                'success' => true,
                'action' => 'check_in',
                'message' => 'Check-in recorded!',
                'member' => [
                    'id' => $member['id'],
                    'fullname' => $member['fullname'],
                    'username' => $member['username'],
                    'profile_image' => $member['profile_image'],
                ],
                'time' => date('g:i A'),
                'membership_valid' => $membershipValid,
                'membership_msg' => $membershipMsg,
                'capacity' => $capacity,
            ]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to record check-in.']);
            exit;
        }
    }
}

// ============================================
// HANDLE LIVE CAPACITY FETCH (AJAX)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_capacity') {
    header('Content-Type: application/json');
    $capacity = getGymCapacity($conn);
    $capacity['timestamp'] = date('g:i:s A');
    echo json_encode(['success' => true, 'capacity' => $capacity]);
    exit;
}

// ============================================
// HANDLE RECENT CHECK-INS FETCH (AJAX)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_recent_checkins') {
    header('Content-Type: application/json');

    $checkins = [];
    if (tableExists($conn, 'attendance')) {
        $r = safeQuery($conn, "
            SELECT a.id, a.user_id, a.check_in_time, a.check_out_time,
                   u.fullname, u.username, u.profile_image,
                   TIMESTAMPDIFF(MINUTE, a.check_in_time, IFNULL(a.check_out_time, NOW())) AS duration_min
            FROM attendance a
            INNER JOIN users u ON a.user_id = u.id
            WHERE a.check_in_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY a.check_in_time DESC
            LIMIT 10
        ");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $initials = strtoupper(substr($row['fullname'] ?? 'U', 0, 1));
                if (strpos($row['fullname'] ?? '', ' ') !== false) {
                    $parts = explode(' ', $row['fullname']);
                    $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
                }
                $checkins[] = [
                    'id' => (int)$row['id'],
                    'user_id' => (int)$row['user_id'],
                    'fullname' => $row['fullname'],
                    'username' => $row['username'],
                    'profile_image' => $row['profile_image'],
                    'initials' => $initials,
                    'check_in' => date('g:i A', strtotime($row['check_in_time'])),
                    'check_out' => $row['check_out_time'] ? date('g:i A', strtotime($row['check_out_time'])) : null,
                    'is_active' => empty($row['check_out_time']),
                    'duration_min' => (int)$row['duration_min'],
                    'time_ago' => timeAgo($row['check_in_time']),
                ];
            }
        }
    }

    echo json_encode(['success' => true, 'checkins' => $checkins]);
    exit;
}

// ============================================
// HANDLE SESSION ACTIONS
// ============================================
$flashMessage = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_message'] = 'This form has expired or is invalid. Please refresh the page and try again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: admin-sessions.php');
        exit;
    }

    $sessionId = (int)($_POST['session_id'] ?? 0);
    $action = $_POST['action'];
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($sessionId > 0) {

        // Fetch the session
        $getStmt = $conn->prepare("
            SELECT s.*, u.fullname, u.email, u.id AS user_id, u.username
            FROM sessions s
            INNER JOIN users u ON s.user_id = u.id
            WHERE s.id = ?
            LIMIT 1
        ");
        $getStmt->bind_param("i", $sessionId);
        $getStmt->execute();
        $sessionRes = $getStmt->get_result()->fetch_assoc();
        $getStmt->close();

        if ($sessionRes) {

            switch ($action) {

                case 'approve':
                    $ok = executeQuery($conn,
                        "UPDATE sessions SET status = 'Booked' WHERE id = ?",
                        "i", [$sessionId]
                    );

                    if ($ok) {
                        if (tableExists($conn, 'notifications')) {
                            $dateStr = date('F j, Y', strtotime($sessionRes['session_date']));
                            $timeStr = date('g:i A', strtotime($sessionRes['session_time']));
                            $notifTitle = "Session Confirmed";
                            $notifMsg = "Your {$sessionRes['session_type']} session on {$dateStr} at {$timeStr} has been confirmed. See you there!";
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'session', ?, ?, NOW())",
                                "iss", [$sessionRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }

                        $flashMessage = "Session booked for <strong>" . htmlspecialchars($sessionRes['fullname']) . "</strong>.";
                        $flashType = 'success';
                    } else {
                        $flashMessage = "Failed to book session.";
                        $flashType = 'error';
                    }
                    break;

                case 'reject':
                    $ok = executeQuery($conn,
                        "UPDATE sessions SET status = 'Rejected' WHERE id = ?",
                        "i", [$sessionId]
                    );

                    if ($ok) {
                        if (tableExists($conn, 'notifications')) {
                            $dateStr = date('F j, Y', strtotime($sessionRes['session_date']));
                            $notifTitle = "Session Request Declined";
                            $notifMsg = "Unfortunately, your {$sessionRes['session_type']} session request on {$dateStr} was declined." . 
                                        (!empty($adminNote) ? " Reason: {$adminNote}" : "");
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'session', ?, ?, NOW())",
                                "iss", [$sessionRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }

                        $flashMessage = "Session request rejected.";
                        $flashType = 'success';
                    }
                    break;

                case 'complete':
                    $ok = executeQuery($conn,
                        "UPDATE sessions SET status = 'Completed' WHERE id = ?",
                        "i", [$sessionId]
                    );

                    if ($ok) {
                        if (tableExists($conn, 'notifications')) {
                            $notifTitle = "Session Completed";
                            $notifMsg = "Great job completing your {$sessionRes['session_type']} session! Keep up the momentum.";
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'session', ?, ?, NOW())",
                                "iss", [$sessionRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }

                        $flashMessage = "Session marked as completed.";
                        $flashType = 'success';
                    }
                    break;

                case 'cancel':
                    $ok = executeQuery($conn,
                        "UPDATE sessions SET status = 'Cancelled' WHERE id = ?",
                        "i", [$sessionId]
                    );

                    if ($ok) {
                        if (tableExists($conn, 'notifications')) {
                            $dateStr = date('F j, Y', strtotime($sessionRes['session_date']));
                            $notifTitle = "Session Cancelled";
                            $notifMsg = "Your {$sessionRes['session_type']} session on {$dateStr} has been cancelled." .
                                        (!empty($adminNote) ? " Reason: {$adminNote}" : "");
                            executeQuery($conn,
                                "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'session', ?, ?, NOW())",
                                "iss", [$sessionRes['user_id'], $notifTitle, $notifMsg]
                            );
                        }

                        $flashMessage = "Session cancelled.";
                        $flashType = 'success';
                    }
                    break;

                case 'reschedule':
                    $newDate = $_POST['new_date'] ?? '';
                    $newTime = $_POST['new_time'] ?? '';

                    if (empty($newDate) || empty($newTime)) {
                        $flashMessage = "Please provide a new date and time.";
                        $flashType = 'error';
                    } else {
                        $ok = executeQuery($conn,
                            "UPDATE sessions 
                             SET session_date = ?, session_time = ?, status = 'Booked' 
                             WHERE id = ?",
                            "ssi", [$newDate, $newTime, $sessionId]
                        );

                        if ($ok) {
                            if (tableExists($conn, 'notifications')) {
                                $dateStr = date('F j, Y', strtotime($newDate));
                                $timeStr = date('g:i A', strtotime($newTime));
                                $notifTitle = "Session Rescheduled";
                                $notifMsg = "Your {$sessionRes['session_type']} session has been rescheduled to {$dateStr} at {$timeStr}.";
                                executeQuery($conn,
                                    "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'session', ?, ?, NOW())",
                                    "iss", [$sessionRes['user_id'], $notifTitle, $notifMsg]
                                );
                            }

                            $flashMessage = "Session rescheduled successfully.";
                            $flashType = 'success';
                        }
                    }
                    break;

                case 'delete':
                    $ok = executeQuery($conn, "DELETE FROM sessions WHERE id = ?", "i", [$sessionId]);
                    if ($ok) {
                        $flashMessage = "Session record deleted permanently.";
                        $flashType = 'success';
                    }
                    break;
            }
        }
    }

    if (!empty($flashMessage)) {
        $_SESSION['flash_message'] = $flashMessage;
        $_SESSION['flash_type'] = $flashType;
        header("Location: admin-sessions.php" . (!empty($_POST['redirect_query']) ? '?' . $_POST['redirect_query'] : ''));
        exit;
    }
}

if (!empty($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// ============================================
// FILTERS
// ============================================
$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['type'] ?? 'all';
$dateFilter = $_GET['date'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build WHERE
$where = ["1=1"];
$params = [];
$types = '';

if ($statusFilter !== 'all') {
    $where[] = "s.status = ?";
    $params[] = ucfirst($statusFilter);
    $types .= 's';
}

if ($typeFilter !== 'all') {
    $where[] = "s.session_type = ?";
    $params[] = $typeFilter;
    $types .= 's';
}

if ($dateFilter === 'today') {
    $where[] = "s.session_date = CURDATE()";
} elseif ($dateFilter === 'upcoming') {
    $where[] = "s.session_date >= CURDATE()";
} elseif ($dateFilter === 'past') {
    $where[] = "s.session_date < CURDATE()";
} elseif ($dateFilter === 'week') {
    $where[] = "YEARWEEK(s.session_date, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($dateFilter === 'month') {
    $where[] = "MONTH(s.session_date) = MONTH(CURDATE()) AND YEAR(s.session_date) = YEAR(CURDATE())";
}

if (!empty($search)) {
    $where[] = "(u.fullname LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

$whereSQL = implode(' AND ', $where);

// ============================================
// COUNT FOR PAGINATION
// ============================================
$totalRecords = 0;
$countSQL = "SELECT COUNT(*) AS total 
             FROM sessions s 
             INNER JOIN users u ON s.user_id = u.id 
             WHERE {$whereSQL}";

if (!empty($params)) {
    $stmt = $conn->prepare($countSQL);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r && $row = $r->fetch_assoc()) $totalRecords = (int)$row['total'];
    $stmt->close();
} else {
    $r = $conn->query($countSQL);
    if ($r && $row = $r->fetch_assoc()) $totalRecords = (int)$row['total'];
}

$totalPages = max(1, ceil($totalRecords / $perPage));

// ============================================
// FETCH SESSIONS
// ============================================
$sessions = [];
$hasBranchId = columnExists($conn, 'sessions', 'branch_id');
$selectExtra = $hasBranchId ? ", s.branch_id" : "";

$fetchSQL = "
    SELECT s.id, s.session_type, s.session_date, s.session_time, s.status, 
           s.created_at{$selectExtra},
           u.id AS user_id, u.fullname, u.email, u.username, u.phone, u.profile_image
    FROM sessions s
    INNER JOIN users u ON s.user_id = u.id
    WHERE {$whereSQL}
    ORDER BY 
        CASE s.status 
            WHEN 'Pending' THEN 1
            WHEN 'Booked' THEN 2
            WHEN 'Completed' THEN 3
            WHEN 'Cancelled' THEN 4
            WHEN 'Rejected' THEN 5
        END,
        s.session_date DESC, s.session_time DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($fetchSQL);
if ($stmt) {
    $fetchTypes = $types . 'ii';
    $fetchParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($fetchTypes, ...$fetchParams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sessions[] = $row;
    }
    $stmt->close();
}

// ============================================
// STATS
// ============================================
$stats = [
    'total' => 0,
    'pending' => 0,
    'booked' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'rejected' => 0,
    'today' => 0,
    'this_month' => 0,
];

$r = $conn->query("SELECT COUNT(*) AS total FROM sessions");
if ($r && $row = $r->fetch_assoc()) $stats['total'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM sessions WHERE status = 'Pending'");
if ($r && $row = $r->fetch_assoc()) $stats['pending'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM sessions WHERE status = 'Booked'");
if ($r && $row = $r->fetch_assoc()) $stats['booked'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM sessions WHERE status = 'Completed'");
if ($r && $row = $r->fetch_assoc()) $stats['completed'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM sessions WHERE status = 'Cancelled'");
if ($r && $row = $r->fetch_assoc()) $stats['cancelled'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM sessions WHERE status = 'Rejected'");
if ($r && $row = $r->fetch_assoc()) $stats['rejected'] = (int)$row['total'];

$r = $conn->query("SELECT COUNT(*) AS total FROM sessions WHERE session_date = CURDATE()");
if ($r && $row = $r->fetch_assoc()) $stats['today'] = (int)$row['total'];

$r = $conn->query("
    SELECT COUNT(*) AS total FROM sessions 
    WHERE MONTH(session_date) = MONTH(CURDATE()) 
    AND YEAR(session_date) = YEAR(CURDATE())
");
if ($r && $row = $r->fetch_assoc()) $stats['this_month'] = (int)$row['total'];

// ============================================
// SESSION TYPES FOR FILTER DROPDOWN
// ============================================
$sessionTypes = [];
if (tableExists($conn, 'session_offers')) {
    $r = safeQuery($conn, "SELECT DISTINCT session_type FROM session_offers ORDER BY session_type ASC");
    if ($r) {
        while ($row = $r->fetch_assoc()) $sessionTypes[] = $row['session_type'];
    }
}
if (empty($sessionTypes)) {
    $sessionTypes = ['Yoga', 'HIIT', 'Boxing', 'Pilates', 'Zumba', 'CrossFit'];
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function timeAgo($datetime) {
    if (empty($datetime)) return '—';
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $ts);
}

function getStatusClass($status) {
    $s = strtolower($status);
    if ($s === 'booked') return 'success';
    if ($s === 'pending') return 'warning';
    if ($s === 'completed') return 'info';
    if (in_array($s, ['cancelled', 'rejected'])) return 'danger';
    return 'info';
}

function getStatusIcon($status) {
    $s = strtolower($status);
    if ($s === 'booked') return 'check-circle-fill';
    if ($s === 'pending') return 'hourglass-split';
    if ($s === 'completed') return 'patch-check-fill';
    if ($s === 'cancelled') return 'slash-circle-fill';
    if ($s === 'rejected') return 'x-circle-fill';
    return 'circle-fill';
}

function getSessionIcon($type) {
    $t = strtolower($type);
    if (strpos($t, 'yoga') !== false) return 'flower1';
    if (strpos($t, 'hiit') !== false) return 'lightning-charge-fill';
    if (strpos($t, 'boxing') !== false) return 'shield-fill';
    if (strpos($t, 'pilates') !== false) return 'activity';
    if (strpos($t, 'zumba') !== false) return 'music-note-beamed';
    if (strpos($t, 'crossfit') !== false) return 'trophy-fill';
    return 'heart-pulse-fill';
}

function getSessionColor($type) {
    $t = strtolower($type);
    if (strpos($t, 'yoga') !== false) return '#8b5cf6';
    if (strpos($t, 'hiit') !== false) return '#ef4444';
    if (strpos($t, 'boxing') !== false) return '#f59e0b';
    if (strpos($t, 'pilates') !== false) return '#10b981';
    if (strpos($t, 'zumba') !== false) return '#ec4899';
    if (strpos($t, 'crossfit') !== false) return '#0d849c';
    return '#005b9f';
}

function daysUntil($date) {
    if (empty($date)) return null;
    return (int)floor((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
}

function buildQuery($overrides = []) {
    $params = array_merge($_GET, $overrides);
    return http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
}

// Fetch initial capacity
$initialCapacity = getGymCapacity($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sessions - FitSense Admin</title>
    <link rel="icon" type="image/png" href="../uploads/umak-gym-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        :root {
            --umak-blue: #005b9f;
            --umak-blue-dark: #00467a;
            --umak-yellow: #ffce00;
            --umak-red: #d32f2f;
            --umak-green: #2e7d32;
            --umak-orange: #f59e0b;
            --sidebar-width: 260px;
            --bg: #f4f6f9;
            --card-bg: #ffffff;
            --text: #1f2937;
            --text-muted: #6b7280;
            --border: #e5e7eb;
        }

        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { margin: 0; background-color: var(--bg); color: var(--text); }

        /* SIDEBAR */
        .sidebar {
            position: fixed; left: 0; top: 0;
            width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, #005b9f 0%, #003a6b 100%);
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
            width: 45px; height: 45px; border-radius: 10px;
            background: rgba(255,255,255,0.1); padding: 3px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; overflow: hidden;
            border: 1px solid rgba(255,255,255,0.15);
        }

        .sidebar-brand .logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }

        .sidebar-brand .brand-info h3 { margin: 0; font-size: 16px; font-weight: 900; letter-spacing: 1.5px; }
        .sidebar-brand .brand-info p { margin: 2px 0 0; font-size: 10px; opacity: 0.65; text-transform: uppercase; letter-spacing: 1.5px; }

        .admin-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 800;
            letter-spacing: 0.5px; text-transform: uppercase;
            margin-top: 6px;
        }
        .admin-badge.super { background: rgba(255,206,0,0.2); color: var(--umak-yellow); border: 1px solid rgba(255,206,0,0.3); }
        .admin-badge.regular { background: rgba(74,222,128,0.2); color: #4ade80; border: 1px solid rgba(74,222,128,0.3); }

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

        /* MAIN WRAPPER */
        .main-wrapper { margin-left: var(--sidebar-width); min-height: 100vh; padding-bottom: 40px; }

        .top-header {
            background: white; padding: 15px 30px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04); position: sticky; top: 0;
            z-index: 100; border-bottom: 3px solid var(--umak-yellow);
        }

        .header-left { display: flex; align-items: center; gap: 15px; }
        .sidebar-toggle {
            background: none; border: none; font-size: 22px;
            color: var(--umak-blue); cursor: pointer; display: none; padding: 5px;
        }
        .header-left h2 { margin: 0; font-size: 20px; color: var(--umak-blue); font-weight: 800; }
        .header-left .subtitle { font-size: 12px; color: var(--text-muted); margin: 0; }
        .header-right { display: flex; align-items: center; gap: 12px; }

        .header-btn {
            background: #f4f6f9; border: none; width: 42px; height: 42px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            cursor: pointer; position: relative; font-size: 18px;
            color: var(--umak-blue); transition: all 0.2s;
            text-decoration: none;
        }
        .header-btn:hover { background: #e5eef5; }

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
            font-weight: bold; font-size: 16px; overflow: hidden;
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }

        /* CONTAINER */
        .container { max-width: 1400px; margin: 30px auto; padding: 0 25px; }

        .page-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 20px; margin-bottom: 25px; flex-wrap: wrap;
        }

        .page-title {
            font-size: 26px; font-weight: 800; color: var(--umak-blue);
            margin: 0 0 6px; display: flex; align-items: center; gap: 12px;
        }
        .page-title i {
            font-size: 28px;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .page-subtitle { color: var(--text-muted); font-size: 14px; margin: 0; }
        .page-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        .alert {
            padding: 14px 20px; border-radius: 12px; font-size: 14px;
            font-weight: 600; margin-bottom: 20px; display: flex;
            align-items: center; gap: 12px;
        }
        .alert-success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border-left: 4px solid var(--umak-green); }
        .alert-error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border-left: 4px solid var(--umak-red); }
        .alert i { font-size: 20px; }

        /* ============================================
           QR + CAPACITY DASHBOARD ROW
           ============================================ */
        .dashboard-row {
            display: grid;
            grid-template-columns: 1.2fr 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        @media (max-width: 1200px) {
            .dashboard-row { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 768px) {
            .dashboard-row { grid-template-columns: 1fr; }
        }

        /* ============================================
           QR SCANNER CARD
           ============================================ */
        .scanner-card {
            background: linear-gradient(135deg, #005b9f 0%, #003a6b 100%);
            border-radius: 20px;
            padding: 28px;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0,91,159,0.25);
        }

        .scanner-card::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(255,206,0,0.18) 0%, transparent 70%);
            border-radius: 50%;
        }

        .scanner-card::after {
            content: '';
            position: absolute;
            bottom: -60px; left: -60px;
            width: 180px; height: 180px;
            background: radial-gradient(circle, rgba(74,222,128,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .scanner-content { position: relative; z-index: 2; }

        .scanner-title {
            font-size: 18px;
            font-weight: 800;
            margin: 0 0 6px;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.3px;
        }

        .scanner-title i {
            font-size: 22px;
            color: var(--umak-yellow);
        }

        .scanner-desc {
            font-size: 13px;
            color: rgba(255,255,255,0.85);
            margin: 0 0 20px;
            line-height: 1.6;
        }

        .btn-scan {
            width: 100%;
            background: var(--umak-yellow);
            color: #333;
            border: none;
            padding: 15px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.25s;
            box-shadow: 0 8px 24px rgba(255,206,0,0.35);
            letter-spacing: 0.5px;
            text-transform: uppercase;
            font-family: inherit;
        }

        .btn-scan:hover {
            background: #ffb800;
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(255,206,0,0.45);
        }

        .btn-scan i { font-size: 18px; }

        .scanner-note {
            margin-top: 14px;
            font-size: 11.5px;
            color: rgba(255,255,255,0.7);
            line-height: 1.5;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .scanner-note i { margin-top: 2px; flex-shrink: 0; color: var(--umak-yellow); }

        /* ============================================
           CAPACITY WIDGET
           ============================================ */
        .capacity-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .capacity-card:hover {
            box-shadow: 0 12px 30px rgba(0,0,0,0.08);
        }

        .capacity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .capacity-title {
            font-size: 13px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .capacity-title i { font-size: 16px; color: var(--umak-blue); }

        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 10.5px;
            font-weight: 800;
            color: #10b981;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: pulseLive 2s infinite;
            box-shadow: 0 0 0 4px rgba(16,185,129,0.2);
        }

        @keyframes pulseLive {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.1); }
        }

        .capacity-main {
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin-bottom: 14px;
        }

        .capacity-current {
            font-size: 48px;
            font-weight: 900;
            color: #111827;
            line-height: 1;
            letter-spacing: -2px;
            transition: color 0.3s;
        }

        .capacity-sep {
            font-size: 24px;
            color: #9ca3af;
            font-weight: 400;
        }

        .capacity-max {
            font-size: 22px;
            color: var(--text-muted);
            font-weight: 700;
        }

        .capacity-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: auto;
        }

        .capacity-track {
            height: 12px;
            background: #f3f4f6;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 16px;
            position: relative;
        }

        .capacity-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.8s ease, background 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .capacity-fill::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .capacity-stats {
            display: flex;
            gap: 14px;
            padding-top: 14px;
            border-top: 1px dashed var(--border);
            flex-wrap: wrap;
        }

        .capacity-stat {
            flex: 1;
            min-width: 80px;
        }

        .capacity-stat-label {
            font-size: 10.5px;
            color: var(--text-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 4px;
        }

        .capacity-stat-value {
            font-size: 18px;
            font-weight: 800;
            color: #111827;
            letter-spacing: -0.3px;
        }

        .capacity-stat-value.success { color: var(--umak-green); }
        .capacity-stat-value.warning { color: #b45309; }
        .capacity-stat-value.danger { color: var(--umak-red); }

        .capacity-updated {
            position: absolute;
            bottom: 8px;
            right: 16px;
            font-size: 10px;
            color: #9ca3af;
            font-weight: 600;
        }

        /* ============================================
           RECENT CHECK-INS WIDGET
           ============================================ */
        .checkins-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
            max-height: 420px;
        }

        .checkins-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 2px solid var(--border);
        }

        .checkins-title {
            font-size: 13px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .checkins-title i { font-size: 16px; color: var(--umak-blue); }

        .checkins-count {
            background: #e0f2fe;
            color: #0369a1;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 800;
        }

        .checkins-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            overflow-y: auto;
            flex: 1;
            padding-right: 4px;
        }

        .checkins-list::-webkit-scrollbar { width: 5px; }
        .checkins-list::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }

        .checkin-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #f9fafb;
            border-left: 3px solid transparent;
            transition: all 0.2s;
        }

        .checkin-item:hover {
            background: #f0f7fc;
            transform: translateX(3px);
        }

        .checkin-item.active {
            border-left-color: #10b981;
            background: linear-gradient(90deg, rgba(16,185,129,0.06), transparent);
        }

        .checkin-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 13px;
            flex-shrink: 0;
            overflow: hidden;
            border: 2px solid white;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        .checkin-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .checkin-info { flex: 1; min-width: 0; }

        .checkin-name {
            font-size: 13.5px;
            font-weight: 800;
            color: #111827;
            margin: 0 0 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .checkin-meta {
            font-size: 11.5px;
            color: var(--text-muted);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .checkin-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .checkin-status.in {
            background: #dcfce7;
            color: #166534;
        }

        .checkin-status.out {
            background: #f3f4f6;
            color: #6b7280;
        }

        /* STATS CARDS */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px; margin-bottom: 25px;
        }

        .stat-box {
            background: white; border-radius: 16px; padding: 20px 22px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            display: flex; align-items: center; gap: 15px;
            transition: all 0.25s ease;
            text-decoration: none; color: inherit;
        }

        .stat-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }

        .stat-box.active {
            border-color: var(--umak-blue);
            box-shadow: 0 8px 20px rgba(0,91,159,0.12);
        }

        .stat-box-icon {
            width: 50px; height: 50px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
        }

        .stat-box-icon.blue { background: linear-gradient(135deg, rgba(0,91,159,0.12), rgba(0,132,209,0.18)); color: var(--umak-blue); }
        .stat-box-icon.green { background: linear-gradient(135deg, rgba(46,125,50,0.12), rgba(74,222,128,0.18)); color: var(--umak-green); }
        .stat-box-icon.orange { background: linear-gradient(135deg, rgba(245,158,11,0.12), rgba(251,191,36,0.18)); color: #b45309; }
        .stat-box-icon.red { background: linear-gradient(135deg, rgba(211,47,47,0.12), rgba(248,113,113,0.18)); color: var(--umak-red); }
        .stat-box-icon.purple { background: linear-gradient(135deg, rgba(139,92,246,0.12), rgba(167,139,250,0.18)); color: #7c3aed; }
        .stat-box-icon.teal { background: linear-gradient(135deg, rgba(13,132,156,0.12), rgba(6,182,212,0.18)); color: #0d849c; }

        .stat-box-info { flex: 1; min-width: 0; }
        .stat-box-label { font-size: 11px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .stat-box-value { font-size: 24px; font-weight: 800; color: #111827; line-height: 1.1; letter-spacing: -0.5px; }
        .stat-box-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 4px; }

        /* FILTER BAR */
        .filter-bar {
            background: white; border-radius: 16px; padding: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 22px;
        }

        .filter-row {
            display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
        }

        .search-wrapper { position: relative; flex: 1; min-width: 240px; }

        .search-wrapper i {
            position: absolute; left: 16px; top: 50%;
            transform: translateY(-50%); color: #9ca3af; font-size: 16px;
        }

        .search-wrapper input {
            width: 100%; padding: 12px 16px 12px 46px;
            border: 2px solid var(--border); border-radius: 10px;
            font-size: 14px; font-weight: 500; font-family: inherit;
            outline: none; transition: all 0.2s; background: #f9fafb;
            color: #111827;
        }

        .search-wrapper input:focus {
            border-color: var(--umak-blue); background: white;
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        .filter-select {
            padding: 12px 38px 12px 16px;
            border: 2px solid var(--border); border-radius: 10px;
            font-size: 13.5px; font-weight: 700; color: #374151;
            background: #f9fafb; cursor: pointer;
            outline: none; transition: all 0.2s;
            appearance: none; font-family: inherit;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%236b7280'%3e%3cpath d='M4.5 6l3.5 3.5L11.5 6z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }

        .filter-select:focus { border-color: var(--umak-blue); }

        .btn {
            padding: 11px 20px; border-radius: 10px; font-size: 13px;
            font-weight: 700; cursor: pointer; border: none;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; transition: all 0.25s ease; text-decoration: none;
            font-family: inherit; letter-spacing: 0.3px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white;
            box-shadow: 0 4px 12px rgba(0,91,159,0.25);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,91,159,0.35); color: white; }

        .btn-outline {
            background: white; color: var(--umak-blue);
            border: 2px solid var(--umak-blue);
        }
        .btn-outline:hover { background: var(--umak-blue); color: white; }

        .btn-success { background: linear-gradient(135deg, #2e7d32, #4ade80); color: white; }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(46,125,50,0.35); color: white; }

        .btn-danger { background: linear-gradient(135deg, #d32f2f, #ef4444); color: white; }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(211,47,47,0.3); color: white; }

        .btn-warning { background: linear-gradient(135deg, #f59e0b, #fbbf24); color: #333; }
        .btn-warning:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(245,158,11,0.35); color: #333; }

        .btn-info { background: linear-gradient(135deg, #0d849c, #06b6d4); color: white; }
        .btn-info:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(13,132,156,0.3); color: white; }

        /* TABLE */
        .table-card {
            background: white; border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            overflow: hidden;
        }

        .table-header {
            padding: 18px 24px; border-bottom: 2px solid var(--border);
            display: flex; justify-content: space-between;
            align-items: center; flex-wrap: wrap; gap: 12px;
        }

        .table-header h3 {
            margin: 0; font-size: 17px; font-weight: 800;
            color: var(--umak-blue);
            display: flex; align-items: center; gap: 10px;
        }

        .table-header h3 i { font-size: 20px; }

        .table-info {
            font-size: 13px; color: var(--text-muted); font-weight: 600;
        }

        .table-wrapper { overflow-x: auto; }

        .data-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }

        .data-table th {
            padding: 14px 16px; text-align: left;
            color: var(--text-muted); font-weight: 700;
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 1px; background: #f9fafb;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .data-table td {
            padding: 16px; text-align: left;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .data-table tbody tr { transition: background 0.2s; }
        .data-table tbody tr:hover { background: #f9fafb; }
        .data-table tbody tr:last-child td { border-bottom: none; }

        .data-table tr.pending-row {
            background: linear-gradient(90deg, rgba(245,158,11,0.04), transparent 50%);
            border-left: 3px solid var(--umak-orange);
        }

        .user-cell { display: flex; align-items: center; gap: 12px; }

        .user-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 16px; flex-shrink: 0;
            overflow: hidden; border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .user-info { min-width: 0; }
        .user-info .name {
            font-weight: 800; color: #111827; font-size: 14px;
            line-height: 1.2; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
            max-width: 200px;
        }
        .user-info .sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 11px; border-radius: 20px;
            font-size: 10.5px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .badge i { font-size: 9px; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-secondary { background: #f3f4f6; color: #4b5563; }

        .session-badge {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 14px; border-radius: 12px;
            font-size: 13px; font-weight: 800;
            letter-spacing: 0.3px; border: 2px solid; background: white;
        }

        .date-box {
            display: inline-flex; flex-direction: column;
            align-items: center; min-width: 66px;
            padding: 8px 10px; border-radius: 10px;
            background: linear-gradient(135deg, rgba(0,91,159,0.08), rgba(0,132,209,0.12));
            border: 1px solid rgba(0,91,159,0.15);
        }

        .date-box .day-name { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: var(--umak-blue); }
        .date-box .day-num { font-size: 20px; font-weight: 800; color: #111827; line-height: 1; margin: 2px 0; }
        .date-box .month { font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.8px; }

        .session-time { font-size: 15px; font-weight: 800; color: #111827; margin-top: 4px; }
        .relative-day { font-size: 11.5px; font-weight: 700; margin-top: 6px; }
        .relative-day.today { color: #b45309; }
        .relative-day.upcoming { color: #166534; }
        .relative-day.past { color: #6b7280; }

        .action-group { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }

        .action-btn {
            padding: 7px 12px; border-radius: 8px;
            font-size: 11.5px; font-weight: 800;
            cursor: pointer; border: none;
            display: inline-flex; align-items: center; gap: 5px;
            transition: all 0.2s ease;
            text-transform: uppercase; letter-spacing: 0.3px;
            font-family: inherit; white-space: nowrap;
        }

        .action-btn.approve { background: linear-gradient(135deg, #2e7d32, #4ade80); color: white; box-shadow: 0 2px 8px rgba(46,125,50,0.25); }
        .action-btn.approve:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(46,125,50,0.35); }

        .action-btn.reject { background: linear-gradient(135deg, #d32f2f, #ef4444); color: white; box-shadow: 0 2px 8px rgba(211,47,47,0.25); }
        .action-btn.reject:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(211,47,47,0.35); }

        .action-btn.complete { background: linear-gradient(135deg, #0d849c, #06b6d4); color: white; }
        .action-btn.complete:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(13,132,156,0.35); }

        .action-btn.reschedule { background: white; color: var(--umak-blue); border: 1.5px solid var(--umak-blue); }
        .action-btn.reschedule:hover { background: var(--umak-blue); color: white; }

        .action-btn.cancel { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .action-btn.cancel:hover { background: #fde68a; }

        .icon-btn {
            width: 34px; height: 34px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; border: none; font-size: 14px;
            transition: all 0.2s ease;
            background: #f3f4f6; color: #4b5563;
            text-decoration: none;
        }

        .icon-btn:hover { transform: translateY(-2px); }
        .icon-btn.view:hover { background: rgba(0,91,159,0.15); color: var(--umak-blue); }
        .icon-btn.delete:hover { background: rgba(211,47,47,0.15); color: var(--umak-red); }

        .empty-state { text-align: center; padding: 70px 20px; }

        .empty-state-icon {
            width: 90px; height: 90px; margin: 0 auto 20px;
            background: rgba(0,91,159,0.08); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 40px; color: var(--umak-blue);
        }

        .empty-state h3 { margin: 0 0 10px; color: var(--umak-blue); font-size: 20px; font-weight: 800; }

        .empty-state p {
            color: var(--text-muted); font-size: 14px;
            margin: 0 0 22px; line-height: 1.6;
            max-width: 400px; margin-left: auto; margin-right: auto;
        }

        .pagination {
            display: flex; justify-content: center; align-items: center;
            gap: 8px; padding: 20px 24px;
            border-top: 1px solid var(--border);
            background: #fafbfc; flex-wrap: wrap;
        }

        .page-link {
            min-width: 40px; height: 40px; padding: 0 14px;
            border-radius: 10px; display: inline-flex;
            align-items: center; justify-content: center;
            font-size: 13.5px; font-weight: 700;
            color: #4b5563; background: white;
            border: 1.5px solid var(--border);
            text-decoration: none; transition: all 0.2s ease;
            cursor: pointer;
        }

        .page-link:hover:not(.disabled):not(.active) {
            background: #f3f4f6;
            border-color: var(--umak-blue);
            color: var(--umak-blue);
        }

        .page-link.active {
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; border-color: transparent;
            box-shadow: 0 4px 12px rgba(0,91,159,0.3);
        }

        .page-link.disabled { opacity: 0.4; cursor: not-allowed; }

        .page-info { font-size: 13px; color: var(--text-muted); font-weight: 600; margin: 0 8px; }

        /* MODAL */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; visibility: hidden;
            transition: all 0.3s ease;
            z-index: 2000; padding: 20px;
        }

        .modal-overlay.open { opacity: 1; visibility: visible; }

        .modal {
            background: white; border-radius: 18px;
            width: 100%; max-width: 520px;
            max-height: 90vh; overflow-y: auto;
            transform: scale(0.9);
            transition: transform 0.3s ease;
            box-shadow: 0 25px 70px rgba(0,0,0,0.3);
        }

        .modal-overlay.open .modal { transform: scale(1); }

        .modal-header {
            padding: 22px 26px;
            border-bottom: 2px solid var(--border);
            display: flex; justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0; font-size: 18px; font-weight: 800;
            color: var(--umak-blue);
            display: flex; align-items: center; gap: 10px;
        }

        .modal-close {
            background: #f3f4f6; border: none;
            width: 36px; height: 36px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center;
            justify-content: center; font-size: 16px;
            color: var(--text-muted); transition: all 0.2s;
        }

        .modal-close:hover { background: #e5e7eb; transform: rotate(90deg); }

        .modal-body { padding: 24px 26px; }

        .modal-detail-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; }

        .modal-detail-item {
            display: flex; justify-content: space-between;
            align-items: center; padding: 12px 16px;
            background: #f9fafb; border-radius: 10px;
            border: 1px solid var(--border);
        }

        .modal-detail-label {
            font-size: 12px; font-weight: 700;
            color: var(--text-muted); text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .modal-detail-value {
            font-size: 14px; font-weight: 800;
            color: #111827; text-align: right;
        }

        .form-group { margin-bottom: 16px; }

        .form-label {
            display: block;
            font-size: 12px; font-weight: 800;
            color: #374151; text-transform: uppercase;
            letter-spacing: 0.8px; margin-bottom: 8px;
        }

        .form-control {
            width: 100%; padding: 12px 14px;
            border: 2px solid var(--border); border-radius: 10px;
            font-size: 14px; font-weight: 500;
            color: #111827; background: #f9fafb;
            transition: all 0.25s ease;
            font-family: inherit; outline: none;
        }

        .form-control:focus {
            border-color: var(--umak-blue); background: white;
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        textarea.form-control { resize: vertical; min-height: 100px; line-height: 1.6; }

        .form-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        .modal-footer {
            padding: 18px 26px 22px;
            display: flex; gap: 10px; justify-content: flex-end;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .modal-danger-text {
            background: #fee2e2; border-left: 4px solid var(--umak-red);
            padding: 14px 18px; border-radius: 10px;
            font-size: 13.5px; color: #991b1b;
            line-height: 1.6; margin-bottom: 16px;
        }

        .modal-warning-text {
            background: #fef3c7; border-left: 4px solid var(--umak-orange);
            padding: 14px 18px; border-radius: 10px;
            font-size: 13.5px; color: #78350f;
            line-height: 1.6; margin-bottom: 16px;
        }

        .modal-info-text {
            background: #f0f7fc; border-left: 4px solid var(--umak-blue);
            padding: 14px 18px; border-radius: 10px;
            font-size: 13.5px; color: #1e40af;
            line-height: 1.6; margin-bottom: 16px;
        }

        /* SCANNER MODAL */
        .scanner-modal {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.85);
            backdrop-filter: blur(8px);
            z-index: 3000; display: none;
            align-items: center; justify-content: center;
            padding: 20px;
        }

        .scanner-modal.open { display: flex; }

        .scanner-modal-content {
            background: white; border-radius: 24px;
            max-width: 520px; width: 100%;
            max-height: 95vh; overflow-y: auto;
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
            display: flex; justify-content: space-between; align-items: center;
        }

        .scanner-modal-header h3 {
            margin: 0; font-size: 18px; font-weight: 800;
            color: var(--umak-blue);
            display: flex; align-items: center; gap: 10px;
        }

        .scanner-modal-close {
            background: #f3f4f6; border: none;
            width: 38px; height: 38px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center;
            justify-content: center; font-size: 16px;
            color: var(--text-muted); transition: all 0.2s;
        }

        .scanner-modal-close:hover { background: #e5e7eb; transform: rotate(90deg); }

        .scanner-modal-body { padding: 24px; }

        #qr-reader {
            width: 100%; border-radius: 16px;
            overflow: hidden; background: #000;
            margin-bottom: 16px;
        }

        #qr-reader video { width: 100% !important; height: auto !important; display: block; }

        .scanner-status {
            text-align: center; padding: 12px 18px;
            border-radius: 12px; font-size: 13.5px;
            font-weight: 600; margin-bottom: 16px;
            line-height: 1.5;
        }

        .scanner-status.info { background: #dbeafe; color: #1e40af; }
        .scanner-status.success { background: #d1fae5; color: #065f46; }
        .scanner-status.error { background: #fee2e2; color: #991b1b; }

        .scanner-divider {
            display: flex; align-items: center; gap: 14px;
            margin: 20px 0; color: #9ca3af;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1.5px;
        }

        .scanner-divider::before,
        .scanner-divider::after {
            content: ''; flex: 1; height: 1px; background: var(--border);
        }

        .manual-input-group { display: flex; gap: 10px; }

        .manual-input-group input {
            flex: 1; padding: 13px 16px;
            border: 2px solid var(--border); border-radius: 12px;
            font-size: 14px; font-family: inherit;
            font-weight: 600; outline: none; transition: 0.2s;
        }

        .manual-input-group input:focus {
            border-color: var(--umak-blue);
            box-shadow: 0 0 0 4px rgba(0,91,159,0.1);
        }

        .manual-input-group button {
            padding: 0 24px; border: none;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; border-radius: 12px;
            font-weight: 800; cursor: pointer;
            font-family: inherit; transition: 0.2s;
        }

        .manual-input-group button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,91,159,0.35);
        }

        .scan-result {
            padding: 20px; border-radius: 16px;
            margin-bottom: 20px; text-align: center;
            animation: resultPop 0.4s ease;
        }

        @keyframes resultPop {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .scan-result.success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 2px solid #10b981; }
        .scan-result.error { background: linear-gradient(135deg, #fee2e2, #fecaca); border: 2px solid #ef4444; }

        .scan-result-icon {
            width: 70px; height: 70px; margin: 0 auto 14px;
            border-radius: 50%; display: flex; align-items: center;
            justify-content: center; font-size: 36px;
            animation: popIn 0.5s ease;
        }

        @keyframes popIn {
            0% { transform: scale(0); }
            50% { transform: scale(1.15); }
            100% { transform: scale(1); }
        }

        .scan-result.success .scan-result-icon { background: #10b981; color: white; }
        .scan-result.error .scan-result-icon { background: #ef4444; color: white; }

        .scan-result-title { font-size: 20px; font-weight: 800; margin: 0 0 6px; }
        .scan-result.success .scan-result-title { color: #065f46; }
        .scan-result.error .scan-result-title { color: #991b1b; }

        .scan-result-message { font-size: 14px; margin: 0; font-weight: 600; line-height: 1.5; }
        .scan-result.success .scan-result-message { color: #065f46; }
        .scan-result.error .scan-result-message { color: #991b1b; }

        .scan-member-info {
            display: flex; align-items: center; gap: 14px;
            background: rgba(255,255,255,0.7);
            border-radius: 12px; padding: 14px;
            margin-top: 16px;
        }

        .scan-member-avatar {
            width: 54px; height: 54px; border-radius: 50%;
            background: linear-gradient(135deg, #005b9f, #0084d1);
            color: white; display: flex; align-items: center;
            justify-content: center; font-weight: 800; font-size: 20px;
            overflow: hidden; flex-shrink: 0;
        }

        .scan-member-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .scan-member-detail { text-align: left; flex: 1; min-width: 0; }
        .scan-member-detail h4 { margin: 0 0 3px; font-size: 15px; font-weight: 800; color: #111827; }
        .scan-member-detail p { margin: 0; font-size: 12.5px; color: #4b5563; font-weight: 600; }

        .scan-meta { display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap; justify-content: center; }

        .scan-meta-item {
            background: rgba(255,255,255,0.7);
            padding: 6px 12px; border-radius: 10px;
            font-size: 12px; font-weight: 700;
            color: #374151;
            display: inline-flex; align-items: center; gap: 6px;
        }

        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* RESPONSIVE */
        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .sidebar-toggle { display: block; }
        }

        @media (max-width: 768px) {
            .top-header { padding: 12px 15px; }
            .header-left h2 { font-size: 16px; }
            .container { padding: 0 15px; margin: 20px auto; }
            .page-title { font-size: 20px; }
            .filter-row { flex-direction: column; align-items: stretch; }
            .search-wrapper { min-width: auto; }
            .filter-select { width: 100%; }
            .data-table { font-size: 12px; }
            .data-table th, .data-table td { padding: 12px 8px; }
            .user-info .name { max-width: 140px; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .form-row-2 { grid-template-columns: 1fr; }
            .capacity-current { font-size: 36px; }
        }

        @media (max-width: 576px) {
            .stats-row { grid-template-columns: 1fr; }
            .user-info .name { max-width: 120px; font-size: 13px; }
            .user-avatar { width: 38px; height: 38px; font-size: 14px; }
            .page-link { min-width: 36px; height: 36px; padding: 0 10px; font-size: 12px; }
            .action-btn { padding: 6px 10px; font-size: 10.5px; }
            .date-box { min-width: 56px; padding: 6px 8px; }
            .date-box .day-num { font-size: 17px; }
            .session-time { font-size: 13px; }
        }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css">
<link rel="stylesheet" href="/PBL/css/admin-content-icons.css"></head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="logo">
            <img src="../uploads/umak-gym-logo.png" alt="UMak Gym">
        </div>
        <div class="brand-info">
            <h3>FITSENSE</h3>
            <p>Admin Panel</p>
            <span class="admin-badge <?= $isSuperAdmin ? 'super' : 'regular' ?>">
                <i class="bi bi-<?= $isSuperAdmin ? 'shield-fill-check' : 'shield-fill' ?>"></i>
                <?= $isSuperAdmin ? 'Super Admin' : 'Admin' ?>
            </span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Main</div>
        <a href="admin-dashboard.php" class="nav-item">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>

        <div class="nav-section-title">Management</div>
        <a href="admin-members.php" class="nav-item">
            <i class="bi bi-people-fill"></i><span>Members</span>
        </a>
        <a href="admin-memberships.php" class="nav-item">
            <i class="bi bi-ticket-perforated-fill"></i><span>Memberships</span>
            <?php if ($stats['pending'] > 0): ?>
                <span class="badge-count"><?= $stats['pending'] ?></span>
            <?php endif; ?>
        </a>
        <a href="admin-sessions.php" class="nav-item active">
            <i class="bi bi-calendar-event-fill"></i><span>Sessions</span>
            <?php if ($stats['pending'] > 0): ?>
                <span class="badge-count"><?= $stats['pending'] ?></span>
            <?php endif; ?>
        </a>
         <a href="admin-classes.php" class="nav-item">
            <i class="bi bi-calendar-event-fill"></i><span>Classes</span>
        </a>
        <a href="admin-payments.php" class="nav-item">
            <i class="bi bi-credit-card-fill"></i><span>Payment Logs</span>
        </a>

        <?php if ($isSuperAdmin): ?>
            <div class="nav-section-title">Super Admin</div>
            <a href="admin-franchise.php" class="nav-item">
                <i class="bi bi-shop"></i><span>Franchise Apps</span>
            </a>
            <a href="admin-admins.php" class="nav-item">
                <i class="bi bi-person-badge-fill"></i><span>Manage Admins</span>
            </a>
            <a href="admin-branches.php" class="nav-item">
                <i class="bi bi-geo-alt-fill"></i><span>Branches</span>
            </a>
        <?php endif; ?>

        <div class="nav-section-title">Insights</div>
        <a href="admin-reports.php" class="nav-item">
            <i class="bi bi-graph-up-arrow"></i><span>Reports</span>
        </a>

        <div class="nav-section-title">Account</div>
        <a href="admin-profile.php" class="nav-item">
            <i class="bi bi-person-circle"></i><span>My Profile</span>
        </a>
        <a href="admin-settings.php" class="nav-item">
            <i class="bi bi-gear-fill"></i><span>Settings</span>
        </a>
        <div class="nav-divider"></div>
        <a href="admin-logout.php" class="nav-item nav-logout">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
    </nav>
</aside>

<!-- MAIN WRAPPER -->
<div class="main-wrapper">

    <header class="top-header">
        <div class="header-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h2>Sessions</h2>
                <p class="subtitle">Manage class bookings, gym attendance, and capacity</p>
            </div>
        </div>

        <div class="header-right">
            <a href="admin-dashboard.php" class="header-btn" title="Dashboard">
                <i class="bi bi-speedometer2"></i>
            </a>
            <a href="admin-notifications.php" class="header-btn" title="Notifications">
                <i class="bi bi-bell-fill"></i>
                <?php if ($stats['pending'] > 0): ?>
                    <span class="notif-badge"><?= $stats['pending'] ?></span>
                <?php endif; ?>
            </a>
            <button class="avatar-btn" onclick="window.location.href='admin-profile.php'">
                <div class="avatar">
                    <?php if (!empty($adminAvatar) && file_exists(__DIR__ . '/../' . $adminAvatar)): ?>
                        <img src="../<?= htmlspecialchars($adminAvatar) ?>" alt="">
                    <?php else: ?>
                        <?= htmlspecialchars($adminInitial) ?>
                    <?php endif; ?>
                </div>
            </button>
        </div>
    </header>

    <main class="container">

        <!-- PAGE HEADER -->
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="bi bi-calendar-event-fill"></i>
                    Sessions & Attendance
                </h1>
                <p class="page-subtitle">Scan QR codes to check members in/out and manage session bookings.</p>
            </div>
            <div class="page-actions">
                <a href="admin-sessions-export.php?<?= htmlspecialchars(buildQuery(['export' => 'csv'])) ?>"
                   class="btn btn-outline" target="_blank">
                    <i class="bi bi-download"></i> Export CSV
                </a>
                <a href="admin-session-offers.php" class="btn btn-primary">
                    <i class="bi bi-gear-fill"></i> Manage Session Types
                </a>
            </div>
        </div>

        <!-- FLASH MESSAGE -->
        <?php if (!empty($flashMessage)): ?>
            <div class="alert alert-<?= $flashType ?>">
                <i class="bi bi-<?= $flashType === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
                <div><?= $flashMessage ?></div>
            </div>
        <?php endif; ?>

        <!-- ============================================
             QR SCANNER + CAPACITY DASHBOARD
             ============================================ -->
        <div class="dashboard-row">

            <!-- QR SCANNER CARD -->
            <div class="scanner-card">
                <div class="scanner-content">
                    <h3 class="scanner-title">
                        <i class="bi bi-qr-code-scan"></i>
                        Quick QR Check-In
                    </h3>
                    <p class="scanner-desc">
                        Scan a member's QR code to instantly check them in or out. The system detects automatically.
                    </p>
                    <button class="btn-scan" onclick="openScanner()">
                        <i class="bi bi-camera-fill"></i>
                        Open QR Scanner
                    </button>
                    <div class="scanner-note">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>Point the camera at a member's QR code. Works on any device with a camera.</span>
                    </div>
                </div>
            </div>

            <!-- LIVE CAPACITY WIDGET -->
            <div class="capacity-card">
                <div class="capacity-header">
                    <div class="capacity-title">
                        <i class="bi bi-people-fill"></i>
                        Gym Capacity
                    </div>
                    <div class="live-indicator">
                        <span class="live-dot"></span> Live
                    </div>
                </div>

                <div class="capacity-main">
                    <span class="capacity-current" id="capacityCurrent"><?= $initialCapacity['current'] ?></span>
                    <span class="capacity-sep">/</span>
                    <span class="capacity-max" id="capacityMax"><?= $initialCapacity['max'] ?></span>
                    <span class="capacity-status-badge" id="capacityStatusBadge" style="background: <?= $initialCapacity['status_color'] ?>20; color: <?= $initialCapacity['status_color'] ?>;">
                        <i class="bi bi-circle-fill" style="font-size: 8px;"></i>
                        <span id="capacityStatusText"><?= $initialCapacity['status_label'] ?></span>
                    </span>
                </div>

                <div class="capacity-track">
                    <div class="capacity-fill" id="capacityFill" 
                         style="width: <?= $initialCapacity['percentage'] ?>%; background: <?= $initialCapacity['status_color'] ?>;"></div>
                </div>

                <div class="capacity-stats">
                    <div class="capacity-stat">
                        <div class="capacity-stat-label">Available</div>
                        <div class="capacity-stat-value success" id="capacityAvailable"><?= $initialCapacity['available'] ?> slots</div>
                    </div>
                    <div class="capacity-stat">
                        <div class="capacity-stat-label">Last 24h</div>
                        <div class="capacity-stat-value" id="capacityRecent"><?= $initialCapacity['recent_checkins'] ?> check-ins</div>
                    </div>
                </div>

                <div class="capacity-updated">
                    Updated: <span id="capacityUpdated"><?= date('g:i:s A') ?></span>
                </div>
            </div>

            <!-- RECENT CHECK-INS WIDGET -->
            <div class="checkins-card">
                <div class="checkins-header">
                    <h3 class="checkins-title">
                        <i class="bi bi-clock-history"></i>
                        Recent Check-Ins
                    </h3>
                    <span class="checkins-count" id="checkinsCount">0</span>
                </div>

                <div class="checkins-list" id="checkinsList">
                    <div style="text-align: center; padding: 30px 10px; color: #9ca3af; font-size: 12px;">
                        <i class="bi bi-arrow-clockwise" style="font-size: 20px; display: block; margin-bottom: 8px; animation: spin 1s linear infinite;"></i>
                        Loading...
                    </div>
                </div>
            </div>

        </div>

        <!-- STATS CARDS -->
        <div class="stats-row">
            <a href="?status=all&date=all" class="stat-box <?= ($statusFilter === 'all' && $dateFilter === 'all') ? 'active' : '' ?>">
                <div class="stat-box-icon blue"><i class="bi bi-collection-fill"></i></div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Total</div>
                    <div class="stat-box-value"><?= number_format($stats['total']) ?></div>
                    <div class="stat-box-sub"><?= $stats['this_month'] ?> this month</div>
                </div>
            </a>

            <a href="?status=pending" class="stat-box <?= $statusFilter === 'pending' ? 'active' : '' ?>">
                <div class="stat-box-icon orange"><i class="bi bi-hourglass-split"></i></div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Pending</div>
                    <div class="stat-box-value"><?= number_format($stats['pending']) ?></div>
                    <div class="stat-box-sub">Need review</div>
                </div>
            </a>

            <a href="?status=booked" class="stat-box <?= $statusFilter === 'booked' ? 'active' : '' ?>">
                <div class="stat-box-icon green"><i class="bi bi-check-circle-fill"></i></div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Booked</div>
                    <div class="stat-box-value"><?= number_format($stats['booked']) ?></div>
                    <div class="stat-box-sub">Upcoming</div>
                </div>
            </a>

            <a href="?status=completed" class="stat-box <?= $statusFilter === 'completed' ? 'active' : '' ?>">
                <div class="stat-box-icon teal"><i class="bi bi-patch-check-fill"></i></div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Completed</div>
                    <div class="stat-box-value"><?= number_format($stats['completed']) ?></div>
                    <div class="stat-box-sub">Attended</div>
                </div>
            </a>

            <a href="?date=today" class="stat-box <?= $dateFilter === 'today' ? 'active' : '' ?>">
                <div class="stat-box-icon purple"><i class="bi bi-calendar-check-fill"></i></div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Today</div>
                    <div class="stat-box-value"><?= number_format($stats['today']) ?></div>
                    <div class="stat-box-sub">Scheduled</div>
                </div>
            </a>

            <a href="?status=cancelled" class="stat-box <?= $statusFilter === 'cancelled' ? 'active' : '' ?>">
                <div class="stat-box-icon red"><i class="bi bi-slash-circle-fill"></i></div>
                <div class="stat-box-info">
                    <div class="stat-box-label">Cancelled</div>
                    <div class="stat-box-value"><?= number_format($stats['cancelled'] + $stats['rejected']) ?></div>
                    <div class="stat-box-sub">Cancelled + rejected</div>
                </div>
            </a>
        </div>

        <!-- FILTER BAR -->
        <form method="GET" class="filter-bar">
            <div class="filter-row">
                <div class="search-wrapper">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" placeholder="Search by member name, email, or username..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>

                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="booked" <?= $statusFilter === 'booked' ? 'selected' : '' ?>>Booked</option>
                    <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>

                <select name="type" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All Types</option>
                    <?php foreach ($sessionTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>>
                            <?= htmlspecialchars($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="date" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>All Dates</option>
                    <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="upcoming" <?= $dateFilter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                    <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="past" <?= $dateFilter === 'past' ? 'selected' : '' ?>>Past</option>
                </select>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel-fill"></i> Apply
                </button>

                <?php if (!empty($search) || $statusFilter !== 'all' || $typeFilter !== 'all' || $dateFilter !== 'all'): ?>
                    <a href="admin-sessions.php" class="btn btn-outline">
                        <i class="bi bi-x-lg"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- SESSIONS TABLE -->
        <div class="table-card">
            <div class="table-header">
                <h3>
                    <i class="bi bi-list-ul"></i>
                    Session Records
                    <?php if ($totalRecords > 0): ?>
                        <span style="background:#e0f2fe; color:#0369a1; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:800;">
                            <?= number_format($totalRecords) ?>
                        </span>
                    <?php endif; ?>
                </h3>
                <div class="table-info">
                    <?php if ($totalRecords > 0): ?>
                        Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($sessions)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="bi bi-calendar-x"></i></div>
                    <h3>No Sessions Found</h3>
                    <p>
                        <?php if (!empty($search) || $statusFilter !== 'all' || $typeFilter !== 'all' || $dateFilter !== 'all'): ?>
                            No sessions match your current filters. Try adjusting your search criteria.
                        <?php else: ?>
                            No session bookings have been made yet.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Session</th>
                                <th>Date & Time</th>
                                <th>Status</th>
                                <th>Booked</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $s):
                                $initials = strtoupper(substr($s['fullname'] ?? 'U', 0, 1));
                                if (strpos($s['fullname'] ?? '', ' ') !== false) {
                                    $parts = explode(' ', $s['fullname']);
                                    $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
                                }
                                $statusClass = getStatusClass($s['status']);
                                $statusIcon = getStatusIcon($s['status']);
                                $isPending = strtolower($s['status']) === 'pending';
                                $isBooked = strtolower($s['status']) === 'booked';
                                $isCompleted = strtolower($s['status']) === 'completed';
                                $sessionColor = getSessionColor($s['session_type']);
                                $sessionIcon = getSessionIcon($s['session_type']);

                                $sessionDate = strtotime($s['session_date']);
                                $dayName = date('D', $sessionDate);
                                $dayNum = date('j', $sessionDate);
                                $month = date('M', $sessionDate);

                                $daysUntil = daysUntil($s['session_date']);
                                if ($daysUntil < 0) { $relText = abs($daysUntil) . 'd ago'; $relClass = 'past'; }
                                elseif ($daysUntil == 0) { $relText = 'Today'; $relClass = 'today'; }
                                elseif ($daysUntil == 1) { $relText = 'Tomorrow'; $relClass = 'upcoming'; }
                                else { $relText = "In {$daysUntil} days"; $relClass = 'upcoming'; }
                            ?>
                                <tr class="<?= $isPending ? 'pending-row' : '' ?>">
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar">
                                                <?php if (!empty($s['profile_image']) && file_exists(__DIR__ . '/../' . $s['profile_image'])): ?>
                                                    <img src="../<?= htmlspecialchars($s['profile_image']) ?>" alt="">
                                                <?php else: ?>
                                                    <?= htmlspecialchars($initials) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="user-info">
                                                <div class="name"><?= htmlspecialchars($s['fullname']) ?></div>
                                                <div class="sub">@<?= htmlspecialchars($s['username']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="session-badge" style="border-color: <?= $sessionColor ?>; color: <?= $sessionColor ?>;">
                                            <i class="bi bi-<?= $sessionIcon ?>"></i>
                                            <?= htmlspecialchars($s['session_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div class="date-box">
                                                <div class="day-name"><?= $dayName ?></div>
                                                <div class="day-num"><?= $dayNum ?></div>
                                                <div class="month"><?= $month ?></div>
                                            </div>
                                            <div>
                                                <div class="session-time"><?= date('g:i A', strtotime($s['session_time'])) ?></div>
                                                <div class="relative-day <?= $relClass ?>"><?= $relText ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $statusClass ?>">
                                            <i class="bi bi-<?= $statusIcon ?>"></i>
                                            <?= htmlspecialchars($s['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-size: 12.5px; font-weight: 600; color: #374151;">
                                            <?= date('M j, Y', strtotime($s['created_at'])) ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                            <?= timeAgo($s['created_at']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-group" style="justify-content: flex-end;">
                                            <?php if ($isPending): ?>
                                                <button type="button" class="action-btn approve"
                                                        onclick="approveSession(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($s['fullname'])) ?>', '<?= htmlspecialchars($s['session_type']) ?>', '<?= date('M j, Y', $sessionDate) ?>', '<?= date('g:i A', strtotime($s['session_time'])) ?>')">
                                                    <i class="bi bi-check-circle-fill"></i> Approve
                                                </button>
                                                <button type="button" class="action-btn reject"
                                                        onclick="openRejectModal(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($s['fullname'])) ?>')">
                                                    <i class="bi bi-x-circle-fill"></i> Reject
                                                </button>
                                            <?php elseif ($isBooked): ?>
                                                <button type="button" class="action-btn complete"
                                                        onclick="completeSession(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($s['fullname'])) ?>')">
                                                    <i class="bi bi-patch-check-fill"></i> Complete
                                                </button>
                                                <button type="button" class="action-btn reschedule"
                                                        onclick="openRescheduleModal(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($s['fullname'])) ?>', '<?= $s['session_date'] ?>', '<?= $s['session_time'] ?>')">
                                                    <i class="bi bi-calendar-plus"></i> Reschedule
                                                </button>
                                                <button type="button" class="action-btn cancel"
                                                        onclick="cancelSession(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($s['fullname'])) ?>')">
                                                    <i class="bi bi-slash-circle"></i> Cancel
                                                </button>
                                            <?php elseif ($isCompleted): ?>
                                                <span style="font-size: 11.5px; color: var(--umak-green); font-weight: 800; display: inline-flex; align-items: center; gap: 5px;">
                                                    <i class="bi bi-check-circle-fill"></i> Session done
                                                </span>
                                            <?php else: ?>
                                                <span style="font-size: 11.5px; color: var(--text-muted); font-weight: 700;">No actions</span>
                                            <?php endif; ?>

                                            <a href="admin-member-view.php?id=<?= (int)$s['user_id'] ?>" class="icon-btn view" title="View Member">
                                                <i class="bi bi-eye-fill"></i>
                                            </a>

                                            <?php if (!$isPending): ?>
                                                <button type="button" class="icon-btn delete" title="Delete Record"
                                                        onclick="deleteSession(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($s['fullname'])) ?>')">
                                                    <i class="bi bi-trash3-fill"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <a href="?<?= htmlspecialchars(buildQuery(['page' => max(1, $page - 1)])) ?>"
                           class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        if ($startPage > 1) {
                            echo '<a href="?' . htmlspecialchars(buildQuery(['page' => 1])) . '" class="page-link">1</a>';
                            if ($startPage > 2) echo '<span class="page-info">...</span>';
                        }
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="?<?= htmlspecialchars(buildQuery(['page' => $i])) ?>"
                               class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <?php
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) echo '<span class="page-info">...</span>';
                            echo '<a href="?' . htmlspecialchars(buildQuery(['page' => $totalPages])) . '" class="page-link">' . $totalPages . '</a>';
                        }
                        ?>

                        <a href="?<?= htmlspecialchars(buildQuery(['page' => min($totalPages, $page + 1)])) ?>"
                           class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ============================================
     QR SCANNER MODAL
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

            <div id="scanResultContainer"></div>

            <div class="scanner-status info" id="scannerStatus">
                <i class="bi bi-info-circle-fill"></i>
                Point your camera at the member's QR code
            </div>

            <div id="qr-reader"></div>

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

<!-- APPROVE MODAL -->
<div class="modal-overlay" id="approveModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-check-circle-fill" style="color: var(--umak-green);"></i> Approve Session</h3>
            <button class="modal-close" onclick="closeModal('approveModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <div class="modal-detail-list">
                <div class="modal-detail-item">
                    <span class="modal-detail-label">Member</span>
                    <span class="modal-detail-value" id="approveMemberName">—</span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">Session</span>
                    <span class="modal-detail-value" id="approveSessionType">—</span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">Date</span>
                    <span class="modal-detail-value" id="approveDate">—</span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">Time</span>
                    <span class="modal-detail-value" id="approveTime">—</span>
                </div>
            </div>

            <div class="modal-info-text">
                <strong><i class="bi bi-info-circle-fill"></i> Note:</strong>
                Approving will book the session and send a confirmation notification.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('approveModal')">Cancel</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="approve">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="session_id" id="approveSessionId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle-fill"></i> Approve & Book
                </button>
            </form>
        </div>
    </div>
</div>

<!-- REJECT MODAL -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-x-circle-fill" style="color: var(--umak-red);"></i> Reject Session</h3>
            <button class="modal-close" onclick="closeModal('rejectModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="modal-detail-list">
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">Member</span>
                        <span class="modal-detail-value" id="rejectMemberName">—</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Reason (Optional)</label>
                    <textarea name="admin_note" class="form-control"
                              placeholder="e.g., No slots available, conflicting schedule..."></textarea>
                </div>

                <div class="modal-danger-text">
                    <strong><i class="bi bi-exclamation-triangle-fill"></i> Warning:</strong>
                    The member will be notified and can book another session.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
                <input type="hidden" name="action" value="reject">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="session_id" id="rejectSessionId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-x-circle-fill"></i> Reject Request
                </button>
            </div>
        </form>
    </div>
</div>

<!-- RESCHEDULE MODAL -->
<div class="modal-overlay" id="rescheduleModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-calendar-plus" style="color: var(--umak-blue);"></i> Reschedule Session</h3>
            <button class="modal-close" onclick="closeModal('rescheduleModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="modal-detail-list">
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">Member</span>
                        <span class="modal-detail-value" id="rescheduleMemberName">—</span>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label">New Date</label>
                        <input type="date" name="new_date" id="rescheduleNewDate" class="form-control"
                               min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Time</label>
                        <input type="time" name="new_time" id="rescheduleNewTime" class="form-control" required>
                    </div>
                </div>

                <div class="modal-info-text">
                    <strong><i class="bi bi-info-circle-fill"></i> Note:</strong>
                    The member will be notified of the new schedule.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('rescheduleModal')">Cancel</button>
                <input type="hidden" name="action" value="reschedule">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="session_id" id="rescheduleSessionId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-calendar-check-fill"></i> Reschedule
                </button>
            </div>
        </form>
    </div>
</div>

<!-- COMPLETE MODAL -->
<div class="modal-overlay" id="completeModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-patch-check-fill" style="color: #0d849c;"></i> Complete Session</h3>
            <button class="modal-close" onclick="closeModal('completeModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <p style="margin: 0 0 16px; font-size: 14.5px; color: #4b5563; line-height: 1.6;">
                Mark this session as completed for <strong id="completeMemberName">—</strong>?
            </p>

            <div class="modal-info-text">
                <strong><i class="bi bi-info-circle-fill"></i> Note:</strong>
                The member will receive a congratulatory notification.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('completeModal')">Cancel</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="complete">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="session_id" id="completeSessionId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-info">
                    <i class="bi bi-patch-check-fill"></i> Mark Completed
                </button>
            </form>
        </div>
    </div>
</div>

<!-- CANCEL MODAL -->
<div class="modal-overlay" id="cancelModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-slash-circle" style="color: #b45309;"></i> Cancel Session</h3>
            <button class="modal-close" onclick="closeModal('cancelModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <p style="margin: 0 0 16px; font-size: 14.5px; color: #4b5563; line-height: 1.6;">
                    Cancel the session for <strong id="cancelMemberName">—</strong>?
                </p>

                <div class="form-group">
                    <label class="form-label">Reason (Optional)</label>
                    <textarea name="admin_note" class="form-control"
                              placeholder="e.g., Member requested, weather, etc."></textarea>
                </div>

                <div class="modal-warning-text">
                    <strong><i class="bi bi-info-circle-fill"></i> Note:</strong>
                    The member will be notified and the slot will be freed up.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('cancelModal')">Cancel</button>
                <input type="hidden" name="action" value="cancel">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="session_id" id="cancelSessionId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-warning">
                    <i class="bi bi-slash-circle"></i> Cancel Session
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="bi bi-trash3-fill" style="color: var(--umak-red);"></i> Delete Session Record</h3>
            <button class="modal-close" onclick="closeModal('deleteModal')"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <p style="margin: 0 0 16px; font-size: 14.5px; color: #4b5563; line-height: 1.6;">
                Permanently delete the session record for <strong id="deleteMemberName">—</strong>?
            </p>

            <div class="modal-danger-text">
                <strong><i class="bi bi-exclamation-triangle-fill"></i> Warning:</strong>
                This action cannot be undone. The session record will be removed from the database permanently.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="delete">
                <?php echo app_csrf_field(); ?>
                <input type="hidden" name="session_id" id="deleteSessionId" value="">
                <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(buildQuery()) ?>">
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash3-fill"></i> Delete Permanently
                </button>
            </form>
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
    // MODAL HELPERS
    // ============================================
    function openModal(id) {
        document.getElementById(id).classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(m => {
                m.classList.remove('open');
            });
            document.body.style.overflow = '';
        }
    });

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('open');
                document.body.style.overflow = '';
            }
        });
    });

    // ============================================
    // SESSION ACTIONS
    // ============================================
    function approveSession(id, name, type, date, time) {
        document.getElementById('approveMemberName').textContent = name;
        document.getElementById('approveSessionType').textContent = type;
        document.getElementById('approveDate').textContent = date;
        document.getElementById('approveTime').textContent = time;
        document.getElementById('approveSessionId').value = id;
        openModal('approveModal');
    }

    function openRejectModal(id, name) {
        document.getElementById('rejectMemberName').textContent = name;
        document.getElementById('rejectSessionId').value = id;
        openModal('rejectModal');
    }

    function openRescheduleModal(id, name, currentDate, currentTime) {
        document.getElementById('rescheduleMemberName').textContent = name;
        document.getElementById('rescheduleSessionId').value = id;
        document.getElementById('rescheduleNewDate').value = currentDate;
        document.getElementById('rescheduleNewTime').value = currentTime.substring(0, 5);
        openModal('rescheduleModal');
    }

    function completeSession(id, name) {
        document.getElementById('completeMemberName').textContent = name;
        document.getElementById('completeSessionId').value = id;
        openModal('completeModal');
    }

    function cancelSession(id, name) {
        document.getElementById('cancelMemberName').textContent = name;
        document.getElementById('cancelSessionId').value = id;
        openModal('cancelModal');
    }

    function deleteSession(id, name) {
        document.getElementById('deleteMemberName').textContent = name;
        document.getElementById('deleteSessionId').value = id;
        openModal('deleteModal');
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
            };

            await html5QrCode.start(
                { facingMode: "environment" },
                config,
                onScanSuccess,
                onScanError
            );

        } catch (err) {
            console.error('Scanner start error:', err);
            isScanning = false;

            const status = document.getElementById('scannerStatus');
            status.className = 'scanner-status error';

            const isSecure = window.isSecureContext;
            const isLocalhost = location.hostname === 'localhost' || location.hostname === '127.0.0.1';

            let errorMsg = '<i class="bi bi-exclamation-triangle-fill"></i> ';

            if (!isSecure && !isLocalhost) {
                errorMsg += '<strong>Camera requires HTTPS.</strong><br>';
                errorMsg += 'Your device needs a secure (HTTPS) connection to use the camera.<br>';
                errorMsg += '<span style="font-size: 12px; opacity: 0.85;">';
                errorMsg += 'Use manual entry below to check in members.';
                errorMsg += '</span>';
            } else {
                errorMsg += 'Could not access camera.<br>';
                errorMsg += '<span style="font-size: 12px; opacity: 0.85;">';
                errorMsg += 'Please allow camera permissions or use manual entry below.';
                errorMsg += '</span>';
            }

            status.innerHTML = errorMsg;
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

    let lastScannedCode = '';
    let lastScanTime = 0;

    async function onScanSuccess(decodedText, decodedResult) {
        const now = Date.now();
        if (decodedText === lastScannedCode && (now - lastScanTime) < 3000) return;
        lastScannedCode = decodedText;
        lastScanTime = now;

        if (navigator.vibrate) navigator.vibrate(100);

        document.getElementById('scannerStatus').className = 'scanner-status info';
        document.getElementById('scannerStatus').innerHTML = 
            '<i class="bi bi-arrow-clockwise" style="animation: spin 1s linear infinite; display: inline-block;"></i> Processing...';

        await submitScan(decodedText);
    }

    function onScanError(errorMessage) {
        // Silently ignore
    }

    const csrfToken = <?php echo json_encode(app_csrf_token()); ?>;

    async function submitScan(qrData) {
        try {
            const formData = new FormData();
            formData.append('action', 'process_qr');
            formData.append('qr_data', qrData);
            formData.append('csrf_token', csrfToken);

            const res = await fetch('', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            showScanResult(data);

            // Update capacity if provided
            if (data.capacity) {
                updateCapacity(data.capacity);
                loadRecentCheckins();
            }

            if (data.success) {
                setTimeout(async () => {
                    await stopScanner();
                    // Don't reload — just keep scanner open for next scan
                    setTimeout(() => {
                        document.getElementById('scanResultContainer').innerHTML = '';
                        document.getElementById('scannerStatus').className = 'scanner-status info';
                        document.getElementById('scannerStatus').innerHTML = '<i class="bi bi-info-circle-fill"></i> Ready for next scan';
                    }, 3000);
                }, 1000);
            }
        } catch (err) {
            console.error('Submit error:', err);
            showScanResult({ success: false, message: 'Network error. Please try again.' });
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
        input.value = '';
    }

    function showScanResult(data) {
        const container = document.getElementById('scanResultContainer');
        const status = document.getElementById('scannerStatus');

        if (data.success) {
            const member = data.member || {};
            const initials = (member.fullname || 'U').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();

            let avatarHtml = '';
            if (member.profile_image && member.profile_image.length > 0) {
                avatarHtml = `<img src="../${escapeHtml(member.profile_image)}" alt="">`;
            } else {
                avatarHtml = escapeHtml(initials);
            }

            const actionLabel = data.action === 'check_in' ? 'CHECKED IN' : 'CHECKED OUT';
            const actionIcon = data.action === 'check_in' ? 'box-arrow-in-right' : 'box-arrow-out-right';

            let metaHtml = `<span class="scan-meta-item"><i class="bi bi-clock-fill"></i> ${escapeHtml(data.time)}</span>`;
            if (data.duration !== undefined) {
                metaHtml += `<span class="scan-meta-item"><i class="bi bi-hourglass-split"></i> ${data.duration} min</span>`;
            }
            if (data.membership_valid) {
                metaHtml += `<span class="scan-meta-item"><i class="bi bi-check-circle-fill" style="color: #10b981;"></i> Active Member</span>`;
            } else if (data.membership_msg) {
                metaHtml += `<span class="scan-meta-item"><i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b;"></i> ${escapeHtml(data.membership_msg)}</span>`;
            } else {
                metaHtml += `<span class="scan-meta-item"><i class="bi bi-info-circle-fill" style="color: #f59e0b;"></i> No active membership</span>`;
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
            status.innerHTML = '<i class="bi bi-check-circle-fill"></i> Success!';
        } else {
            container.innerHTML = `
                <div class="scan-result error">
                    <div class="scan-result-icon"><i class="bi bi-x-circle-fill"></i></div>
                    <h3 class="scan-result-title">Scan Failed</h3>
                    <p class="scan-result-message">${escapeHtml(data.message || 'Unknown error')}</p>
                </div>
            `;
            status.className = 'scanner-status error';
            status.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Please try again';

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

    document.getElementById('manualQrInput')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            submitManualCode();
        }
    });

    // ============================================
    // LIVE CAPACITY REFRESH
    // ============================================
    function updateCapacity(cap) {
        document.getElementById('capacityCurrent').textContent = cap.current;
        document.getElementById('capacityMax').textContent = cap.max;
        document.getElementById('capacityAvailable').textContent = cap.available + ' slots';
        document.getElementById('capacityAvailable').className = 'capacity-stat-value ' + 
            (cap.percentage >= 80 ? 'danger' : (cap.percentage >= 50 ? 'warning' : 'success'));
        document.getElementById('capacityRecent').textContent = cap.recent_checkins + ' check-ins';

        const fill = document.getElementById('capacityFill');
        fill.style.width = cap.percentage + '%';
        fill.style.background = cap.status_color;

        const badge = document.getElementById('capacityStatusBadge');
        badge.style.background = cap.status_color + '20';
        badge.style.color = cap.status_color;

        document.getElementById('capacityStatusText').textContent = cap.status_label;
        document.getElementById('capacityUpdated').textContent = cap.timestamp || new Date().toLocaleTimeString();
    }

    async function refreshCapacity() {
        try {
            const res = await fetch('?action=get_capacity');
            const data = await res.json();
            if (data.success) {
                updateCapacity(data.capacity);
            }
        } catch (err) {
            console.error('Capacity refresh error:', err);
        }
    }

    // ============================================
    // RECENT CHECK-INS LOADER
    // ============================================
    async function loadRecentCheckins() {
        const list = document.getElementById('checkinsList');
        try {
            const res = await fetch('?action=get_recent_checkins');
            const data = await res.json();

            if (!data.success) return;

            document.getElementById('checkinsCount').textContent = data.checkins.length;

            if (data.checkins.length === 0) {
                list.innerHTML = `
                    <div style="text-align: center; padding: 30px 10px; color: #9ca3af; font-size: 12px;">
                        <i class="bi bi-inbox" style="font-size: 24px; display: block; margin-bottom: 8px; opacity: 0.4;"></i>
                        No check-ins in the last 24 hours
                    </div>
                `;
                return;
            }

            let html = '';
            data.checkins.forEach(c => {
                let avatarHtml = '';
                if (c.profile_image && c.profile_image.length > 0) {
                    avatarHtml = `<img src="../${escapeHtml(c.profile_image)}" alt="">`;
                } else {
                    avatarHtml = escapeHtml(c.initials);
                }

                const statusBadge = c.is_active 
                    ? '<span class="checkin-status in"><i class="bi bi-broadcast"></i> In</span>'
                    : '<span class="checkin-status out"><i class="bi bi-check"></i> Out</span>';

                html += `
                    <div class="checkin-item ${c.is_active ? 'active' : ''}">
                        <div class="checkin-avatar">${avatarHtml}</div>
                        <div class="checkin-info">
                            <div class="checkin-name">${escapeHtml(c.fullname)}</div>
                            <div class="checkin-meta">
                                <span><i class="bi bi-box-arrow-in-right"></i> ${escapeHtml(c.check_in)}</span>
                                ${c.check_out ? `<span><i class="bi bi-box-arrow-out-right"></i> ${escapeHtml(c.check_out)}</span>` : ''}
                                <span style="color: #9ca3af;">${escapeHtml(c.time_ago)}</span>
                            </div>
                        </div>
                        ${statusBadge}
                    </div>
                `;
            });

            list.innerHTML = html;
        } catch (err) {
            console.error('Recent check-ins error:', err);
        }
    }

    // ============================================
    // AUTO-DISMISS ALERTS
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-dismiss flash messages
        document.querySelectorAll('.alert-success').forEach(function(alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s, transform 0.5s';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function() { alert.style.display = 'none'; }, 500);
            }, 5000);
        });

        // Initial load
        loadRecentCheckins();

        // Poll capacity every 15 seconds
        setInterval(refreshCapacity, 15000);

        // Poll check-ins every 20 seconds
        setInterval(loadRecentCheckins, 20000);
    });
</script>

<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>