<?php
// api/staff_ideas.php
// Staff: Submit ideas, view ideas with filters, vote, comment

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
        // ===== GET T&C =====
        case 'get_tc':
            if ($method === 'GET') {
                $tc = $helper->fetchOne(
                    "SELECT id, version, content FROM terms_and_conditions WHERE is_active = TRUE ORDER BY version DESC LIMIT 1",
                    []
                );
                
                if ($current_user) {
                    $acceptance = $helper->fetchOne(
                        "SELECT * FROM staff_tc_acceptance WHERE staff_id = ? AND tc_version = ?",
                        [$current_user['admin_id'], $tc['version'] ?? 1]
                    );
                    $tc['accepted'] = !!$acceptance;
                }
                
                echo ApiResponse::success($tc);
            }
            break;
        
        // ===== ACCEPT T&C =====
        case 'accept_tc':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);
                
                $tc_version = intval($input['tc_version'] ?? 1);
                
                // Check if already accepted
                $existing = $helper->fetchOne(
                    "SELECT id FROM staff_tc_acceptance WHERE staff_id = ? AND tc_version = ?",
                    [$current_user['admin_id'], $tc_version]
                );
                
                if ($existing) {
                    echo ApiResponse::success(null, 'T&C already accepted');
                    exit();
                }
                
                // Record acceptance
                $helper->execute(
                    "INSERT INTO staff_tc_acceptance (staff_id, tc_version, accepted_at, ip_address)
                     VALUES (?, ?, NOW(), ?)",
                    [$current_user['admin_id'], $tc_version, $_SERVER['REMOTE_ADDR']]
                );
                
                echo ApiResponse::success(null, 'T&C accepted successfully');
            }
            break;
        
        // ===== SUBMIT IDEA =====
        case 'submit_idea':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);
                
                $title = $input['title'] ?? '';
                $description = $input['description'] ?? '';
                $session_id = intval($input['session_id'] ?? 0);
                $category_ids = $input['category_ids'] ?? []; // Array of IDs
                $is_anonymous = boolval($input['is_anonymous'] ?? false);
                
                if (!$title || !$description || !$session_id) {
                    echo ApiResponse::error('Title, description, and session required', 400);
                    exit();
                }
                
                // Check session status
                $session = $helper->fetchOne(
                    "SELECT * FROM sessions WHERE id = ?",
                    [$session_id]
                );
                
                if (!$session) {
                    echo ApiResponse::error('Session not found', 404);
                    exit();
                }
                
                if ($session['closes_at'] < date('Y-m-d H:i:s')) {
                    echo ApiResponse::error('Submission window closed', 400);
                    exit();
                }
                
                // Get or create contributor
                $staff = $helper->fetchOne(
                    "SELECT email, department, name FROM staff WHERE id = ?",
                    [$current_user['admin_id']]
                );
                
                $contributor = $helper->fetchOne(
                    "SELECT id FROM contributors WHERE email = ?",
                    [$staff['email']]
                );
                
                if (!$contributor) {
                    $helper->execute(
                        "INSERT INTO contributors (email, name, department) VALUES (?, ?, ?)",
                        [$staff['email'], $staff['name'], $staff['department']]
                    );
                    $contributor_id = $connection->lastInsertId();
                } else {
                    $contributor_id = $contributor['id'];
                }
                
                // Create idea
                $helper->execute(
                    "INSERT INTO ideas (title, description, session_id, contributor_id, department, status, approval_status, submitted_at, is_anonymous)
                     VALUES (?, ?, ?, ?, ?, 'Submitted', 'Approved', NOW(), ?)",
                    [$title, $description, $session_id, $contributor_id, $staff['department'], $is_anonymous ? 1 : 0]
                );
                
                $idea_id = $connection->lastInsertId();
                
                // Tag categories (if not using JOIN table, link directly)
                if (!empty($category_ids)) {
                    foreach ($category_ids as $cat_id) {
                        // Note: Adjust based on your schema - may need junction table
                        // For now assuming direct category in ideas table
                    }
                }
                
                // Get QA Coordinator email
                $coordinator = $helper->fetchOne(
                    "SELECT email, full_name FROM admin_users WHERE role = 'QACoordinator' AND department = ?",
                    [$staff['department']]
                );
                
                if ($coordinator) {
                    // Send email notification
                    $helper->execute(
                        "INSERT INTO email_notifications (recipient_email, recipient_type, notification_type, subject, message, idea_id, session_id, sent_at, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'Sent')",
                        [
                            $coordinator['email'],
                            'Coordinator',
                            'Idea_Submitted',
                            "New Idea Submitted - {$staff['department']} {$session['session_name']}",
                            "New idea: $title",
                            $idea_id,
                            $session_id
                        ]
                    );
                    
                    // Create system notification
                    $helper->execute(
                        "INSERT INTO system_notifications (recipient_id, notification_type, title, message, idea_id, created_at)
                         VALUES (?, ?, ?, ?, ?, NOW())",
                        [
                            $coordinator['admin_id'] ?? 0,
                            'Idea_Submitted',
                            "New Idea: $title",
                            $is_anonymous ? "Anonymous staff submitted: $title" : "{$staff['name']} submitted: $title",
                            $idea_id
                        ]
                    );
                }
                
                // Update staff submission tracking
                $helper->execute(
                    "INSERT INTO staff_submission_tracking (session_id, department, staff_id, has_submitted, first_submission_date, idea_count)
                     VALUES (?, ?, ?, 1, NOW(), 1)
                     ON DUPLICATE KEY UPDATE has_submitted = 1, idea_count = idea_count + 1, last_idea_date = NOW()",
                    [$session_id, $staff['department'], $current_user['admin_id']]
                );
                
                echo ApiResponse::success(['id' => $idea_id], 'Idea submitted successfully', 201);
            }
            break;
        
        // ===== GET IDEAS WITH FILTERS =====
        case 'get_ideas':
            if ($method === 'GET') {
                $filter = $_GET['filter'] ?? 'latest'; // latest, popular, unpopular, viewed
                $session_id = $_GET['session_id'] ?? null;
                $page = intval($_GET['page'] ?? 1);
                $per_page = 5; // 5 ideas per page
                $offset = ($page - 1) * $per_page;
                
                $where = "WHERE i.status = 'Submitted' AND i.approval_status = 'Approved'";
                $params = [];
                $order = "i.submitted_at DESC";
                
                if ($session_id) {
                    $where .= " AND i.session_id = ?";
                    $params[] = $session_id;
                }
                
                switch ($filter) {
                    case 'popular':
                        $order = "(COALESCE(i.upvote_count, 0) - COALESCE(i.downvote_count, 0)) DESC, i.submitted_at DESC";
                        break;
                    case 'unpopular':
                        $order = "(COALESCE(i.upvote_count, 0) - COALESCE(i.downvote_count, 0)) ASC, i.submitted_at DESC";
                        break;
                    case 'viewed':
                        $order = "i.view_count DESC, i.submitted_at DESC";
                        break;
                }
                
                // Count total
                $count_result = $helper->fetchOne(
                    "SELECT COUNT(*) as count FROM ideas i $where",
                    $params
                );
                $total = $count_result['count'];
                
                // Get ideas
                $count_params = array_merge($params, [$per_page, $offset]);
                $ideas = $helper->fetchAll(
                    "SELECT i.id, i.title, i.description, i.department, i.view_count,
                            COALESCE(i.upvote_count, 0) as upvote_count,
                            COALESCE(i.downvote_count, 0) as downvote_count,
                            COALESCE(i.upvote_count, 0) - COALESCE(i.downvote_count, 0) as net_votes,
                            i.comment_count, i.submitted_at, i.is_anonymous,
                            IF(i.is_anonymous, 'Anonymous', c.name) as contributor_name,
                            s.session_name, s.closes_at, s.final_closure_date
                     FROM ideas i
                     JOIN sessions s ON i.session_id = s.id
                     JOIN contributors c ON i.contributor_id = c.id
                     $where
                     ORDER BY $order
                     LIMIT ? OFFSET ?",
                    $count_params
                );
                
                // Add vote status for current user
                if ($current_user) {
                    foreach ($ideas as &$idea) {
                        $vote = $helper->fetchOne(
                            "SELECT vote_type FROM idea_votes WHERE idea_id = ? AND contributor_id = ?",
                            [$idea['id'], $current_user['admin_id']]
                        );
                        $idea['user_vote'] = $vote['vote_type'] ?? null;
                    }
                }
                
                echo ApiResponse::paginated($ideas, $total, $page, $per_page);
            }
            break;
        
        // ===== GET IDEA DETAIL =====
        case 'get_idea':
            if ($method === 'GET') {
                $idea_id = intval($_GET['idea_id'] ?? 0);
                
                if (!$idea_id) {
                    echo ApiResponse::error('Idea ID required', 400);
                    exit();
                }
                
                // Track view
                if ($current_user) {
                    $helper->execute(
                        "INSERT INTO idea_views (idea_id, viewer_id, viewed_at) VALUES (?, ?, NOW())",
                        [$idea_id, $current_user['admin_id']]
                    );
                }
                
                $idea = $helper->fetchOne(
                    "SELECT i.*, c.name as contributor_name, c.email as contributor_email,
                            IF(i.is_anonymous, 'Anonymous', c.name) as display_name,
                            s.session_name, s.closes_at, s.final_closure_date,
                            cat.name as category_name
                     FROM ideas i
                     JOIN sessions s ON i.session_id = s.id
                     JOIN contributors c ON i.contributor_id = c.id
                     LEFT JOIN idea_categories cat ON s.category_id = cat.id
                     WHERE i.id = ?",
                    [$idea_id]
                );
                
                if (!$idea) {
                    echo ApiResponse::error('Idea not found', 404);
                    exit();
                }
                
                // Get attachments
                $attachments = $helper->fetchAll(
                    "SELECT id, file_name, file_path, file_type, file_size, created_at FROM idea_attachments WHERE idea_id = ?",
                    [$idea_id]
                );
                $idea['attachments'] = $attachments;
                
                // Check if can submit new ideas (session status)
                $idea['can_submit'] = strtotime($idea['closes_at']) > time();
                $idea['can_comment'] = strtotime($idea['final_closure_date']) > time();
                
                // Get user vote if logged in
                if ($current_user) {
                    $vote = $helper->fetchOne(
                        "SELECT vote_type FROM idea_votes WHERE idea_id = ? AND contributor_id = ?",
                        [$idea_id, $current_user['admin_id']]
                    );
                    $idea['user_vote'] = $vote['vote_type'] ?? null;
                }
                
                echo ApiResponse::success($idea);
            }
            break;
        
        // ===== VOTE ON IDEA =====
        case 'vote_idea':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);
                
                $idea_id = intval($input['idea_id'] ?? 0);
                $vote_type = $input['vote_type'] ?? null; // 'up' or 'down'
                
                if (!$idea_id || !in_array($vote_type, ['up', 'down'])) {
                    echo ApiResponse::error('Idea ID and vote type required', 400);
                    exit();
                }
                
                // Check if already voted
                $existing = $helper->fetchOne(
                    "SELECT id, vote_type FROM idea_votes WHERE idea_id = ? AND contributor_id = ?",
                    [$idea_id, $current_user['admin_id']]
                );
                
                if ($existing) {
                    echo ApiResponse::error('You have already voted on this idea', 400);
                    exit();
                }
                
                // Record vote
                $helper->execute(
                    "INSERT INTO idea_votes (idea_id, contributor_id, vote_type, voted_at) VALUES (?, ?, ?, NOW())",
                    [$idea_id, $current_user['admin_id'], $vote_type]
                );
                
                echo ApiResponse::success(null, 'Vote recorded successfully');
            }
            break;
        
        // ===== GET LATEST COMMENTS =====
        case 'latest_comments':
            if ($method === 'GET') {
                $limit = intval($_GET['limit'] ?? 10);
                
                $comments = $helper->fetchAll(
                    "SELECT c.id, c.content, c.created_at,
                            IF(c.is_anonymous, 'Anonymous', con.name) as contributor_name,
                            i.id as idea_id, i.title as idea_title,
                            s.session_name
                     FROM comments c
                     JOIN ideas i ON c.idea_id = i.id
                     JOIN sessions s ON i.session_id = s.id
                     JOIN contributors con ON c.contributor_id = con.id
                     WHERE c.is_deleted = 0 AND i.approval_status = 'Approved'
                     ORDER BY c.created_at DESC
                     LIMIT ?",
                    [$limit]
                );
                
                echo ApiResponse::success($comments);
            }
            break;
        
        default:
            echo ApiResponse::error('Unknown action', 400);
    }
} catch (Exception $e) {
    echo ApiResponse::error('Error: ' . $e->getMessage(), 500);
}

?>
