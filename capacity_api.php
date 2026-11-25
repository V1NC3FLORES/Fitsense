<?php
// capacity_api.php — Returns live gym capacity
session_start();
include 'conn.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$maxCapacity = 20;
$currentOccupancy = 0;

// Count active check-ins today
if (function_exists('tableExists') === false) {
    function tableExists($conn, $t) {
        $t = $conn->real_escape_string($t);
        $r = $conn->query("SHOW TABLES LIKE '$t'");
        return $r && $r->num_rows > 0;
    }
}

if (tableExists($conn, 'attendance')) {
    $result = $conn->query(
        "SELECT COUNT(*) AS current_count FROM attendance 
         WHERE DATE(check_in_time) = CURDATE() AND check_out_time IS NULL"
    );
    if ($result && $row = $result->fetch_assoc()) {
        $currentOccupancy = (int)$row['current_count'];
    }
}

$percentage = $maxCapacity > 0 ? round(($currentOccupancy / $maxCapacity) * 100) : 0;
$available = max(0, $maxCapacity - $currentOccupancy);

$status = 'low';
if ($percentage > 80) $status = 'high';
elseif ($percentage > 50) $status = 'medium';

echo json_encode([
    'success' => true,
    'capacity' => [
        'current' => $currentOccupancy,
        'max' => $maxCapacity,
        'available' => $available,
        'percentage' => $percentage,
        'status' => $status,
        'timestamp' => date('g:i:s A'),
    ]
]);