<?php
// api/categories.php
// QA Manager: Manage idea categories (add, edit, delete, list)

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

// Check if QA Manager
$current_user = $auth->getCurrentUser();
if (!$current_user || !in_array($current_user['role'], ['Admin', 'QAManager'])) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized - QA Manager access required']));
}

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
$id = $_GET['id'] ?? ($request_uri[0] ?? null);

try {
    // Detect optional columns to remain compatible with older schema versions.
    $columns_meta = $helper->fetchAll("SHOW COLUMNS FROM idea_categories");
    $columns = array_column($columns_meta, 'Field');
    $has_color = in_array('color', $columns, true);
    $has_icon = in_array('icon', $columns, true);
    $has_sort_order = in_array('sort_order', $columns, true);
    $select_fields = "id, name, description, is_active, created_at";
    if ($has_color) {
        $select_fields .= ", color";
    }
    if ($has_icon) {
        $select_fields .= ", icon";
    }
    if ($has_sort_order) {
        $select_fields .= ", sort_order";
    }
    $order_by = $has_sort_order ? "sort_order ASC, name ASC" : "name ASC";

    switch ($method) {
        // GET all categories
        case 'GET':
            if ($id) {
                $result = $helper->fetchOne(
                    "SELECT $select_fields FROM idea_categories WHERE id = ?",
                    [$id]
                );
                
                if (!$result) {
                    echo ApiResponse::error('Category not found', 404);
                    exit();
                }
                echo ApiResponse::success($result);
            } else {
                $categories = $helper->fetchAll(
                    "SELECT $select_fields FROM idea_categories ORDER BY $order_by"
                );
                echo ApiResponse::success($categories);
            }
            break;
        
        // CREATE new category
        case 'POST':
            $auth->requireAuth();
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['name'])) {
                echo ApiResponse::error('Category name is required', 400);
                exit();
            }
            
            $name = trim($input['name']);
            $description = trim($input['description'] ?? '');
            $color = $input['color'] ?? '#0EA5E9';
            $icon = trim($input['icon'] ?? '');
            $sort_order = intval($input['sort_order'] ?? 0);
            
            // Check if category already exists
            $existing = $helper->fetchOne("SELECT id FROM idea_categories WHERE name = ?", [$name]);
            if ($existing) {
                echo ApiResponse::error('Category already exists', 409);
                exit();
            }
            
            $insert_fields = ['name', 'description', 'is_active'];
            $insert_values = [$name, $description, 1];
            if ($has_color) {
                $insert_fields[] = 'color';
                $insert_values[] = $color;
            }
            if ($has_icon) {
                $insert_fields[] = 'icon';
                $insert_values[] = $icon;
            }
            if ($has_sort_order) {
                $insert_fields[] = 'sort_order';
                $insert_values[] = $sort_order;
            }
            $placeholders = implode(', ', array_fill(0, count($insert_fields), '?'));
            $helper->execute(
                "INSERT INTO idea_categories (" . implode(', ', $insert_fields) . ") VALUES ($placeholders)",
                $insert_values
            );
            
            $category_id = $connection->lastInsertId();
            $new_category = $helper->fetchOne(
                "SELECT $select_fields FROM idea_categories WHERE id = ?",
                [$category_id]
            );
            
            echo ApiResponse::success($new_category, 'Category created successfully', 201);
            break;
        
        // UPDATE category
        case 'PUT':
            $auth->requireAuth();
            if (!$id) {
                echo ApiResponse::error('Category ID is required', 400);
                exit();
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                echo ApiResponse::error('Invalid JSON input', 400);
                exit();
            }
            
            // Check if exists
            $existing = $helper->fetchOne("SELECT id FROM idea_categories WHERE id = ?", [$id]);
            if (!$existing) {
                echo ApiResponse::error('Category not found', 404);
                exit();
            }
            
            // Check if name is being updated and is unique
            if (isset($input['name'])) {
                $dup = $helper->fetchOne(
                    "SELECT id FROM idea_categories WHERE name = ? AND id != ?",
                    [$input['name'], $id]
                );
                if ($dup) {
                    echo ApiResponse::error('Category name already exists', 409);
                    exit();
                }
            }
            
            $updates = [];
            $values = [];
            $allowed = ['name', 'description', 'is_active'];
            if ($has_color) {
                $allowed[] = 'color';
            }
            if ($has_icon) {
                $allowed[] = 'icon';
            }
            if ($has_sort_order) {
                $allowed[] = 'sort_order';
            }
            
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
            $update_query = "UPDATE idea_categories SET " . implode(', ', $updates) . " WHERE id = ?";
            $helper->execute($update_query, $values);
            
            $updated = $helper->fetchOne(
                "SELECT $select_fields FROM idea_categories WHERE id = ?",
                [$id]
            );
            
            echo ApiResponse::success($updated, 'Category updated successfully');
            break;
        
        // DELETE category
        case 'DELETE':
            $auth->requireAuth();
            if (!$id) {
                echo ApiResponse::error('Category ID is required', 400);
                exit();
            }
            
            $existing = $helper->fetchOne("SELECT id FROM idea_categories WHERE id = ?", [$id]);
            if (!$existing) {
                echo ApiResponse::error('Category not found', 404);
                exit();
            }
            
            // Category cannot be deleted once used by any session.
            $sessions_result = $helper->fetchOne(
                "SELECT COUNT(*) as count FROM sessions WHERE category_id = ?",
                [$id]
            );
            $sessions = intval($sessions_result['count'] ?? 0);

            if ($sessions > 0) {
                echo ApiResponse::error('Cannot delete category that is already used by sessions', 409);
                exit();
            }

            // If idea category tags table exists, block deletion when tags exist.
            $tags_table = $helper->fetchOne(
                "SELECT COUNT(*) as count
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'idea_category_tags'",
                []
            );
            $tags_table_exists = intval($tags_table['count'] ?? 0) > 0;
            if ($tags_table_exists) {
                $tag_usage = $helper->fetchOne(
                    "SELECT COUNT(*) as count FROM idea_category_tags WHERE category_id = ?",
                    [$id]
                );
                $tag_count = intval($tag_usage['count'] ?? 0);
                if ($tag_count > 0) {
                    echo ApiResponse::error('Cannot delete category that is already tagged on ideas', 409);
                    exit();
                }
            }

            // Keep older clients safe by ensuring no orphan references from active ideas.
            $ideas_result = $helper->fetchOne(
                "SELECT COUNT(*) as count
                 FROM ideas i
                 JOIN sessions s ON i.session_id = s.id
                 WHERE s.category_id = ?",
                [$id]
            );
            $ideas_count = intval($ideas_result['count'] ?? 0);
            if ($ideas_count > 0) {
                echo ApiResponse::error('Cannot delete category that has ideas associated with it', 409);
                exit();
            }
            
            $helper->execute("DELETE FROM idea_categories WHERE id = ?", [$id]);
            
            echo ApiResponse::success(null, 'Category deleted successfully');
            break;
        
        default:
            echo ApiResponse::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    echo ApiResponse::error('Server error: ' . $e->getMessage(), 500);
}

?>
