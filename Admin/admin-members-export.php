<?php
// Admin/admin-members-export.php
session_start();
include __DIR__ . '/../conn.php';
require_once __DIR__ . '/../config/session_security.php';

// ============================================
// AUTHENTICATION CHECK
// ============================================
$userSession = app_require_session_user($conn, ['admin', 'super_admin'], 'admin-login.php');
$role = $_SESSION['role'];

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

$userCols = getTableColumns($conn, 'users');
$hasColumn = function($col) use ($userCols) {
    return in_array($col, $userCols);
};

// ============================================
// APPLY SAME FILTERS AS MEMBERS PAGE
// ============================================
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';
$verifiedFilter = $_GET['verified'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'newest';

$where = ["u.role = 'user'"];
$params = [];
$types = '';

if (!empty($search)) {
    $where[] = "(u.fullname LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.phone LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'ssss';
}

if ($statusFilter === 'active') {
    $where[] = "u.account_status = 'Active'";
} elseif ($statusFilter === 'inactive') {
    $where[] = "u.account_status = 'Inactive'";
}

if ($verifiedFilter === 'verified') {
    $where[] = "u.is_verified = 1";
} elseif ($verifiedFilter === 'unverified') {
    $where[] = "u.is_verified = 0";
}

$whereSQL = implode(' AND ', $where);

// Sort
$orderSQL = "u.created_at DESC";
if ($sortBy === 'oldest') $orderSQL = "u.created_at ASC";
elseif ($sortBy === 'name_asc') $orderSQL = "u.fullname ASC";
elseif ($sortBy === 'name_desc') $orderSQL = "u.fullname DESC";
elseif ($sortBy === 'active_first') $orderSQL = "u.account_status DESC, u.created_at DESC";

// ============================================
// FETCH ALL MEMBERS (no pagination for export)
// ============================================
$members = [];

// Build dynamic SELECT based on existing columns
$selectCols = [
    'u.id',
    'u.fullname',
    'u.username',
    'u.email',
    'u.phone',
    'u.account_status',
    'u.is_verified',
    'u.profile_image',
    'u.created_at',
];

if ($hasColumn('last_login')) $selectCols[] = 'u.last_login';
if ($hasColumn('date_of_birth')) $selectCols[] = 'u.date_of_birth';
if ($hasColumn('gender')) $selectCols[] = 'u.gender';
if ($hasColumn('address')) $selectCols[] = 'u.address';
if ($hasColumn('city')) $selectCols[] = 'u.city';
if ($hasColumn('state')) $selectCols[] = 'u.state';
if ($hasColumn('country')) $selectCols[] = 'u.country';
if ($hasColumn('zip')) $selectCols[] = 'u.zip';
if ($hasColumn('emergency_contact_name')) $selectCols[] = 'u.emergency_contact_name';
elseif ($hasColumn('emergency_contact')) $selectCols[] = 'u.emergency_contact';
if ($hasColumn('emergency_contact_phone')) $selectCols[] = 'u.emergency_contact_phone';
elseif ($hasColumn('emergency_phone')) $selectCols[] = 'u.emergency_phone';
if ($hasColumn('height')) $selectCols[] = 'u.height';
if ($hasColumn('weight')) $selectCols[] = 'u.weight';
if ($hasColumn('fitness_goal')) $selectCols[] = 'u.fitness_goal';
if ($hasColumn('activity_level')) $selectCols[] = 'u.activity_level';

$selectSQL = implode(', ', $selectCols);

$fetchSQL = "
    SELECT {$selectSQL}
    FROM users u
    WHERE {$whereSQL}
    ORDER BY {$orderSQL}
";

$stmt = $conn->prepare($fetchSQL);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();
}

// ============================================
// FETCH MEMBERSHIP INFO FOR EACH MEMBER (batch)
// ============================================
$memberIds = array_column($members, 'id');
$membershipInfo = [];

if (!empty($memberIds) && tableExists($conn, 'memberships')) {
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $typesStr = str_repeat('i', count($memberIds));

    $sql = "
        SELECT m.user_id, m.membership_type, m.status, m.start_date, m.end_date, m.amount
        FROM memberships m
        INNER JOIN (
            SELECT user_id, MAX(id) AS max_id
            FROM memberships
            WHERE user_id IN ({$placeholders})
            GROUP BY user_id
        ) AS latest ON m.id = latest.max_id
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($typesStr, ...$memberIds);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $membershipInfo[(int)$row['user_id']] = $row;
        }
        $stmt->close();
    }
}

