<?php
require_once __DIR__ . '/config/app_env.php';
require_once __DIR__ . '/config/error_handling.php';

$dbHost = app_env('DB_HOST', 'localhost');
$dbUser = app_env('DB_USER', 'root');
$dbPass = app_env('DB_PASS', '');
$dbName = app_env('DB_NAME', 'gym_system');
$dbPort = (int)app_env('DB_PORT', 3306);

$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);

if ($conn->connect_error) {
    $errorMessage = 'Database connection failed: ' . $conn->connect_error;
    app_log_error($errorMessage, ['host' => $dbHost, 'database' => $dbName]);
    die(app_safe_error_message('Database is unavailable right now.', 'db'));
}

if (!$conn->set_charset('utf8mb4')) {
    app_log_error('Database charset could not be set to utf8mb4.', ['database' => $dbName]);
}
