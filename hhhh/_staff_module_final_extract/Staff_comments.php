<?php
// api/staff_comments.php
// Staff: Comment on ideas, reply to comments, report inappropriate content

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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$current_user = $auth->getCurrentUser();

try {
    switch ($action) {
        // ===== POST COMMENT =====
        case 'comment':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);
                
                $idea_id = intval($input['idea_id'] ?? 0);
                $content = $input['content'] ?? '';
                $is_anonymous = boolval($input['is_anonymous'] ?? false);
                
                if (!$idea_id || !$content) {
                    echo ApiResponse::error('Idea ID and content required', 400);
                    exit();
                }
                
                // Check if idea exists and can be commented
                $idea = $helper->fetchOne(
                    "SELECT i.*, s.final_closure_date FROM ideas i
                     JOIN sessions s ON i.session_id = s.id
                     WHERE i.id = ?",
                    [$idea_id]
                );
                
                if (!$idea) {
                    echo ApiResponse::error('Idea not found', 404);
                    exit();
                }
                
                if ($idea['final_closure_date'] < date('Y-m-d H:i:s')) {
                    echo ApiResponse::error('Session has closed - no more comments allowed', 400);
                    exit();
                }
                
                // Get or create contributor
                $staff = $helper->fetchOne(
                    "SELECT email, name FROM staff WHERE id = ?",
                    [$current_user['admin_id']]
                );
                
                $contributor = $helper->fetchOne(
                    "SELECT id FROM contributors WHERE email = ?",
                    [$staff['email']]
                );
                
                if (!$contributor) {
                    $helper->execute(
                        "INSERT INTO contributors (email, name) VALUES (?, ?)",
                        [$staff['email'], $staff['name']]
                    );
                    $contributor_id = $connection->lastInsertId();
                } else {
                    $contributor_id = $contributor['id'];
                }
                
                // Create comment
                $helper->execute(
                    "INSERT INTO comments (idea_id, contributor_id, content, is_anonymous, created_at)
                     VALUES (?, ?, ?, ?, NOW())",
                    [$idea_id, $contributor_id, $content, $is_anonymous ? 1 : 0]
                );
                
                $comment_id = $connection->lastInsertId();
                
                // Update idea comment count
                $helper->execute(
                    "UPDATE ideas SET comment_count = comment_count + 1 WHERE id = ?",
                    [$idea_id]
                );
                
                // Get idea author
                $idea_author = $helper->fetchOne(
                    "SELECT c.email, c.name FROM ideas i
                     JOIN contributors c ON i.contributor_id = c.id
                     WHERE i.id = ?",
                    [$idea_id]
                );
                
                // Notify idea author
                if ($idea_author) {
                    $helper->execute(
                        "INSERT INTO email_notifications (recipient_email, recipient_type, notification_type, subject, message, idea_id, comment_id, sent_at, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Sent')",
                        [
                            $idea_author['email'],
                            'Staff',
                            'Comment_Added',
                            "New Comment on Your Idea: {$idea['title']}",
                            $is_anonymous ? "Anonymous commented: " . substr($content, 0, 100) : "{$staff['name']} commented: " . substr($content, 0, 100),
                            $idea_id,
                            $comment_id
                        ]
                    );
                    
                    // System notification
                    $helper->execute(
                        "INSERT INTO system_notifications (recipient_type, recipient_email, notification_type, title, message, idea_id, comment_id, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                        [
                            'Staff',
                            $idea_author['email'],
                            'Comment_Added',
                            "New Comment on: {$idea['title']}",
                            $is_anonymous ? "Anonymous: " . substr($content, 0, 100) : "{$staff['name']}: " . substr($content, 0, 100),
                            $idea_id,
                            $comment_id
                        ]
                    );
                }
                
                echo ApiResponse::success(['id' => $comment_id], 'Comment posted successfully', 201);
            }
            break;
        
        // ===== GET COMMENTS FOR IDEA =====
        case 'get_comments':
            if ($method === 'GET') {
                $idea_id = intval($_GET['idea_id'] ?? 0);
                
                if (!$idea_id) {
                    echo ApiResponse::error('Idea ID required', 400);
                    exit();
                }
                
                $comments = $helper->fetchAll(
                    "SELECT c.id, c.content, c.created_at, c.is_deleted,
                            IF(c.is_anonymous, 'Anonymous', con.name) as contributor_name,
                            con.email as contributor_email,
                            con.id as contributor_id
                     FROM comments c
                     JOIN contributors con ON c.contributor_id = con.id
                     WHERE c.idea_id = ? AND c.is_deleted = 0
                     ORDER BY c.created_at DESC",
                    [$idea_id]
                );
                
                // Get replies for each comment
                foreach ($comments as &$comment) {
                    $replies = $helper->fetchAll(
                        "SELECT cr.id, cr.content, cr.created_at,
                                IF(cr.is_anonymous, 'Anonymous', con.name) as contributor_name,
                                con.id as contributor_id,
                                s.name as mentioned_staff_name
                         FROM comment_replies cr
                         JOIN contributors con ON cr.contributor_id = con.id
                         LEFT JOIN staff s ON cr.mentioned_staff_id = s.id
                         WHERE cr.parent_comment_id = ? AND cr.is_deleted = 0
                         ORDER BY cr.created_at ASC",
                        [$comment['id']]
                    );
                    $comment['replies'] = $replies;
                }
                
                echo ApiResponse::success($comments);
            }
            break;
        
        // ===== REPLY TO COMMENT =====
        case 'reply':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);
                
                $parent_comment_id = intval($input['parent_comment_id'] ?? 0);
                $idea_id = intval($input['idea_id'] ?? 0);
                $content = $input['content'] ?? '';
                $mentioned_staff_id = intval($input['mentioned_staff_id'] ?? 0);
                $is_anonymous = boolval($input['is_anonymous'] ?? false);
                
                if (!$parent_comment_id || !$idea_id || !$content) {
                    echo ApiResponse::error('Parent comment ID, idea ID, and content required', 400);
                    exit();
                }
                
                // Get or create contributor
                $staff = $helper->fetchOne(
                    "SELECT email, name FROM staff WHERE id = ?",
                    [$current_user['admin_id']]
                );
                
                $contributor = $helper->fetchOne(
                    "SELECT id FROM contributors WHERE email = ?",
                    [$staff['email']]
                );
                
                if (!$contributor) {
                    $helper->execute(
                        "INSERT INTO contributors (email, name) VALUES (?, ?)",
                        [$staff['email'], $staff['name']]
                    );
                    $contributor_id = $connection->lastInsertId();
                } else {
                    $contributor_id = $contributor['id'];
                }
                
                // Create reply
                $helper->execute(
                    "INSERT INTO comment_replies (parent_comment_id, idea_id, contributor_id, content, mentioned_staff_id, is_anonymous, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [$parent_comment_id, $idea_id, $contributor_id, $content, $mentioned_staff_id ?: null, $is_anonymous ? 1 : 0]
                );
                
                $reply_id = $connection->lastInsertId();
                
                // Notify mentioned staff if tagged
                if ($mentioned_staff_id) {
                    $mentioned = $helper->fetchOne(
                        "SELECT email, name FROM staff WHERE id = ?",
                        [$mentioned_staff_id]
                    );
                    
                    if ($mentioned) {
                        $helper->execute(
                            "INSERT INTO email_notifications (recipient_email, recipient_type, notification_type, subject, message, idea_id, sent_at, status)
                             VALUES (?, ?, ?, ?, ?, ?, NOW(), 'Sent')",
                            [
                                $mentioned['email'],
                                'Staff',
                                'Reply_Added',
                                "You were mentioned in a comment",
                                "{$staff['name']} mentioned you: " . substr($content, 0, 100),
                                $idea_id
                            ]
                        );
                    }
                }
                
                echo ApiResponse::success(['id' => $reply_id], 'Reply posted successfully', 201);
            }
            break;
        
        // ===== REPORT INAPPROPRIATE CONTENT =====
        case 'report_content':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);
                
                $content_type = $input['content_type'] ?? null; // 'Idea', 'Comment', 'Reply'
                $content_id = intval($input['content_id'] ?? 0);
                $report_category = $input['report_category'] ?? null;
                $reason = $input['reason'] ?? '';
                $description = $input['description'] ?? '';
                
                if (!$content_type || !$content_id || !$report_category) {
                    echo ApiResponse::error('Missing required fields', 400);
                    exit();
                }
                
                // Get or create contributor for reporter
                $staff = $helper->fetchOne(
                    "SELECT email, name FROM staff WHERE id = ?",
                    [$current_user['admin_id']]
                );
                
                $reporter = $helper->fetchOne(
                    "SELECT id FROM contributors WHERE email = ?",
                    [$staff['email']]
                );
                
                if (!$reporter) {
                    $helper->execute(
                        "INSERT INTO contributors (email, name) VALUES (?, ?)",
                        [$staff['email'], $staff['name']]
                    );
                    $reporter_id = $connection->lastInsertId();
                } else {
                    $reporter_id = $reporter['id'];
                }
                
                // Create report
                $helper->execute(
                    "INSERT INTO staff_idea_reports (reporter_id, content_type, content_id, report_category, reason, description, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'Reported')",
                    [$reporter_id, $content_type, $content_id, $report_category, $reason, $description]
                );
                
                $report_id = $connection->lastInsertId();
                
                // Notify QA coordinators
                $coordinators = $helper->fetchAll(
                    "SELECT email, full_name FROM admin_users WHERE role = 'QACoordinator'",
                    []
                );
                
                foreach ($coordinators as $coordinator) {
                    $helper->execute(
                        "INSERT INTO email_notifications (recipient_email, recipient_type, notification_type, subject, message, sent_at, status)
                         VALUES (?, ?, ?, ?, ?, NOW(), 'Sent')",
                        [
                            $coordinator['email'],
                            'Coordinator',
                            'Inappropriate_Report',
                            "New Content Report: $report_category",
                            "$content_type reported: $reason"
                        ]
                    );
                }
                
                echo ApiResponse::success(['id' => $report_id], 'Report submitted successfully', 201);
            }
            break;
        
        // ===== DELETE OWN COMMENT =====
        case 'delete_comment':
            if ($method === 'DELETE') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);
                
                $comment_id = intval($input['comment_id'] ?? 0);
                
                // Get comment
                $comment = $helper->fetchOne(
                    "SELECT c.*, con.email FROM comments c
                     JOIN contributors con ON c.contributor_id = con.id
                     WHERE c.id = ?",
                    [$comment_id]
                );
                
                if (!$comment) {
                    echo ApiResponse::error('Comment not found', 404);
                    exit();
                }
                
                // Check if staff is owner
                $staff = $helper->fetchOne(
                    "SELECT email FROM staff WHERE id = ?",
                    [$current_user['admin_id']]
                );
                
                if ($comment['email'] !== $staff['email']) {
                    echo ApiResponse::error('Unauthorized - can only delete own comments', 403);
                    exit();
                }
                
                // Soft delete
                $helper->execute(
                    "UPDATE comments SET is_deleted = 1, deleted_at = NOW() WHERE id = ?",
                    [$comment_id]
                );
                
                // Update idea comment count
                $helper->execute(
                    "UPDATE ideas SET comment_count = comment_count - 1 WHERE id = ?",
                    [$comment['idea_id']]
                );
                
                echo ApiResponse::success(null, 'Comment deleted successfully');
            }
            break;
        
        default:
            echo ApiResponse::error('Unknown action', 400);
    }
} catch (Exception $e) {
    echo ApiResponse::error('Error: ' . $e->getMessage(), 500);
}

?>
