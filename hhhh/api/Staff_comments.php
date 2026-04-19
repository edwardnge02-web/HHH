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
require_once '../config/email_service.php';
require_once './auth.php';

$db = new Database();
$connection = $db->connect();
$auth = new Auth($connection);
$helper = new DatabaseHelper($connection);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$current_user = $auth->getCurrentUser();

function generateContributorUuid() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function getSystemSettingValue($helper, $key, $default = null) {
    try {
        $table = $helper->fetchOne(
            "SELECT COUNT(*) as count
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_settings'",
            []
        );
        if (intval($table['count'] ?? 0) === 0) {
            return $default;
        }
        $row = $helper->fetchOne(
            "SELECT setting_value, value_type FROM system_settings WHERE setting_key = ? LIMIT 1",
            [$key]
        );
        if (!$row) {
            return $default;
        }
        if (($row['value_type'] ?? '') === 'bool') {
            return in_array(strtolower(strval($row['setting_value'])), ['1', 'true', 'yes', 'on'], true);
        }
        return $row['setting_value'];
    } catch (Exception $e) {
        return $default;
    }
}

try {
    switch ($action) {
        // ===== POST COMMENT =====
        case 'comment':
            if ($method === 'POST') {
                $auth->requireAuth();
                $commenting_enabled = getSystemSettingValue($helper, 'commenting_enabled', true);
                if (!$commenting_enabled) {
                    echo ApiResponse::error('Commenting is currently disabled by administrator', 403);
                    exit();
                }
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
                    "SELECT i.*, s.session_name, COALESCE(s.final_closure_date, s.closes_at) as final_closure_date
                     FROM ideas i
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
                    $user_uuid = generateContributorUuid();
                    $helper->execute(
                        "INSERT INTO contributors (user_uuid, email, name) VALUES (?, ?, ?)",
                        [$user_uuid, $staff['email'], $staff['name']]
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
                    $commenter_name = $is_anonymous ? 'Anonymous staff member' : trim($staff['name'] ?? 'Staff member');
                    $comment_preview = mb_substr(trim($content), 0, 220);
                    $comment_subject = "New comment on your idea in {$idea['session_name']}: {$idea['title']}";
                    $comment_message = emailServiceBuildPlainText(
                        'Hello ' . trim($idea_author['name'] ?? 'there') . ',',
                        'A new comment has been posted on your idea.',
                        [
                            'Idea Title' => trim($idea['title'] ?? ''),
                            'Campaign' => trim($idea['session_name'] ?? ''),
                            'Department' => trim($idea['department'] ?? ''),
                            'Commented By' => $commenter_name,
                            'Comment Preview' => $comment_preview,
                        ],
                        [
                            'Please sign in to the system to read the full discussion and continue the conversation.',
                            'This is an automated notification from Ideas System',
                        ]
                    );

                    sendSystemEmail($connection, [
                        'recipient_email' => $idea_author['email'],
                        'recipient_type' => 'Staff',
                        'notification_type' => 'Comment_Added',
                        'subject' => $comment_subject,
                        'message' => $comment_message,
                        'idea_id' => $idea_id,
                        'comment_id' => $comment_id
                    ]);
                    
                    // System notification
                    $helper->execute(
                        "INSERT INTO system_notifications (recipient_type, recipient_email, notification_type, title, message, idea_id, comment_id, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                        [
                            'Staff',
                            $idea_author['email'],
                            'Comment_Added',
                            $comment_subject,
                            $comment_message,
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
                $commenting_enabled = getSystemSettingValue($helper, 'commenting_enabled', true);
                if (!$commenting_enabled) {
                    echo ApiResponse::error('Commenting is currently disabled by administrator', 403);
                    exit();
                }
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
                
                $idea = $helper->fetchOne(
                    "SELECT i.id, COALESCE(s.final_closure_date, s.closes_at) as final_closure_date
                     FROM ideas i
                     JOIN sessions s ON i.session_id = s.id
                     WHERE i.id = ?",
                    [$idea_id]
                );
                if (!$idea) {
                    echo ApiResponse::error('Idea not found', 404);
                    exit();
                }
                if ($idea['final_closure_date'] < date('Y-m-d H:i:s')) {
                    echo ApiResponse::error('Session has closed - no more replies allowed', 400);
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
                    $user_uuid = generateContributorUuid();
                    $helper->execute(
                        "INSERT INTO contributors (user_uuid, email, name) VALUES (?, ?, ?)",
                        [$user_uuid, $staff['email'], $staff['name']]
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
                        $mention_subject = 'You were mentioned in an idea discussion';
                        $mention_message = emailServiceBuildPlainText(
                            'Hello ' . trim($mentioned['name'] ?? 'there') . ',',
                            trim($staff['name'] ?? 'A staff member') . ' mentioned you in a discussion.',
                            [
                                'Idea ID' => strval($idea_id),
                                'Mentioned By' => trim($staff['name'] ?? ''),
                                'Reply Preview' => mb_substr(trim($content), 0, 220),
                            ],
                            [
                                'Please sign in to the system to review the full reply and join the discussion.',
                                'This is an automated notification from Ideas System',
                            ]
                        );

                        sendSystemEmail($connection, [
                            'recipient_email' => $mentioned['email'],
                            'recipient_type' => 'Staff',
                            'notification_type' => 'Reply_Added',
                            'subject' => $mention_subject,
                            'message' => $mention_message,
                            'idea_id' => $idea_id
                        ]);
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
                    $user_uuid = generateContributorUuid();
                    $helper->execute(
                        "INSERT INTO contributors (user_uuid, email, name) VALUES (?, ?, ?)",
                        [$user_uuid, $staff['email'], $staff['name']]
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
                    $report_subject = "New content report: {$report_category}";
                    $report_message = emailServiceBuildPlainText(
                        'Hello Coordinator,',
                        'A new content report has been submitted and requires review.',
                        [
                            'Reported By' => trim($staff['name'] ?? ''),
                            'Content Type' => trim($content_type),
                            'Report Category' => trim($report_category),
                            'Reason' => trim($reason),
                            'Description' => trim($description),
                        ],
                        [
                            'Please sign in to the system to review the reported content.',
                            'This is an automated notification from Ideas System',
                        ]
                    );

                    sendSystemEmail($connection, [
                        'recipient_email' => $coordinator['email'],
                        'recipient_type' => 'Coordinator',
                        'notification_type' => 'Inappropriate_Report',
                        'subject' => $report_subject,
                        'message' => $report_message
                    ]);
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