// ============================================
// GENERATE FILENAME
// ============================================
$filename = 'members_export_';
if ($statusFilter !== 'all') $filename .= $statusFilter . '_';
if ($verifiedFilter !== 'all') $filename .= $verifiedFilter . '_';
$filename .= date('Y-m-d_His') . '.csv';

// ============================================
// SET CSV HEADERS
// ============================================
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

// ============================================
// OPEN OUTPUT STREAM
// ============================================
$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// ============================================
// CSV TITLE BLOCK (metadata)
// ============================================
$adminName = $_SESSION['fullname'] ?? 'Administrator';
$adminUsername = $_SESSION['username'] ?? 'admin';

fputcsv($output, ['FitSense - Members Export']);
fputcsv($output, ['Generated By', $adminName . ' (@' . $adminUsername . ')']);
fputcsv($output, ['Generated On', date('F j, Y g:i A')]);
fputcsv($output, ['Total Records', count($members)]);

// Filters applied
$filterDesc = [];
if ($statusFilter !== 'all') $filterDesc[] = "Status: " . ucfirst($statusFilter);
if ($verifiedFilter !== 'all') $filterDesc[] = "Verification: " . ucfirst($verifiedFilter);
if (!empty($search)) $filterDesc[] = "Search: \"{$search}\"";
if (!empty($filterDesc)) {
    fputcsv($output, ['Filters Applied', implode(' | ', $filterDesc)]);
}

fputcsv($output, []);

// ============================================
// SUMMARY STATISTICS
// ============================================
$totalActive = 0;
$totalInactive = 0;
$totalVerified = 0;
$totalUnverified = 0;
$genderCount = ['Male' => 0, 'Female' => 0, 'Other' => 0, 'Unspecified' => 0];

foreach ($members as $m) {
    if ($m['account_status'] === 'Active') $totalActive++;
    else $totalInactive++;

    if ((int)($m['is_verified'] ?? 0) === 1) $totalVerified++;
    else $totalUnverified++;

    if (isset($m['gender']) && !empty($m['gender'])) {
        if (isset($genderCount[$m['gender']])) $genderCount[$m['gender']]++;
        else $genderCount['Other']++;
    } else {
        $genderCount['Unspecified']++;
    }
}

fputcsv($output, ['=== SUMMARY ===']);
fputcsv($output, ['Total Records', count($members)]);
fputcsv($output, ['Active Accounts', $totalActive]);
fputcsv($output, ['Inactive Accounts', $totalInactive]);
fputcsv($output, ['Verified', $totalVerified]);
fputcsv($output, ['Unverified', $totalUnverified]);
fputcsv($output, []);

fputcsv($output, ['=== GENDER BREAKDOWN ===']);
fputcsv($output, ['Gender', 'Count']);
foreach ($genderCount as $g => $count) {
    if ($count > 0) fputcsv($output, [$g, $count]);
}

fputcsv($output, []);
fputcsv($output, []);

// ============================================
// MAIN DATA TABLE HEADERS
// ============================================
fputcsv($output, ['=== MEMBER RECORDS ===']);

$headers = [
    'Member ID',
    'Full Name',
    'Username',
    'Email',
    'Phone',
    'Account Status',
    'Email Verified',
];

// Add optional columns
$optionalHeaders = [
    'date_of_birth' => 'Date of Birth',
    'gender' => 'Gender',
    'address' => 'Address',
    'city' => 'City',
    'state' => 'State/Province',
    'country' => 'Country',
    'zip' => 'ZIP Code',
    'height' => 'Height (cm)',
    'weight' => 'Weight (kg)',
    'fitness_goal' => 'Fitness Goal',
    'activity_level' => 'Activity Level',
];

foreach ($optionalHeaders as $col => $header) {
    if ($hasColumn($col)) $headers[] = $header;
}

// Emergency contact (flexible columns)
if ($hasColumn('emergency_contact_name')) $headers[] = 'Emergency Contact';
elseif ($hasColumn('emergency_contact')) $headers[] = 'Emergency Contact';

if ($hasColumn('emergency_contact_phone')) $headers[] = 'Emergency Phone';
elseif ($hasColumn('emergency_phone')) $headers[] = 'Emergency Phone';

