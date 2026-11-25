<?php
// chat_api.php — Handles chat messages, contacts, and sending
session_start();
include 'conn.php';

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================
// HELPERS
// ============================================
function jsonOut($data) {
    echo json_encode($data);
    exit;
}

function tableExists($conn, $t) {
    $t = $conn->real_escape_string($t);
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    return $r && $r->num_rows > 0;
}

function getInitials($name) {
    $name = trim($name);
    if (empty($name)) return '?';
    $parts = preg_split('/\s+/', $name);
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time / 60) . 'm ago';
    if ($time < 86400) return floor($time / 3600) . 'h ago';
    if ($time < 604800) return floor($time / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}

function dateLabel($dateStr) {
    $date = date('Y-m-d', strtotime($dateStr));
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($date === $today) return 'Today';
    if ($date === $yesterday) return 'Yesterday';
    return date('F j, Y', strtotime($dateStr));
}

// ============================================
// ROUTE: GET MESSAGES
// ============================================
if ($action === 'get_messages') {
    if (!tableExists($conn, 'chat_messages')) {
        jsonOut(['success' => true, 'messages' => []]);
    }

    $room = $_GET['room'] ?? 'community';
    $lastId = (int)($_GET['last_id'] ?? 0);
    $receiverId = (int)($_GET['receiver_id'] ?? 0);

    if ($room === 'community') {
        $sql = "SELECT cm.id, cm.sender_id, cm.message, cm.created_at,
                       u.fullname, u.username, u.profile_image, u.role
                FROM chat_messages cm
                LEFT JOIN users u ON cm.sender_id = u.id
                WHERE cm.room = 'community' AND cm.id > ?
                ORDER BY cm.id ASC
                LIMIT 200";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $lastId);
    } else {
        // Private conversation
        if ($receiverId <= 0) {
            jsonOut(['success' => true, 'messages' => []]);
        }
        $sql = "SELECT cm.id, cm.sender_id, cm.receiver_id, cm.message, cm.created_at,
                       u.fullname, u.username, u.profile_image, u.role
                FROM chat_messages cm
                LEFT JOIN users u ON cm.sender_id = u.id
                WHERE cm.room = 'private' 
                  AND (
                      (cm.sender_id = ? AND cm.receiver_id = ?)
                      OR (cm.sender_id = ? AND cm.receiver_id = ?)
                  )
                  AND cm.id > ?
                ORDER BY cm.id ASC
                LIMIT 200";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiiii", $userId, $receiverId, $receiverId, $userId, $lastId);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $senderName = $row['fullname'] ?: $row['username'] ?: 'Unknown';
        $messages[] = [
            'id' => (int)$row['id'],
            'sender_id' => (int)$row['sender_id'],
            'sender' => $senderName,
            'sender_initials' => getInitials($senderName),
            'sender_avatar' => $row['profile_image'] ?? '',
            'is_admin' => in_array($row['role'] ?? '', ['admin', 'super_admin']),
            'is_self' => ((int)$row['sender_id'] === $userId),
            'message' => $row['message'],
            'date' => dateLabel($row['created_at']),
            'time' => date('g:i A', strtotime($row['created_at'])),
        ];
    }
    $stmt->close();

    jsonOut(['success' => true, 'messages' => $messages]);
}

// ============================================
// ROUTE: SEND MESSAGE
// ============================================
if ($action === 'send_message') {
    if (!tableExists($conn, 'chat_messages')) {
        jsonOut(['success' => false, 'error' => 'Chat table missing']);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $message = trim($input['message'] ?? '');
    $room = $input['room'] ?? 'community';
    $receiverId = (int)($input['receiver_id'] ?? 0);

    if ($message === '' || strlen($message) > 2000) {
        jsonOut(['success' => false, 'error' => 'Invalid message']);
    }

    // Sanitize room
    if (!in_array($room, ['community', 'private'])) $room = 'community';

    if ($room === 'community') {
        $receiverId = null;
        $stmt = $conn->prepare(
            "INSERT INTO chat_messages (sender_id, receiver_id, room, message) VALUES (?, NULL, 'community', ?)"
        );
        $stmt->bind_param("is", $userId, $message);
    } else {
        if ($receiverId <= 0) {
            jsonOut(['success' => false, 'error' => 'Missing receiver']);
        }
        $stmt = $conn->prepare(
            "INSERT INTO chat_messages (sender_id, receiver_id, room, message) VALUES (?, ?, 'private', ?)"
        );
        $stmt->bind_param("iis", $userId, $receiverId, $message);
    }

    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        $stmt->close();
        jsonOut(['success' => true, 'message_id' => $newId]);
    } else {
        $err = $conn->error;
        $stmt->close();
        jsonOut(['success' => false, 'error' => $err]);
    }
}

// ============================================
// ROUTE: GET CONTACTS
// ============================================
if ($action === 'get_contacts') {
    // Get all other users (excluding self)
    $sql = "SELECT id, fullname, username, profile_image, role, last_login
            FROM users
            WHERE id != ? AND account_status = 'Active'
            ORDER BY 
                CASE WHEN role IN ('admin','super_admin') THEN 0 ELSE 1 END,
                fullname ASC
            LIMIT 50";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $contacts = [];
    $now = time();

    while ($row = $result->fetch_assoc()) {
        $name = $row['fullname'] ?: $row['username'] ?: 'User';
        $isAdmin = in_array($row['role'], ['admin', 'super_admin']);

        // Online if last_login within 5 min
        $isOnline = false;
        if (!empty($row['last_login'])) {
            $lastLogin = strtotime($row['last_login']);
            $isOnline = ($now - $lastLogin) < 300;
        }

        // Unread count for private messages
        $unread = 0;
        if (tableExists($conn, 'chat_messages')) {
            $uStmt = $conn->prepare(
                "SELECT COUNT(*) AS c FROM chat_messages 
                 WHERE sender_id = ? AND receiver_id = ? AND room = 'private'"
            );
            if ($uStmt) {
                $otherId = (int)$row['id'];
                $uStmt->bind_param("ii", $otherId, $userId);
                $uStmt->execute();
                $uRes = $uStmt->get_result();
                if ($uRow = $uRes->fetch_assoc()) $unread = (int)$uRow['c'];
                $uStmt->close();
            }
        }

        $contacts[] = [
            'id' => (int)$row['id'],
            'name' => $name,
            'initials' => getInitials($name),
            'avatar' => $row['profile_image'] ?? '',
            'is_admin' => $isAdmin,
            'is_online' => $isOnline,
            'unread_count' => $unread,
        ];
    }
    $stmt->close();

    jsonOut(['success' => true, 'contacts' => $contacts]);
}

// Unknown action
jsonOut(['success' => false, 'error' => 'Unknown action']);