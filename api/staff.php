<?php
// api/staff.php
// Staff management CRUD endpoint

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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
$helper = new DatabaseHelper($connection);

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
$id = $_GET['id'] ?? ($request_uri[1] ?? null);

// Check authentication for all requests except GET all
if ($method !== 'GET' || $id) {
    $auth->requireAuth();
}

try {
    switch ($method) {
        // GET all staff or specific staff by ID
        case 'GET':
            if ($id) {
                // Get single staff member
                $result = $helper->fetchOne(
                    "SELECT id, name, email, role, department, phone, is_active, created_at FROM staff WHERE id = ?",
                    [$id]
                );
                
                if (!$result) {
                    echo ApiResponse::error('Staff member not found', 404);
                    exit();
                }
                
                echo ApiResponse::success($result);
            } else {
                // Get all staff members
                $page = intval($_GET['page'] ?? 1);
                $per_page = intval($_GET['per_page'] ?? 10);
                $offset = ($page - 1) * $per_page;
                
                $total = $helper->count("SELECT COUNT(*) FROM staff");
                $staff = $helper->fetchAll(
                    "SELECT id, name, email, role, department, phone, is_active, created_at FROM staff 
                     ORDER BY created_at DESC LIMIT ? OFFSET ?",
                    [$per_page, $offset]
                );
                
                echo ApiResponse::paginated($staff, $total, $page, $per_page);
            }
            break;
        
        // CREATE new staff member
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                echo ApiResponse::error('Invalid JSON input', 400);
                exit();
            }
            
            // Validate required fields
            $required = ['name', 'email', 'role'];
            $errors = [];
            
            foreach ($required as $field) {
                if (!isset($input[$field]) || trim($input[$field]) === '') {
                    $errors[] = "$field is required";
                }
            }
            
            if (!empty($errors)) {
                echo ApiResponse::error('Validation failed', 400, $errors);
                exit();
            }
            
            // Validate email
            if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                echo ApiResponse::error('Invalid email format', 400);
                exit();
            }
            
            // Check if email already exists
            $existing = $helper->fetchOne("SELECT id FROM staff WHERE email = ?", [$input['email']]);
            if ($existing) {
                echo ApiResponse::error('Email already exists', 409);
                exit();
            }
            
            // Validate role
            $valid_roles = ['Manager', 'Coordinator', 'Staff'];
            if (!in_array($input['role'], $valid_roles)) {
                echo ApiResponse::error('Invalid role', 400);
                exit();
            }
            
            // Insert staff
            $stmt = $helper->execute(
                "INSERT INTO staff (name, email, role, department, phone, is_active) 
                 VALUES (?, ?, ?, ?, ?, 1)",
                [
                    trim($input['name']),
                    trim($input['email']),
                    $input['role'],
                    trim($input['department'] ?? ''),
                    trim($input['phone'] ?? '')
                ]
            );
            
            $staff_id = $connection->lastInsertId();
            
            $new_staff = $helper->fetchOne(
                "SELECT id, name, email, role, department, phone, is_active, created_at FROM staff WHERE id = ?",
                [$staff_id]
            );
            
            echo ApiResponse::success($new_staff, 'Staff member created successfully', 201);
            break;
        
        // UPDATE staff member
        case 'PUT':
            if (!$id) {
                echo ApiResponse::error('Staff ID is required', 400);
                exit();
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                echo ApiResponse::error('Invalid JSON input', 400);
                exit();
            }
            
            // Check if staff exists
            $existing = $helper->fetchOne("SELECT id FROM staff WHERE id = ?", [$id]);
            if (!$existing) {
                echo ApiResponse::error('Staff member not found', 404);
                exit();
            }
            
            // Validate email if provided
            if (isset($input['email'])) {
                if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
                    echo ApiResponse::error('Invalid email format', 400);
                    exit();
                }
                
                // Check if email already exists for another staff
                $duplicate = $helper->fetchOne(
                    "SELECT id FROM staff WHERE email = ? AND id != ?",
                    [$input['email'], $id]
                );
                if ($duplicate) {
                    echo ApiResponse::error('Email already exists', 409);
                    exit();
                }
            }
            
            // Build update query dynamically
            $updates = [];
            $values = [];
            
            $allowed_fields = ['name', 'email', 'role', 'department', 'phone', 'is_active'];
            foreach ($allowed_fields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "$field = ?";
                    $values[] = $input[$field];
                }
            }
            
            if (empty($updates)) {
                echo ApiResponse::error('No fields to update', 400);
                exit();
            }
            
            $values[] = $id;
            
            $update_query = "UPDATE staff SET " . implode(', ', $updates) . " WHERE id = ?";
            $helper->execute($update_query, $values);
            
            $updated_staff = $helper->fetchOne(
                "SELECT id, name, email, role, department, phone, is_active, created_at FROM staff WHERE id = ?",
                [$id]
            );
            
            echo ApiResponse::success($updated_staff, 'Staff member updated successfully');
            break;
        
        // DELETE staff member
        case 'DELETE':
            if (!$id) {
                echo ApiResponse::error('Staff ID is required', 400);
                exit();
            }
            
            // Check if staff exists
            $existing = $helper->fetchOne("SELECT id FROM staff WHERE id = ?", [$id]);
            if (!$existing) {
                echo ApiResponse::error('Staff member not found', 404);
                exit();
            }
            
            // Delete staff
            $helper->execute("DELETE FROM staff WHERE id = ?", [$id]);
            
            echo ApiResponse::success(null, 'Staff member deleted successfully');
            break;
        
        default:
            echo ApiResponse::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    echo ApiResponse::error('Server error: ' . $e->getMessage(), 500);
}

?>
