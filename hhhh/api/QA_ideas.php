<?php
// api/qa_ideas.php
// QA Manager: Manage ideas, approve/reject, flag inappropriate content

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

// Check QA Manager access
$current_user = $auth->getCurrentUser();
if (!$current_user || !in_array($current_user['role'], ['Admin', 'QAManager'])) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = explode('/', trim($_SERVER['PATH_INFO'] ?? '', '/'));
$id = $request_uri[1] ?? ($_GET['id'] ?? null);
$action = $request_uri[2] ?? null;

function ensureQASchema($helper) {
    $helper->execute(
        "CREATE TABLE IF NOT EXISTS qa_content_action_audit (
            id INT PRIMARY KEY AUTO_INCREMENT,
            content_type ENUM('Idea','Comment') NOT NULL,
            content_id INT NOT NULL,
            action ENUM('flag','hide','delete') NOT NULL,
            previous_state_json LONGTEXT NOT NULL,
            admin_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_undone TINYINT(1) NOT NULL DEFAULT 0,
            undone_at TIMESTAMP NULL,
            INDEX idx_action_content (content_type, content_id, is_undone)
        )",
        []
    );
}

try {
    ensureQASchema($helper);

    switch ($method) {
        // GET all ideas with filters
        case 'GET':
            if ($id) {
                // Get single idea with details
                $idea = $helper->fetchOne(
                    "SELECT i.*, c.id as contributor_id, c.name as contributor_name, c.email, c.department, c.is_anonymous,
                            cat.name as category_name, s.session_name, a.year_label,
                            EXISTS(
                                SELECT 1
                                FROM qa_content_action_audit q
                                WHERE q.content_type = 'Idea'
                                  AND q.content_id = i.id
                                  AND q.action = 'hide'
                                  AND q.is_undone = 0
                            ) as can_unhide
                     FROM ideas i
                     JOIN contributors c ON i.contributor_id = c.id
                     JOIN sessions s ON i.session_id = s.id
                     JOIN idea_categories cat ON s.category_id = cat.id
                     JOIN academic_years a ON s.academic_year_id = a.id
                     WHERE i.id = ?",
                    [$id]
                );
                
                if (!$idea) {
                    echo ApiResponse::error('Idea not found', 404);
                    exit();
                }
                
                // Get comments
                $comments = $helper->fetchAll(
                    "SELECT c.*, con.name, con.is_anonymous FROM comments c
                     JOIN contributors con ON c.contributor_id = con.id
                     WHERE c.idea_id = ? AND c.is_deleted = 0
                     ORDER BY c.created_at DESC",
                    [$id]
                );
                
                $idea['comments'] = $comments;
                echo ApiResponse::success($idea);
            } else {
                // List ideas with filters
                $page = intval($_GET['page'] ?? 1);
                $per_page = intval($_GET['per_page'] ?? 10);
                $offset = ($page - 1) * $per_page;
                $session_id = $_GET['session_id'] ?? null;
                $status = $_GET['status'] ?? null;
                $approval_status = $_GET['approval_status'] ?? null;
                $is_inappropriate = $_GET['is_inappropriate'] ?? null;
                $department = $_GET['department'] ?? null;
                
                // Build WHERE clause
                $where = [];
                $params = [];
                
                if ($session_id) {
                    $where[] = "i.session_id = ?";
                    $params[] = $session_id;
                }
                
                if ($status) {
                    $where[] = "i.status = ?";
                    $params[] = $status;
                }
                
                if ($approval_status) {
                    $where[] = "i.approval_status = ?";
                    $params[] = $approval_status;
                }
                
                if ($is_inappropriate !== null) {
                    $where[] = "i.is_inappropriate = ?";
                    $params[] = intval($is_inappropriate);
                }
                
                if ($department) {
                    $where[] = "i.department = ?";
                    $params[] = $department;
                }
                
                $where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
                
                $count_result = $helper->fetchOne(
                    "SELECT COUNT(*) as count FROM ideas i $where_clause",
                    $params
                );
                $total = $count_result['count'];
                
                $count_params = $params;
                $count_params[] = $per_page;
                $count_params[] = $offset;
                
                $ideas = $helper->fetchAll(
                    "SELECT i.id, i.title, i.description, i.department, i.impact_level, i.status,
                            i.approval_status, i.is_inappropriate, i.submitted_at, i.comment_count,
                            i.like_count, c.id as contributor_id, c.name as contributor_name, c.is_anonymous,
                            EXISTS(
                                SELECT 1
                                FROM qa_content_action_audit q
                                WHERE q.content_type = 'Idea'
                                  AND q.content_id = i.id
                                  AND q.action = 'hide'
                                  AND q.is_undone = 0
                            ) as can_unhide,
                            cat.name as category_name, s.session_name
                     FROM ideas i
                     JOIN contributors c ON i.contributor_id = c.id
                     JOIN sessions s ON i.session_id = s.id
                     JOIN idea_categories cat ON s.category_id = cat.id
                     $where_clause
                     ORDER BY i.created_at DESC
                     LIMIT ? OFFSET ?",
                    $count_params
                );
                
                echo ApiResponse::paginated($ideas, $total, $page, $per_page);
            }
            break;
        
        // UPDATE idea (approve, reject, flag)
        case 'PUT':
            if (!$id) {
                echo ApiResponse::error('Idea ID required', 400);
                exit();
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                echo ApiResponse::error('Invalid input', 400);
                exit();
            }
            
            $existing = $helper->fetchOne("SELECT id FROM ideas WHERE id = ?", [$id]);
            if (!$existing) {
                echo ApiResponse::error('Idea not found', 404);
                exit();
            }
            
            // Handle approval
            if (isset($input['approval_action'])) {
                $approval_action = $input['approval_action']; // 'approve', 'reject', 'flag', 'hide', 'unhide'
                
                if ($approval_action === 'approve') {
                    $helper->execute(
                        "UPDATE ideas SET approval_status = 'Approved', approved_at = NOW() WHERE id = ?",
                        [$id]
                    );
                } elseif ($approval_action === 'reject') {
                    $helper->execute(
                        "UPDATE ideas SET approval_status = 'Rejected', rejected_at = NOW() WHERE id = ?",
                        [$id]
                    );
                } elseif ($approval_action === 'flag') {
                    $reason = $input['reason'] ?? 'Inappropriate content';
                    $previous_state = $helper->fetchOne(
                        "SELECT status, approval_status, is_inappropriate, inappropriate_reason, flagged_by_admin_id, flagged_at
                         FROM ideas WHERE id = ?",
                        [$id]
                    ) ?: [];
                    $helper->execute(
                        "INSERT INTO qa_content_action_audit (content_type, content_id, action, previous_state_json, admin_id)
                         VALUES ('Idea', ?, 'flag', ?, ?)",
                        [$id, json_encode($previous_state), $current_user['admin_id']]
                    );
                    $helper->execute(
                        "UPDATE ideas SET is_inappropriate = 1, inappropriate_reason = ?, flagged_by_admin_id = ?, flagged_at = NOW() WHERE id = ?",
                        [$reason, $current_user['admin_id'], $id]
                    );
                    
                    // Log to inappropriate content log
                    $helper->execute(
                        "INSERT INTO inappropriate_content_log (admin_id, content_type, content_id, reason, action, notes) VALUES (?, ?, ?, ?, ?, ?)",
                        [$current_user['admin_id'], 'Idea', $id, $reason, 'Flagged', $input['notes'] ?? '']
                    );
                } elseif ($approval_action === 'hide') {
                    $reason = $input['reason'] ?? 'Hidden by QA Manager';
                    $previous_state = $helper->fetchOne(
                        "SELECT status, approval_status, is_inappropriate, inappropriate_reason, flagged_by_admin_id, flagged_at
                         FROM ideas WHERE id = ?",
                        [$id]
                    ) ?: [];
                    $helper->execute(
                        "INSERT INTO qa_content_action_audit (content_type, content_id, action, previous_state_json, admin_id)
                         VALUES ('Idea', ?, 'hide', ?, ?)",
                        [$id, json_encode($previous_state), $current_user['admin_id']]
                    );
                    $helper->execute(
                        "UPDATE ideas
                         SET status = 'Deleted', approval_status = 'Deleted',
                             is_inappropriate = 1, inappropriate_reason = ?, flagged_by_admin_id = ?, flagged_at = NOW()
                         WHERE id = ?",
                        [$reason, $current_user['admin_id'], $id]
                    );
                    $helper->execute(
                        "INSERT INTO inappropriate_content_log (admin_id, content_type, content_id, reason, action, notes) VALUES (?, ?, ?, ?, ?, ?)",
                        [$current_user['admin_id'], 'Idea', $id, $reason, 'Hidden', $input['notes'] ?? 'Hidden by QA Manager']
                    );
                } elseif ($approval_action === 'unhide') {
                    $audit = $helper->fetchOne(
                        "SELECT id, previous_state_json
                         FROM qa_content_action_audit
                         WHERE content_type = 'Idea' AND content_id = ? AND action = 'hide' AND is_undone = 0
                         ORDER BY id DESC
                         LIMIT 1",
                        [$id]
                    );

                    if (!$audit) {
                        echo ApiResponse::error('No hide history found for this idea', 404);
                        exit();
                    }

                    $prev = json_decode($audit['previous_state_json'] ?? '{}', true);
                    $current = $helper->fetchOne(
                        "SELECT status, approval_status, is_inappropriate, inappropriate_reason, flagged_by_admin_id, flagged_at
                         FROM ideas WHERE id = ?",
                        [$id]
                    ) ?: [];

                    $helper->execute(
                        "UPDATE ideas
                         SET status = ?, approval_status = ?, is_inappropriate = ?, inappropriate_reason = ?,
                             flagged_by_admin_id = ?, flagged_at = ?
                         WHERE id = ?",
                        [
                            array_key_exists('status', $prev) ? $prev['status'] : ($current['status'] ?? null),
                            array_key_exists('approval_status', $prev) ? $prev['approval_status'] : ($current['approval_status'] ?? null),
                            intval($prev['is_inappropriate'] ?? ($current['is_inappropriate'] ?? 0)),
                            $prev['inappropriate_reason'] ?? ($current['inappropriate_reason'] ?? null),
                            $prev['flagged_by_admin_id'] ?? ($current['flagged_by_admin_id'] ?? null),
                            $prev['flagged_at'] ?? ($current['flagged_at'] ?? null),
                            $id
                        ]
                    );

                    $helper->execute(
                        "UPDATE qa_content_action_audit
                         SET is_undone = 1, undone_at = NOW()
                         WHERE id = ?",
                        [$audit['id']]
                    );
                } elseif ($approval_action === 'undo') {
                    $undo_action = $input['undo_action'] ?? null; // 'flag' or 'hide'
                    if (!in_array($undo_action, ['flag', 'hide'], true)) {
                        echo ApiResponse::error('undo_action must be flag or hide', 400);
                        exit();
                    }
                    $audit = $helper->fetchOne(
                        "SELECT id, previous_state_json
                         FROM qa_content_action_audit
                         WHERE content_type = 'Idea' AND content_id = ? AND action = ? AND is_undone = 0
                         ORDER BY id DESC
                         LIMIT 1",
                        [$id, $undo_action]
                    );
                    if (!$audit) {
                        echo ApiResponse::error('No undo history found for this idea', 404);
                        exit();
                    }
                    $prev = json_decode($audit['previous_state_json'] ?? '{}', true);
                    $current = $helper->fetchOne(
                        "SELECT status, approval_status, is_inappropriate, inappropriate_reason, flagged_by_admin_id, flagged_at
                         FROM ideas WHERE id = ?",
                        [$id]
                    ) ?: [];
                    $helper->execute(
                        "UPDATE ideas
                         SET status = ?, approval_status = ?, is_inappropriate = ?, inappropriate_reason = ?,
                             flagged_by_admin_id = ?, flagged_at = ?
                         WHERE id = ?",
                        [
                            array_key_exists('status', $prev) ? $prev['status'] : ($current['status'] ?? null),
                            array_key_exists('approval_status', $prev) ? $prev['approval_status'] : ($current['approval_status'] ?? null),
                            intval($prev['is_inappropriate'] ?? ($current['is_inappropriate'] ?? 0)),
                            $prev['inappropriate_reason'] ?? ($current['inappropriate_reason'] ?? null),
                            $prev['flagged_by_admin_id'] ?? ($current['flagged_by_admin_id'] ?? null),
                            $prev['flagged_at'] ?? ($current['flagged_at'] ?? null),
                            $id
                        ]
                    );
                    $helper->execute(
                        "UPDATE qa_content_action_audit
                         SET is_undone = 1, undone_at = NOW()
                         WHERE id = ?",
                        [$audit['id']]
                    );
                }
            }
            
            // Update other fields
            $updates = [];
            $values = [];
            $allowed = ['status', 'impact_level'];
            
            foreach ($allowed as $field) {
                if (isset($input[$field])) {
                    $updates[] = "$field = ?";
                    $values[] = $input[$field];
                }
            }
            
            if (!empty($updates)) {
                $values[] = $id;
                $update_query = "UPDATE ideas SET " . implode(', ', $updates) . " WHERE id = ?";
                $helper->execute($update_query, $values);
            }
            
            $updated = $helper->fetchOne(
                "SELECT id, title, status, approval_status, is_inappropriate FROM ideas WHERE id = ?",
                [$id]
            );
            
            echo ApiResponse::success($updated, 'Idea updated successfully');
            break;
        
        default:
            echo ApiResponse::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    echo ApiResponse::error('Error: ' . $e->getMessage(), 500);
}

?>
