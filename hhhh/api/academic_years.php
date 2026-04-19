<?php
// api/academic_years.php
// Academic years management CRUD endpoint

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $auth->requireAuth();
}

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
$id = $_GET['id'] ?? ($request_uri[1] ?? null);

try {
    switch ($method) {
        case 'GET':
            if ($id) {
                // Get single academic year
                $result = $helper->fetchOne(
                    "SELECT id, year_label, start_date, end_date, is_active, created_at, updated_at 
                     FROM academic_years WHERE id = ?",
                    [$id]
                );
                
                if (!$result) {
                    echo ApiResponse::error('Academic year not found', 404);
                    exit();
                }
                
                echo ApiResponse::success($result);
            } else {
                // Get all academic years
                $page = intval($_GET['page'] ?? 1);
                $per_page = intval($_GET['per_page'] ?? 10);
                $offset = ($page - 1) * $per_page;
                
                $total = $helper->count("SELECT COUNT(*) FROM academic_years");
                $years = $helper->fetchAll(
                    "SELECT id, year_label, start_date, end_date, is_active, created_at 
                     FROM academic_years 
                     ORDER BY start_date DESC 
                     LIMIT ? OFFSET ?",
                    [$per_page, $offset]
                );
                
                echo ApiResponse::paginated($years, $total, $page, $per_page);
            }
            break;
        
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                echo ApiResponse::error('Invalid JSON input', 400);
                exit();
            }
            
            // Validate required fields
            $required = ['year_label', 'start_date', 'end_date'];
            $errors = [];
            
            foreach ($required as $field) {
                if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
                    $errors[] = "$field is required";
                }
            }
            
            if (!empty($errors)) {
                echo ApiResponse::error('Validation failed', 400, $errors);
                exit();
            }
            
            // Validate date format
            $start = DateTime::createFromFormat('Y-m-d', $input['start_date']);
            $end = DateTime::createFromFormat('Y-m-d', $input['end_date']);
            
            if (!$start || !$end) {
                echo ApiResponse::error('Invalid date format (use Y-m-d)', 400);
                exit();
            }
            
            if ($start >= $end) {
                echo ApiResponse::error('Start date must be before end date', 400);
                exit();
            }
            
            // Check if year_label already exists
            $existing = $helper->fetchOne(
                "SELECT id FROM academic_years WHERE year_label = ?",
                [$input['year_label']]
            );
            
            if ($existing) {
                echo ApiResponse::error('Academic year label already exists', 409);
                exit();
            }
            
            // Insert academic year
            $stmt = $helper->execute(
                "INSERT INTO academic_years (year_label, start_date, end_date, is_active) 
                 VALUES (?, ?, ?, ?)",
                [
                    trim($input['year_label']),
                    $input['start_date'],
                    $input['end_date'],
                    $input['is_active'] ?? 1
                ]
            );
            
            $year_id = $connection->lastInsertId();
            
            $new_year = $helper->fetchOne(
                "SELECT id, year_label, start_date, end_date, is_active, created_at 
                 FROM academic_years WHERE id = ?",
                [$year_id]
            );
            
            echo ApiResponse::success($new_year, 'Academic year created successfully', 201);
            break;
        
        case 'PUT':
            if (!$id) {
                echo ApiResponse::error('Academic year ID is required', 400);
                exit();
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                echo ApiResponse::error('Invalid JSON input', 400);
                exit();
            }
            
            // Check if academic year exists
            $existing = $helper->fetchOne("SELECT id, start_date, end_date FROM academic_years WHERE id = ?", [$id]);
            if (!$existing) {
                echo ApiResponse::error('Academic year not found', 404);
                exit();
            }
            
            // Validate dates if provided
            if (isset($input['start_date']) || isset($input['end_date'])) {
                $start_date = $input['start_date'] ?? $existing['start_date'];
                $end_date = $input['end_date'] ?? $existing['end_date'];
                
                $start = DateTime::createFromFormat('Y-m-d', $start_date);
                $end = DateTime::createFromFormat('Y-m-d', $end_date);
                
                if (!$start || !$end || $start >= $end) {
                    echo ApiResponse::error('Invalid dates or start date must be before end date', 400);
                    exit();
                }
            }
            
            // Check if year_label is being updated and if it's unique
            if (isset($input['year_label'])) {
                $dup = $helper->fetchOne(
                    "SELECT id FROM academic_years WHERE year_label = ? AND id != ?",
                    [$input['year_label'], $id]
                );
                if ($dup) {
                    echo ApiResponse::error('Academic year label already exists', 409);
                    exit();
                }
            }
            
            // Build update query
            $updates = [];
            $values = [];
            
            $allowed = ['year_label', 'start_date', 'end_date', 'is_active'];
            foreach ($allowed as $field) {
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
            
            $update_query = "UPDATE academic_years SET " . implode(', ', $updates) . " WHERE id = ?";
            $helper->execute($update_query, $values);
            
            $updated = $helper->fetchOne(
                "SELECT id, year_label, start_date, end_date, is_active, created_at, updated_at 
                 FROM academic_years WHERE id = ?",
                [$id]
            );
            
            echo ApiResponse::success($updated, 'Academic year updated successfully');
            break;
        
        case 'DELETE':
            if (!$id) {
                echo ApiResponse::error('Academic year ID is required', 400);
                exit();
            }
            
            $existing = $helper->fetchOne("SELECT id FROM academic_years WHERE id = ?", [$id]);
            if (!$existing) {
                echo ApiResponse::error('Academic year not found', 404);
                exit();
            }
            
            // Check if there are sessions using this academic year
            $sessions = $helper->count("SELECT COUNT(*) FROM sessions WHERE academic_year_id = ?", [$id]);
            if ($sessions > 0) {
                echo ApiResponse::error('Cannot delete academic year with existing sessions', 409);
                exit();
            }
            
            $helper->execute("DELETE FROM academic_years WHERE id = ?", [$id]);
            
            echo ApiResponse::success(null, 'Academic year deleted successfully');
            break;
        
        default:
            echo ApiResponse::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    echo ApiResponse::error('Server error: ' . $e->getMessage(), 500);
}

?>
