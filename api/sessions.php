<?php
// api/sessions.php
// Idea category sessions management CRUD endpoint

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
                // Get single session with category and academic year details
                $result = $helper->fetchOne(
                    "SELECT s.id, s.academic_year_id, s.category_id, s.session_name, s.description,
                            s.opens_at, s.closes_at, s.final_closure_date, s.status, s.created_at, s.updated_at,
                            ay.year_label, ic.name as category_name
                     FROM sessions s
                     JOIN academic_years ay ON s.academic_year_id = ay.id
                     JOIN idea_categories ic ON s.category_id = ic.id
                     WHERE s.id = ?",
                    [$id]
                );
                
                if (!$result) {
                    echo ApiResponse::error('Session not found', 404);
                    exit();
                }
                
                echo ApiResponse::success($result);
            } else {
                // Get all sessions with filters
                $page = intval($_GET['page'] ?? 1);
                $per_page = intval($_GET['per_page'] ?? 10);
                $offset = ($page - 1) * $per_page;
                
                $year_id = $_GET['academic_year_id'] ?? null;
                $category_id = $_GET['category_id'] ?? null;
                $status = $_GET['status'] ?? null;
                
                // Build WHERE clause
                $where = [];
                $params = [];
                
                if ($year_id) {
                    $where[] = "s.academic_year_id = ?";
                    $params[] = $year_id;
                }
                
                if ($category_id) {
                    $where[] = "s.category_id = ?";
                    $params[] = $category_id;
                }
                
                if ($status && in_array($status, ['Draft', 'Active', 'Closed'])) {
                    $where[] = "s.status = ?";
                    $params[] = $status;
                }
                
                $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
                
                $count_query = "SELECT COUNT(*) as count FROM sessions s $where_clause";
                $count_result = $helper->fetchOne($count_query, $params);
                $total = $count_result['count'];
                
                $query = "SELECT s.id, s.academic_year_id, s.category_id, s.session_name, s.description,
                                 s.opens_at, s.closes_at, s.final_closure_date, s.status, s.created_at,
                                 ay.year_label, ic.name as category_name
                          FROM sessions s
                          JOIN academic_years ay ON s.academic_year_id = ay.id
                          JOIN idea_categories ic ON s.category_id = ic.id
                          $where_clause
                          ORDER BY s.created_at DESC
                          LIMIT ? OFFSET ?";
                
                $params[] = $per_page;
                $params[] = $offset;
                
                $sessions = $helper->fetchAll($query, $params);
                
                echo ApiResponse::paginated($sessions, $total, $page, $per_page);
            }
            break;
        
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                echo ApiResponse::error('Invalid JSON input', 400);
                exit();
            }
            
            // Validate required fields
            $required = ['academic_year_id', 'category_id', 'session_name', 'opens_at', 'closes_at'];
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
            
            // Validate dates
            $opens = strtotime($input['opens_at']);
            $closes = strtotime($input['closes_at']);
            
            if ($opens === false || $closes === false) {
                echo ApiResponse::error('Invalid date format', 400);
                exit();
            }
            
            if ($opens >= $closes) {
                echo ApiResponse::error('Opening date must be before closing date', 400);
                exit();
            }

            $final_closure_date = $input['final_closure_date'] ?? $input['closes_at'];
            $final_closure_ts = strtotime($final_closure_date);
            if ($final_closure_ts === false) {
                echo ApiResponse::error('Invalid final closure date format', 400);
                exit();
            }
            if ($final_closure_ts < $closes) {
                echo ApiResponse::error('Final closure date must be on or after closing date', 400);
                exit();
            }
            
            // Verify academic year and category exist
            $year_check = $helper->fetchOne("SELECT id FROM academic_years WHERE id = ?", [$input['academic_year_id']]);
            $cat_check = $helper->fetchOne("SELECT id FROM idea_categories WHERE id = ?", [$input['category_id']]);
            
            if (!$year_check || !$cat_check) {
                echo ApiResponse::error('Invalid academic year or category', 400);
                exit();
            }
            
            // Insert session
            $stmt = $helper->execute(
                "INSERT INTO sessions (academic_year_id, category_id, session_name, description, 
                                       opens_at, closes_at, final_closure_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $input['academic_year_id'],
                    $input['category_id'],
                    trim($input['session_name']),
                    trim($input['description'] ?? ''),
                    $input['opens_at'],
                    $input['closes_at'],
                    $final_closure_date,
                    $input['status'] ?? 'Draft'
                ]
            );
            
            $session_id = $connection->lastInsertId();
            
            $new_session = $helper->fetchOne(
                "SELECT s.id, s.academic_year_id, s.category_id, s.session_name, s.description,
                        s.opens_at, s.closes_at, s.final_closure_date, s.status, s.created_at,
                        ay.year_label, ic.name as category_name
                 FROM sessions s
                 JOIN academic_years ay ON s.academic_year_id = ay.id
                 JOIN idea_categories ic ON s.category_id = ic.id
                 WHERE s.id = ?",
                [$session_id]
            );
            
            echo ApiResponse::success($new_session, 'Session created successfully', 201);
            break;
        
        case 'PUT':
            if (!$id) {
                echo ApiResponse::error('Session ID is required', 400);
                exit();
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                echo ApiResponse::error('Invalid JSON input', 400);
                exit();
            }
            
            // Check if session exists
            $existing = $helper->fetchOne("SELECT id, opens_at, closes_at, final_closure_date FROM sessions WHERE id = ?", [$id]);
            if (!$existing) {
                echo ApiResponse::error('Session not found', 404);
                exit();
            }
            
            // Validate dates if provided
            if (isset($input['opens_at']) || isset($input['closes_at'])) {
                $opens = $input['opens_at'] ?? $existing['opens_at'];
                $closes = $input['closes_at'] ?? $existing['closes_at'];
                
                $opens_ts = strtotime($opens);
                $closes_ts = strtotime($closes);
                
                if ($opens_ts === false || $closes_ts === false || $opens_ts >= $closes_ts) {
                    echo ApiResponse::error('Invalid dates or opening date must be before closing date', 400);
                    exit();
                }
            }

            if (isset($input['final_closure_date']) || isset($input['closes_at'])) {
                $closes = $input['closes_at'] ?? $existing['closes_at'];
                $final_closure = $input['final_closure_date'] ?? ($existing['final_closure_date'] ?: $existing['closes_at']);
                $closes_ts = strtotime($closes);
                $final_closure_ts = strtotime($final_closure);

                if ($closes_ts === false || $final_closure_ts === false || $final_closure_ts < $closes_ts) {
                    echo ApiResponse::error('Final closure date must be on or after closing date', 400);
                    exit();
                }
            }
            
            // Build update query
            $updates = [];
            $values = [];
            
            $allowed = ['session_name', 'description', 'opens_at', 'closes_at', 'final_closure_date', 'status'];
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
            
            $update_query = "UPDATE sessions SET " . implode(', ', $updates) . " WHERE id = ?";
            $helper->execute($update_query, $values);
            
            $updated = $helper->fetchOne(
                "SELECT s.id, s.academic_year_id, s.category_id, s.session_name, s.description,
                        s.opens_at, s.closes_at, s.final_closure_date, s.status, s.created_at,
                        ay.year_label, ic.name as category_name
                 FROM sessions s
                 JOIN academic_years ay ON s.academic_year_id = ay.id
                 JOIN idea_categories ic ON s.category_id = ic.id
                 WHERE s.id = ?",
                [$id]
            );
            
            echo ApiResponse::success($updated, 'Session updated successfully');
            break;
        
        case 'DELETE':
            if (!$id) {
                echo ApiResponse::error('Session ID is required', 400);
                exit();
            }
            
            $existing = $helper->fetchOne("SELECT id FROM sessions WHERE id = ?", [$id]);
            if (!$existing) {
                echo ApiResponse::error('Session not found', 404);
                exit();
            }
            
            $helper->execute("DELETE FROM sessions WHERE id = ?", [$id]);
            
            echo ApiResponse::success(null, 'Session deleted successfully');
            break;
        
        default:
            echo ApiResponse::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    echo ApiResponse::error('Server error: ' . $e->getMessage(), 500);
}

?>
