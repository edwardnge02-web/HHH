<?php
// api/login.php
// User authentication endpoint

header("Content-Type: application/json");
// Support both localhost and LAN dev frontends with credentials enabled.
$allowed_origins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://192.168.1.10:5173'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost:5173';
if (in_array($origin, $allowed_origins, true)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: http://localhost:5173");
}
header("Vary: Origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/db_connection.php';
require_once './auth.php';

$db = new Database();
$connection = $db->connect();
$auth = new Auth($connection);

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo ApiResponse::error('Method not allowed', 405);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['username']) || !isset($input['password'])) {
    echo ApiResponse::error('Username and password are required', 400);
    exit();
}

$username = trim($input['username']);
$password = $input['password'];

// Validate input
if (empty($username) || empty($password)) {
    echo ApiResponse::error('Username and password cannot be empty', 400);
    exit();
}

// Attempt login
$result = $auth->login($username, $password);

if ($result['status'] === 'success') {
    echo ApiResponse::success([
        'token' => $result['token'],
        'user' => $result['user']
    ], $result['message'], 200);
} else {
    echo ApiResponse::error($result['message'], 401);
}

?>