// Membership columns
$headers[] = 'Membership Plan';
$headers[] = 'Membership Status';
$headers[] = 'Membership Start';
$headers[] = 'Membership End';
$headers[] = 'Membership Amount';

// Timestamps
$headers[] = 'Joined Date';
if ($hasColumn('last_login')) $headers[] = 'Last Login';

fputcsv($output, $headers);

// ============================================
// HELPER FUNCTIONS
// ============================================
function formatDate($date) {
    if (empty($date) || $date === '0000-00-00') return '—';
    $ts = strtotime($date);
    if (!$ts) return '—';
    return date('Y-m-d', $ts);
}

function formatDateTime($datetime) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($datetime);
    if (!$ts) return '—';
    return date('Y-m-d H:i:s', $ts);
}

function cleanValue($val) {
    if ($val === null) return '';
    return trim((string)$val);
}

// ============================================
// OUTPUT DATA ROWS
// ============================================
foreach ($members as $m) {
    $membership = $membershipInfo[(int)$m['id']] ?? null;

    $row = [
        'FIT-' . str_pad($m['id'], 5, '0', STR_PAD_LEFT),
        cleanValue($m['fullname'] ?? ''),
        cleanValue($m['username'] ?? ''),
        cleanValue($m['email'] ?? ''),
        cleanValue($m['phone'] ?? ''),
        cleanValue($m['account_status'] ?? 'N/A'),
        (int)($m['is_verified'] ?? 0) === 1 ? 'Yes' : 'No',
    ];

    // Optional fields
    if ($hasColumn('date_of_birth')) $row[] = formatDate($m['date_of_birth'] ?? null);
    if ($hasColumn('gender')) $row[] = cleanValue($m['gender'] ?? '');
    if ($hasColumn('address')) $row[] = cleanValue($m['address'] ?? '');
    if ($hasColumn('city')) $row[] = cleanValue($m['city'] ?? '');
    if ($hasColumn('state')) $row[] = cleanValue($m['state'] ?? '');
    if ($hasColumn('country')) $row[] = cleanValue($m['country'] ?? '');
    if ($hasColumn('zip')) $row[] = cleanValue($m['zip'] ?? '');
    if ($hasColumn('height')) $row[] = cleanValue($m['height'] ?? '');
    if ($hasColumn('weight')) $row[] = cleanValue($m['weight'] ?? '');
    if ($hasColumn('fitness_goal')) $row[] = cleanValue($m['fitness_goal'] ?? '');
    if ($hasColumn('activity_level')) $row[] = cleanValue($m['activity_level'] ?? '');

    // Emergency contact
    if ($hasColumn('emergency_contact_name')) {
        $row[] = cleanValue($m['emergency_contact_name'] ?? '');
    } elseif ($hasColumn('emergency_contact')) {
        $row[] = cleanValue($m['emergency_contact'] ?? '');
    }

    if ($hasColumn('emergency_contact_phone')) {
        $row[] = cleanValue($m['emergency_contact_phone'] ?? '');
    } elseif ($hasColumn('emergency_phone')) {
        $row[] = cleanValue($m['emergency_phone'] ?? '');
    }

    // Membership info
    if ($membership) {
        $row[] = cleanValue($membership['membership_type'] ?? '');
        $row[] = cleanValue($membership['status'] ?? '');
        $row[] = formatDate($membership['start_date'] ?? null);
        $row[] = formatDate($membership['end_date'] ?? null);
        $row[] = number_format((float)($membership['amount'] ?? 0), 2, '.', '');
    } else {
        $row[] = 'No Plan';
        $row[] = '—';
        $row[] = '—';
        $row[] = '—';
        $row[] = '0.00';
    }

    // Timestamps
    $row[] = formatDateTime($m['created_at'] ?? null);
    if ($hasColumn('last_login')) $row[] = formatDateTime($m['last_login'] ?? null);

    fputcsv($output, $row);
}

// ============================================
// FOOTER
// ============================================
fputcsv($output, []);
fputcsv($output, []);
fputcsv($output, ['--- End of Report ---']);
fputcsv($output, ['Report generated by FitSense Admin Panel']);
fputcsv($output, ['Timestamp: ' . date('Y-m-d H:i:s T')]);
fputcsv($output, ['Total members exported: ' . count($members)]);

// ============================================
// CLOSE STREAM
// ============================================
fclose($output);
exit;