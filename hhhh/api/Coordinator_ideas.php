<?php
// api/qa_coordinator.php
// QA Coordinator: Department oversight + engagement + reporting support.

header("Content-Type: application/json");
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
require_once '../config/email_service.php';
require_once './auth.php';

$db = new Database();
$connection = $db->connect();
$auth = new Auth($connection);
$helper = new DatabaseHelper($connection);

$current_user = $auth->getCurrentUser();
if (!$current_user || !in_array($current_user['role'], ['QACoordinator', 'Admin'], true)) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized - QA Coordinator access required']));
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

function tableExists($helper, $table_name) {
    $row = $helper->fetchOne(
        "SELECT COUNT(*) as count
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table_name]
    );
    return intval($row['count'] ?? 0) > 0;
}

function columnExists($helper, $table_name, $column_name) {
    $row = $helper->fetchOne(
        "SELECT COUNT(*) as count
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table_name, $column_name]
    );
    return intval($row['count'] ?? 0) > 0;
}

function getOrCreateCoordinatorContributor($helper, $connection, $current_user) {
    $email = trim($current_user['email'] ?? '');
    if ($email === '') {
        return null;
    }

    $existing = $helper->fetchOne("SELECT id FROM contributors WHERE email = ? LIMIT 1", [$email]);
    if ($existing) {
        return intval($existing['id']);
    }

    $uuid = bin2hex(random_bytes(16));
    $helper->execute(
        "INSERT INTO contributors (user_uuid, email, name, department, account_status, created_at)
         VALUES (?, ?, ?, ?, 'Active', NOW())",
        [
            $uuid,
            $email,
            trim($current_user['full_name'] ?? 'QA Coordinator'),
            trim($current_user['department'] ?? '')
        ]
    );

    return intval($connection->lastInsertId());
}

function getIdeaTags($helper, $idea_id) {
    $tag_table_exists = tableExists($helper, 'idea_category_tags');
    if (!$tag_table_exists) {
        return [];
    }

    return $helper->fetchAll(
        "SELECT ic.id, ic.name
         FROM idea_category_tags ict
         JOIN idea_categories ic ON ic.id = ict.category_id
         WHERE ict.idea_id = ?
         ORDER BY ic.name ASC",
        [$idea_id]
    );
}

function coordinatorCanAccessDepartment($helper, $current_user, $department_name) {
    $dept = trim(strval($department_name ?? ''));
    if ($dept === '') {
        return true;
    }
    if (($current_user['role'] ?? '') === 'Admin') {
        return true;
    }
    $user_dept = trim(strval($current_user['department'] ?? ''));
    if ($user_dept !== '' && strcasecmp($user_dept, $dept) === 0) {
        return true;
    }
    // Fall back to explicit coordinator assignment list.
    if (tableExists($helper, 'qa_coordinator_departments')) {
        $row = $helper->fetchOne(
            "SELECT COUNT(*) as count
             FROM qa_coordinator_departments
             WHERE coordinator_id = ? AND is_active = 1 AND LOWER(department) = LOWER(?)",
            [intval($current_user['admin_id']), $dept]
        );
        return intval($row['count'] ?? 0) > 0;
    }
    return false;
}

try {
    if (tableExists($helper, 'staff_invitations') && !columnExists($helper, 'staff_invitations', 'idea_id')) {
        $helper->execute(
            "ALTER TABLE staff_invitations
             ADD COLUMN idea_id INT NULL AFTER session_id,
             ADD INDEX idx_staff_invitations_idea (idea_id)",
            []
        );
    }

    switch ($action) {
        // ===== DEPARTMENT IDEAS =====
        case 'department_ideas':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }

            $session_id = intval($_GET['session_id'] ?? 0);
            $page = max(1, intval($_GET['page'] ?? 1));
            $per_page = max(1, min(50, intval($_GET['per_page'] ?? 10)));
            $offset = ($page - 1) * $per_page;
            $sort = trim($_GET['sort'] ?? 'latest');

            $where = ["i.department = ?", "c.account_status = 'Active'"];
            $params = [trim($current_user['department'] ?? '')];
            if ($session_id > 0) {
                $where[] = "i.session_id = ?";
                $params[] = $session_id;
            }
            $where_sql = "WHERE " . implode(" AND ", $where);

            $order_sql = "i.submitted_at DESC";
            if ($sort === 'popular') {
                $order_sql = "(COALESCE(i.upvote_count, 0) - COALESCE(i.downvote_count, 0)) DESC, i.submitted_at DESC";
            } elseif ($sort === 'viewed') {
                $order_sql = "COALESCE(i.view_count, 0) DESC, i.submitted_at DESC";
            } elseif ($sort === 'trending') {
                $order_sql = "((COALESCE(i.upvote_count, 0) - COALESCE(i.downvote_count, 0)) * 2 + COALESCE(i.comment_count, 0) + (COALESCE(i.view_count, 0) / 10)) DESC, i.submitted_at DESC";
            }

            $total = intval($helper->fetchOne(
                "SELECT COUNT(*) as count
                 FROM ideas i
                 JOIN contributors c ON i.contributor_id = c.id
                 $where_sql",
                $params
            )['count'] ?? 0);

            $rows = $helper->fetchAll(
                "SELECT i.id, i.title, i.description, i.department, i.status, i.approval_status,
                        i.submitted_at, COALESCE(i.view_count, 0) as view_count,
                        COALESCE(i.like_count, 0) as like_count, COALESCE(i.comment_count, 0) as comment_count,
                        COALESCE(i.upvote_count, 0) as upvote_count, COALESCE(i.downvote_count, 0) as downvote_count,
                        i.is_anonymous, c.name as contributor_name, c.email as contributor_email,
                        cat.name as category_name, ses.session_name, ses.closes_at, ses.final_closure_date
                 FROM ideas i
                 JOIN sessions ses ON i.session_id = ses.id
                 JOIN contributors c ON i.contributor_id = c.id
                 JOIN idea_categories cat ON ses.category_id = cat.id
                 $where_sql
                 ORDER BY $order_sql
                 LIMIT ? OFFSET ?",
                array_merge($params, [$per_page, $offset])
            );

            foreach ($rows as &$row) {
                $row['tags'] = getIdeaTags($helper, intval($row['id']));
                $row['trending_score'] =
                    ((intval($row['upvote_count']) - intval($row['downvote_count'])) * 2) +
                    intval($row['comment_count']) +
                    (intval($row['view_count']) / 10.0);
            }

            echo ApiResponse::paginated($rows, $total, $page, $per_page);
            break;

        // ===== SYSTEM-WIDE IDEA FEED =====
        case 'ideas_feed':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }

            $scope = trim($_GET['scope'] ?? 'all'); // all | department
            $session_id = intval($_GET['session_id'] ?? 0);
            $page = max(1, intval($_GET['page'] ?? 1));
            $per_page = max(1, min(50, intval($_GET['per_page'] ?? 10)));
            $offset = ($page - 1) * $per_page;
            $sort = trim($_GET['sort'] ?? 'latest'); // latest | popular | viewed | comments | trending

            $where = ["i.approval_status != 'Deleted'"];
            $params = [];
            if ($scope === 'department') {
                $where[] = "i.department = ?";
                $params[] = trim($current_user['department'] ?? '');
            }
            if ($session_id > 0) {
                $where[] = "i.session_id = ?";
                $params[] = $session_id;
            }
            $where_sql = "WHERE " . implode(" AND ", $where);

            $order_sql = "i.submitted_at DESC";
            if ($sort === 'popular') {
                $order_sql = "(COALESCE(i.upvote_count, 0) - COALESCE(i.downvote_count, 0)) DESC, i.submitted_at DESC";
            } elseif ($sort === 'viewed') {
                $order_sql = "COALESCE(i.view_count, 0) DESC, i.submitted_at DESC";
            } elseif ($sort === 'comments') {
                $order_sql = "COALESCE(i.comment_count, 0) DESC, i.submitted_at DESC";
            } elseif ($sort === 'trending') {
                $order_sql = "((COALESCE(i.upvote_count, 0) - COALESCE(i.downvote_count, 0)) * 2 + COALESCE(i.comment_count, 0) + (COALESCE(i.view_count, 0) / 10)) DESC, i.submitted_at DESC";
            }

            $total = intval($helper->fetchOne(
                "SELECT COUNT(*) as count
                 FROM ideas i
                 $where_sql",
                $params
            )['count'] ?? 0);

            $contributor_id = getOrCreateCoordinatorContributor($helper, $connection, $current_user);

            $rows = $helper->fetchAll(
                "SELECT i.id, i.title, i.description, i.department, i.status, i.approval_status,
                        i.submitted_at, COALESCE(i.view_count, 0) as view_count,
                        COALESCE(i.comment_count, 0) as comment_count, COALESCE(i.upvote_count, 0) as upvote_count,
                        COALESCE(i.downvote_count, 0) as downvote_count,
                        i.is_anonymous, IF(i.is_anonymous = 1, 'Anonymous', c.name) as contributor_name,
                        ses.session_name, ses.closes_at, ses.final_closure_date,
                        cat.name as category_name
                 FROM ideas i
                 JOIN sessions ses ON i.session_id = ses.id
                 JOIN contributors c ON i.contributor_id = c.id
                 LEFT JOIN idea_categories cat ON ses.category_id = cat.id
                 $where_sql
                 ORDER BY $order_sql
                 LIMIT ? OFFSET ?",
                array_merge($params, [$per_page, $offset])
            );

            foreach ($rows as &$row) {
                $row['tags'] = getIdeaTags($helper, intval($row['id']));
                $vote = $helper->fetchOne(
                    "SELECT vote_type FROM idea_votes WHERE idea_id = ? AND contributor_id = ? LIMIT 1",
                    [intval($row['id']), $contributor_id]
                );
                $row['user_vote'] = $vote['vote_type'] ?? null;
            }

            echo ApiResponse::paginated($rows, $total, $page, $per_page);
            break;

        // ===== IDEA DETAIL =====
        case 'idea_detail':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }

            $idea_id = intval($_GET['idea_id'] ?? 0);
            if ($idea_id <= 0) {
                echo ApiResponse::error('idea_id is required', 400);
                exit();
            }

            $idea = $helper->fetchOne(
                "SELECT i.id, i.title, i.description, i.department, i.status, i.approval_status,
                        i.submitted_at, COALESCE(i.view_count, 0) as view_count,
                        COALESCE(i.comment_count, 0) as comment_count, COALESCE(i.upvote_count, 0) as upvote_count,
                        COALESCE(i.downvote_count, 0) as downvote_count,
                        i.is_anonymous, IF(i.is_anonymous = 1, 'Anonymous', c.name) as contributor_name,
                        ses.id as session_id, ses.session_name, ses.closes_at, ses.final_closure_date,
                        cat.name as category_name
                 FROM ideas i
                 JOIN contributors c ON c.id = i.contributor_id
                 JOIN sessions ses ON ses.id = i.session_id
                 LEFT JOIN idea_categories cat ON cat.id = ses.category_id
                 WHERE i.id = ?
                 LIMIT 1",
                [$idea_id]
            );

            if (!$idea) {
                echo ApiResponse::error('Idea not found', 404);
                exit();
            }

            $contributor_id = getOrCreateCoordinatorContributor($helper, $connection, $current_user);
            if (tableExists($helper, 'idea_views')) {
                $helper->execute(
                    "INSERT INTO idea_views (idea_id, viewer_id, viewed_at) VALUES (?, ?, NOW())",
                    [$idea_id, $contributor_id]
                );
            }

            $idea['attachments'] = $helper->fetchAll(
                "SELECT id, file_name, file_path, file_type, file_size, created_at
                 FROM idea_attachments
                 WHERE idea_id = ?
                 ORDER BY created_at DESC",
                [$idea_id]
            );
            $idea['tags'] = getIdeaTags($helper, $idea_id);

            $idea['comments'] = $helper->fetchAll(
                "SELECT c.id, c.content, c.created_at, c.is_anonymous,
                        IF(c.is_anonymous = 1, 'Anonymous', con.name) as contributor_name
                 FROM comments c
                 JOIN contributors con ON con.id = c.contributor_id
                 WHERE c.idea_id = ? AND c.is_deleted = 0
                 ORDER BY c.created_at DESC
                 LIMIT 200",
                [$idea_id]
            );

            echo ApiResponse::success($idea);
            break;

        // ===== COORDINATOR COMMENT =====
        case 'post_comment':
            if ($method !== 'POST') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $auth->requireAuth();
            $input = json_decode(file_get_contents('php://input'), true);
            $idea_id = intval($input['idea_id'] ?? 0);
            $content = trim($input['content'] ?? '');
            $is_anonymous = !empty($input['is_anonymous']) ? 1 : 0;

            if ($idea_id <= 0 || $content === '') {
                echo ApiResponse::error('idea_id and content are required', 400);
                exit();
            }

            $idea = $helper->fetchOne(
                "SELECT i.id, i.title, i.session_id, i.contributor_id,
                        COALESCE(s.final_closure_date, s.closes_at) as final_closure_date,
                        s.session_name,
                        con.email as contributor_email, con.name as contributor_name
                 FROM ideas i
                 JOIN sessions s ON s.id = i.session_id
                 JOIN contributors con ON con.id = i.contributor_id
                 WHERE i.id = ?",
                [$idea_id]
            );
            if (!$idea) {
                echo ApiResponse::error('Idea not found', 404);
                exit();
            }
            if (strtotime($idea['final_closure_date']) <= time()) {
                echo ApiResponse::error('Comment window is closed for this idea', 400);
                exit();
            }

            $contributor_id = getOrCreateCoordinatorContributor($helper, $connection, $current_user);
            $helper->execute(
                "INSERT INTO comments (idea_id, contributor_id, content, is_anonymous, created_at)
                 VALUES (?, ?, ?, ?, NOW())",
                [$idea_id, $contributor_id, $content, $is_anonymous]
            );
            $comment_id = intval($connection->lastInsertId());

            $helper->execute(
                "UPDATE ideas SET comment_count = comment_count + 1 WHERE id = ?",
                [$idea_id]
            );

            $staff_email = trim($idea['contributor_email'] ?? '');
            if ($staff_email !== '' && strcasecmp($staff_email, trim($current_user['email'] ?? '')) !== 0) {
                $coordinator_name = trim($current_user['full_name'] ?? 'QA Coordinator');
                $coordinator_department = trim($current_user['department'] ?? '');
                if ($coordinator_department === '') {
                    $coordinator_department = 'your';
                }
                $comment_preview = mb_substr(trim($content), 0, 220);
                $staff_email_subject = "Coordinator reply in {$idea['session_name']} campaign: {$idea['title']}";
                $staff_email_message = emailServiceBuildPlainText(
                    'Hello ' . trim($idea['contributor_name'] ?? 'there') . ',',
                    "Coordinator {$coordinator_name} from {$coordinator_department} department has replied back to your idea.",
                    [
                        'Idea Title' => trim($idea['title'] ?? ''),
                        'Campaign' => trim($idea['session_name'] ?? ''),
                        'Coordinator' => $coordinator_name,
                        'Department' => $coordinator_department,
                        'Reply Preview' => $comment_preview,
                    ],
                    [
                        'Please sign in to the system to review the full feedback on your idea.',
                        'This is an automated notification from Ideas System',
                    ]
                );

                sendSystemEmail($connection, [
                    'recipient_email' => $staff_email,
                    'recipient_type' => 'Staff',
                    'notification_type' => 'Coordinator_Comment',
                    'subject' => $staff_email_subject,
                    'message' => $staff_email_message,
                    'idea_id' => $idea_id,
                    'session_id' => intval($idea['session_id']),
                    'comment_id' => $comment_id,
                ]);

                if (tableExists($helper, 'system_notifications')) {
                    $helper->execute(
                        "INSERT INTO system_notifications
                         (recipient_type, recipient_id, recipient_email, notification_type, title, message, idea_id, comment_id, created_at)
                         VALUES ('Staff', NULL, ?, 'Coordinator_Comment', ?, ?, ?, ?, NOW())",
                        [
                            $staff_email,
                            $staff_email_subject,
                            $staff_email_message,
                            $idea_id,
                            $comment_id,
                        ]
                    );
                }
            }

            echo ApiResponse::success(['id' => $comment_id], 'Comment posted successfully', 201);
            break;

        // ===== COORDINATOR VOTE =====
        case 'vote_idea':
            if ($method !== 'POST') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $auth->requireAuth();
            $input = json_decode(file_get_contents('php://input'), true);
            $idea_id = intval($input['idea_id'] ?? 0);
            $vote_type = trim($input['vote_type'] ?? '');

            if ($idea_id <= 0 || !in_array($vote_type, ['up', 'down'], true)) {
                echo ApiResponse::error('idea_id and vote_type (up/down) are required', 400);
                exit();
            }

            $idea_exists = $helper->fetchOne("SELECT id FROM ideas WHERE id = ? LIMIT 1", [$idea_id]);
            if (!$idea_exists) {
                echo ApiResponse::error('Idea not found', 404);
                exit();
            }

            $contributor_id = getOrCreateCoordinatorContributor($helper, $connection, $current_user);
            $existing_vote = $helper->fetchOne(
                "SELECT id FROM idea_votes WHERE idea_id = ? AND contributor_id = ? LIMIT 1",
                [$idea_id, $contributor_id]
            );
            if ($existing_vote) {
                echo ApiResponse::error('You can vote only once per idea', 400);
                exit();
            }

            $helper->execute(
                "INSERT INTO idea_votes (idea_id, contributor_id, vote_type, voted_at)
                 VALUES (?, ?, ?, NOW())",
                [$idea_id, $contributor_id, $vote_type]
            );

            if ($vote_type === 'up') {
                $helper->execute(
                    "UPDATE ideas SET upvote_count = COALESCE(upvote_count, 0) + 1, like_count = COALESCE(like_count, 0) + 1 WHERE id = ?",
                    [$idea_id]
                );
            } else {
                $helper->execute(
                    "UPDATE ideas SET downvote_count = COALESCE(downvote_count, 0) + 1 WHERE id = ?",
                    [$idea_id]
                );
            }

            echo ApiResponse::success(null, 'Vote recorded successfully');
            break;

        // ===== COORDINATOR MODERATION (FLAG/HIDE) =====
        case 'moderate_content':
            if ($method !== 'POST') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $auth->requireAuth();
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $content_type = trim($input['content_type'] ?? '');
            $content_id = intval($input['content_id'] ?? 0);
            $action_type = trim($input['action'] ?? '');
            $reason = trim($input['reason'] ?? 'Moderator action');

            if (!in_array($content_type, ['Idea', 'Comment'], true) || $content_id <= 0) {
                echo ApiResponse::error('Invalid content_type or content_id', 400);
                exit();
            }
            if (!in_array($action_type, ['flag', 'hide'], true)) {
                echo ApiResponse::error('Invalid action', 400);
                exit();
            }

            $department = trim($current_user['department'] ?? '');

            if ($content_type === 'Idea') {
                $idea = $helper->fetchOne(
                    "SELECT id, department FROM ideas WHERE id = ? LIMIT 1",
                    [$content_id]
                );
                if (!$idea) {
                    echo ApiResponse::error('Idea not found', 404);
                    exit();
                }
                if (!coordinatorCanAccessDepartment($helper, $current_user, $idea['department'])) {
                    echo ApiResponse::error('Unauthorized for this department', 403);
                    exit();
                }

                if ($action_type === 'hide') {
                    $reason_text = $reason ?: 'Hidden by QA Coordinator';
                    $helper->execute(
                        "UPDATE ideas
                         SET status = 'Deleted', approval_status = 'Deleted',
                             is_inappropriate = 1, inappropriate_reason = ?,
                             flagged_by_admin_id = ?, flagged_at = NOW()
                         WHERE id = ?",
                        [$reason_text, $current_user['admin_id'], $content_id]
                    );
                    $helper->execute(
                        "INSERT INTO inappropriate_content_log (admin_id, content_type, content_id, reason, action, notes)
                         VALUES (?, 'Idea', ?, ?, ?, ?)",
                        [$current_user['admin_id'], $content_id, $reason_text, 'Hidden', 'Hidden by QA Coordinator']
                    );
                    echo ApiResponse::success(null, 'Idea hidden successfully');
                    break;
                }

                $reason_text = $reason ?: 'Flagged by QA Coordinator';
                $helper->execute(
                    "UPDATE ideas
                     SET is_inappropriate = 1, inappropriate_reason = ?, flagged_by_admin_id = ?, flagged_at = NOW(),
                         approval_status = 'Flagged'
                     WHERE id = ?",
                    [$reason_text, $current_user['admin_id'], $content_id]
                );
                $helper->execute(
                    "INSERT INTO inappropriate_content_log (admin_id, content_type, content_id, reason, action, notes)
                     VALUES (?, 'Idea', ?, ?, ?, ?)",
                    [$current_user['admin_id'], $content_id, $reason_text, 'Flagged', 'Flagged by QA Coordinator']
                );

                echo ApiResponse::success(null, 'Idea flagged successfully');
                break;
            }

            // Comment moderation
            $comment = $helper->fetchOne(
                "SELECT c.id, i.department
                 FROM comments c
                 JOIN ideas i ON i.id = c.idea_id
                 WHERE c.id = ? LIMIT 1",
                [$content_id]
            );
            if (!$comment) {
                echo ApiResponse::error('Comment not found', 404);
                exit();
            }
            if (!coordinatorCanAccessDepartment($helper, $current_user, $comment['department'])) {
                echo ApiResponse::error('Unauthorized for this department', 403);
                exit();
            }

            if ($action_type === 'flag') {
                $reason_text = $reason ?: 'Flagged by QA Coordinator';
                $helper->execute(
                    "UPDATE comments
                     SET is_inappropriate = 1, inappropriate_reason = ?, flagged_by_admin_id = ?, flagged_at = NOW()
                     WHERE id = ?",
                    [$reason_text, $current_user['admin_id'], $content_id]
                );
                $helper->execute(
                    "INSERT INTO inappropriate_content_log (admin_id, content_type, content_id, reason, action, notes)
                     VALUES (?, 'Comment', ?, ?, ?, ?)",
                    [$current_user['admin_id'], $content_id, $reason_text, 'Flagged', 'Flagged by QA Coordinator']
                );
                echo ApiResponse::success(null, 'Comment flagged successfully');
                break;
            }

            // Hide comment
            $reason_text = $reason ?: 'Hidden by QA Coordinator';
            $helper->execute(
                "UPDATE comments
                 SET is_deleted = 1, deleted_by = ?, deleted_at = NOW(), deleted_reason = ?
                 WHERE id = ?",
                [$current_user['admin_id'], $reason_text, $content_id]
            );
            $helper->execute(
                "INSERT INTO inappropriate_content_log (admin_id, content_type, content_id, reason, action, notes)
                 VALUES (?, 'Comment', ?, ?, ?, ?)",
                [$current_user['admin_id'], $content_id, $reason_text, 'Hidden', 'Hidden by QA Coordinator']
            );
            echo ApiResponse::success(null, 'Comment hidden successfully');
            break;

        // ===== LATEST COMMENTS =====
        case 'latest_comments':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $limit = max(1, min(50, intval($_GET['limit'] ?? 10)));
            $rows = $helper->fetchAll(
                "SELECT c.id, c.content, c.created_at, c.idea_id, i.title as idea_title,
                        IF(c.is_anonymous = 1, 'Anonymous', con.name) as contributor_name
                 FROM comments c
                 JOIN ideas i ON i.id = c.idea_id
                 JOIN contributors con ON con.id = c.contributor_id
                 WHERE c.is_deleted = 0
                 ORDER BY c.created_at DESC
                 LIMIT ?",
                [$limit]
            );
            echo ApiResponse::success($rows);
            break;

        // ===== NOTIFICATIONS =====
        case 'notifications':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            if (!tableExists($helper, 'system_notifications')) {
                echo ApiResponse::success([]);
                break;
            }
            $rows = $helper->fetchAll(
                "SELECT id, notification_type, title, message, idea_id, comment_id, created_at
                 FROM system_notifications
                 WHERE recipient_type = 'Coordinator' AND (
                    recipient_id = ? OR recipient_email = ?
                 )
                 ORDER BY created_at DESC
                 LIMIT 200",
                [intval($current_user['admin_id']), trim($current_user['email'] ?? '')]
            );
            echo ApiResponse::success($rows);
            break;

        // ===== LAST LOGIN TRACKING =====
        case 'login_tracking':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }

            $last_login = $helper->fetchOne(
                "SELECT last_login FROM admin_users WHERE id = ? LIMIT 1",
                [intval($current_user['admin_id'])]
            );
            $recent_sessions = tableExists($helper, 'user_sessions')
                ? $helper->fetchAll(
                    "SELECT id, ip_address, user_agent, created_at, expires_at
                     FROM user_sessions
                     WHERE admin_id = ?
                     ORDER BY created_at DESC
                     LIMIT 20",
                    [intval($current_user['admin_id'])]
                )
                : [];

            echo ApiResponse::success([
                'last_login' => $last_login['last_login'] ?? null,
                'recent_sessions' => $recent_sessions
            ]);
            break;

        // ===== TERMS COMPLIANCE AWARENESS =====
        case 'tc_compliance':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $department = trim($current_user['department'] ?? '');
            $active_tc = $helper->fetchOne(
                "SELECT version FROM terms_and_conditions WHERE is_active = 1 ORDER BY version DESC LIMIT 1",
                []
            );
            $tc_version = intval($active_tc['version'] ?? 0);

            if ($tc_version <= 0) {
                echo ApiResponse::success([
                    'tc_version' => null,
                    'total_staff' => 0,
                    'accepted_count' => 0,
                    'acceptance_rate' => 0
                ]);
                break;
            }

            $total_staff = intval($helper->fetchOne(
                "SELECT COUNT(*) as count FROM staff WHERE department = ? AND is_active = 1",
                [$department]
            )['count'] ?? 0);

            $accepted_count = intval($helper->fetchOne(
                "SELECT COUNT(DISTINCT s.id) as count
                 FROM staff s
                 JOIN staff_tc_acceptance a ON a.staff_id = s.id AND a.tc_version = ?
                 WHERE s.department = ? AND s.is_active = 1",
                [$tc_version, $department]
            )['count'] ?? 0);

            $rate = ($total_staff > 0) ? round(($accepted_count * 100) / $total_staff, 2) : 0;
            echo ApiResponse::success([
                'tc_version' => $tc_version,
                'total_staff' => $total_staff,
                'accepted_count' => $accepted_count,
                'acceptance_rate' => $rate
            ]);
            break;

        // ===== STAFF NOT SUBMITTED =====
        case 'staff_not_submitted':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $session_id = intval($_GET['session_id'] ?? 0);
            $where = "WHERE sst.department = ? AND sst.has_submitted = 0";
            $params = [trim($current_user['department'] ?? '')];
            if ($session_id > 0) {
                $where .= " AND sst.session_id = ?";
                $params[] = $session_id;
            }

            $rows = $helper->fetchAll(
                "SELECT sst.staff_id, s.name, s.email, s.department,
                        ses.session_name, ses.closes_at, ses.final_closure_date,
                        DATEDIFF(COALESCE(ses.final_closure_date, ses.closes_at), NOW()) as days_until_closure,
                        ses.id as session_id
                 FROM staff_submission_tracking sst
                 JOIN staff s ON s.id = sst.staff_id
                 JOIN sessions ses ON ses.id = sst.session_id
                 $where
                 AND s.is_active = 1
                 AND ses.status IN ('Active', 'Closed')
                 ORDER BY ses.closes_at ASC, s.name ASC",
                $params
            );
            echo ApiResponse::success($rows);
            break;

        // ===== STAFF FOR INVITE (ALL ACTIVE STAFF IN DEPARTMENT) =====
        case 'staff_for_invite':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $session_id = intval($_GET['session_id'] ?? 0);
            if ($session_id <= 0) {
                echo ApiResponse::error('session_id is required', 400);
                exit();
            }
            $department = trim($current_user['department'] ?? '');
            $rows = $helper->fetchAll(
                "SELECT s.id as staff_id, s.name, s.email, s.department,
                        ses.id as session_id, ses.session_name, ses.closes_at, ses.final_closure_date,
                        DATEDIFF(COALESCE(ses.final_closure_date, ses.closes_at), NOW()) as days_until_closure,
                        COALESCE(sst.has_submitted, 0) as has_submitted
                 FROM staff s
                 JOIN sessions ses ON ses.id = ?
                 LEFT JOIN staff_submission_tracking sst
                    ON sst.staff_id = s.id AND sst.session_id = ses.id
                 WHERE s.department = ?
                   AND s.is_active = 1
                   AND ses.status IN ('Active', 'Closed')
                 ORDER BY s.name ASC",
                [$session_id, $department]
            );
            echo ApiResponse::success($rows);
            break;

        // ===== TOP ENGAGEMENT IDEAS =====
        case 'top_engagement_ideas':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $session_id = intval($_GET['session_id'] ?? 0);
            $department = trim($current_user['department'] ?? '');
            $params = [$department];
            $where = "WHERE i.department = ? AND (i.approval_status IS NULL OR i.approval_status != 'Deleted')";
            if ($session_id > 0) {
                $where .= " AND i.session_id = ?";
                $params[] = $session_id;
            }

            $top_upvoted = $helper->fetchAll(
                "SELECT i.id, i.title, i.department, i.submitted_at,
                        COALESCE(i.upvote_count, 0) as upvote_count,
                        COALESCE(i.downvote_count, 0) as downvote_count,
                        COALESCE(i.comment_count, 0) as comment_count
                 FROM ideas i
                 $where
                 ORDER BY COALESCE(i.upvote_count, 0) DESC, i.submitted_at DESC
                 LIMIT 5",
                $params
            );

            $top_commented = $helper->fetchAll(
                "SELECT i.id, i.title, i.department, i.submitted_at,
                        COALESCE(i.comment_count, 0) as comment_count,
                        COALESCE(i.upvote_count, 0) as upvote_count,
                        COALESCE(i.downvote_count, 0) as downvote_count
                 FROM ideas i
                 $where
                 ORDER BY COALESCE(i.comment_count, 0) DESC, i.submitted_at DESC
                 LIMIT 5",
                $params
            );

            // If there is no data for the coordinator's department, fall back to all departments for visibility.
            if (empty($top_upvoted) && empty($top_commented)) {
                $params = [];
                $where = "WHERE (i.approval_status IS NULL OR i.approval_status != 'Deleted')";
                if ($session_id > 0) {
                    $where .= " AND i.session_id = ?";
                    $params[] = $session_id;
                }

                $top_upvoted = $helper->fetchAll(
                    "SELECT i.id, i.title, i.department, i.submitted_at,
                            COALESCE(i.upvote_count, 0) as upvote_count,
                            COALESCE(i.downvote_count, 0) as downvote_count,
                            COALESCE(i.comment_count, 0) as comment_count
                     FROM ideas i
                     $where
                     ORDER BY COALESCE(i.upvote_count, 0) DESC, i.submitted_at DESC
                     LIMIT 5",
                    $params
                );

                $top_commented = $helper->fetchAll(
                    "SELECT i.id, i.title, i.department, i.submitted_at,
                            COALESCE(i.comment_count, 0) as comment_count,
                            COALESCE(i.upvote_count, 0) as upvote_count,
                            COALESCE(i.downvote_count, 0) as downvote_count
                     FROM ideas i
                     $where
                     ORDER BY COALESCE(i.comment_count, 0) DESC, i.submitted_at DESC
                     LIMIT 5",
                    $params
                );
            }

            // If the selected session has no ideas at all, fall back to latest data across all sessions.
            if (empty($top_upvoted) && empty($top_commented)) {
                $top_upvoted = $helper->fetchAll(
                    "SELECT i.id, i.title, i.department, i.submitted_at,
                            COALESCE(i.upvote_count, 0) as upvote_count,
                            COALESCE(i.downvote_count, 0) as downvote_count,
                            COALESCE(i.comment_count, 0) as comment_count
                     FROM ideas i
                     WHERE (i.approval_status IS NULL OR i.approval_status != 'Deleted')
                     ORDER BY COALESCE(i.upvote_count, 0) DESC, i.submitted_at DESC
                     LIMIT 5",
                    []
                );

                $top_commented = $helper->fetchAll(
                    "SELECT i.id, i.title, i.department, i.submitted_at,
                            COALESCE(i.comment_count, 0) as comment_count,
                            COALESCE(i.upvote_count, 0) as upvote_count,
                            COALESCE(i.downvote_count, 0) as downvote_count
                     FROM ideas i
                     WHERE (i.approval_status IS NULL OR i.approval_status != 'Deleted')
                     ORDER BY COALESCE(i.comment_count, 0) DESC, i.submitted_at DESC
                     LIMIT 5",
                    []
                );
            }

            echo ApiResponse::success([
                'top_upvoted' => $top_upvoted,
                'top_commented' => $top_commented
            ]);
            break;

        // ===== UNANSWERED COMMENTS =====
        case 'unanswered_comments':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $session_id = intval($_GET['session_id'] ?? 0);
            $where = "WHERE crt.has_responses = 0 AND c.is_deleted = 0";
            $params = [];
            if ($session_id > 0) {
                $where .= " AND i.session_id = ?";
                $params[] = $session_id;
            }

            $rows = $helper->fetchAll(
                "SELECT crt.comment_id, crt.idea_id, crt.comment_author_name,
                        crt.comment_author_email, i.title as idea_title,
                        s.session_name, c.content, c.created_at,
                        TIMESTAMPDIFF(DAY, c.created_at, NOW()) as days_without_response
                 FROM comment_response_tracking crt
                 JOIN comments c ON crt.comment_id = c.id
                 JOIN ideas i ON crt.idea_id = i.id
                 JOIN sessions s ON i.session_id = s.id
                 $where
                 ORDER BY c.created_at ASC",
                $params
            );
            echo ApiResponse::success($rows);
            break;

        // ===== DEPARTMENT STATS =====
        case 'department_stats':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $session_id = intval($_GET['session_id'] ?? 0);
            $department = trim($current_user['department'] ?? '');

            $where_cached = "WHERE dss.department = ?";
            $where_calculated = "WHERE sst.department = ?";
            $params = [$department];
            if ($session_id > 0) {
                $where_cached .= " AND dss.session_id = ?";
                $where_calculated .= " AND sst.session_id = ?";
                $params[] = $session_id;
            }

            $stats = [];
            if (tableExists($helper, 'department_performance_stats')) {
                $stats = $helper->fetchAll(
                    "SELECT dss.*, s.session_name, s.closes_at, s.status
                     FROM department_performance_stats dss
                     JOIN sessions s ON s.id = dss.session_id
                     $where_cached
                     ORDER BY s.closes_at DESC",
                    $params
                );
            }

            if (empty($stats)) {
                $stats = $helper->fetchAll(
                    "SELECT sst.session_id, sst.department,
                            COUNT(DISTINCT sst.staff_id) as total_staff,
                            SUM(CASE WHEN sst.has_submitted = 1 THEN 1 ELSE 0 END) as staff_submitted,
                            SUM(CASE WHEN sst.has_submitted = 0 THEN 1 ELSE 0 END) as staff_not_submitted,
                            COUNT(DISTINCT i.id) as total_ideas,
                            COALESCE(SUM(i.comment_count), 0) as total_comments,
                            COALESCE(SUM(COALESCE(i.upvote_count, 0) + COALESCE(i.downvote_count, 0)), 0) as total_votes,
                            ROUND((SUM(CASE WHEN sst.has_submitted = 1 THEN 1 ELSE 0 END) / COUNT(DISTINCT sst.staff_id) * 100), 2) as submission_rate,
                            s.session_name, s.closes_at, s.status
                     FROM staff_submission_tracking sst
                     LEFT JOIN ideas i ON i.session_id = sst.session_id AND i.department = sst.department
                     JOIN sessions s ON s.id = sst.session_id
                     $where_calculated
                     GROUP BY sst.session_id, sst.department, s.session_name, s.closes_at, s.status",
                    $params
                );
            }

            echo ApiResponse::success($stats);
            break;

        // ===== SEND INVITATION =====
        case 'send_invitation':
            if ($method !== 'POST') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $auth->requireAuth();
            $input = json_decode(file_get_contents('php://input'), true);
            $staff_id = intval($input['staff_id'] ?? 0);
            $session_id = intval($input['session_id'] ?? 0);
            $idea_id = intval($input['idea_id'] ?? 0);
            $message = trim($input['message'] ?? 'We would love to hear your ideas.');

            if ($staff_id <= 0 || $session_id <= 0) {
                echo ApiResponse::error('staff_id and session_id are required', 400);
                exit();
            }

            $staff = $helper->fetchOne("SELECT email, name FROM staff WHERE id = ? LIMIT 1", [$staff_id]);
            if (!$staff) {
                echo ApiResponse::error('Staff not found', 404);
                exit();
            }
            if (trim($staff['email'] ?? '') === '') {
                echo ApiResponse::error('Selected staff member does not have a valid email', 400);
                exit();
            }

            $session = $helper->fetchOne(
                "SELECT session_name, closes_at, final_closure_date FROM sessions WHERE id = ? LIMIT 1",
                [$session_id]
            );

            if ($idea_id > 0) {
                $idea_exists = $helper->fetchOne(
                    "SELECT id FROM ideas WHERE id = ? AND session_id = ? LIMIT 1",
                    [$idea_id, $session_id]
                );
                if (!$idea_exists) {
                    echo ApiResponse::error('Related idea not found for selected session', 400);
                    exit();
                }
            }

            $helper->execute(
                "INSERT INTO staff_invitations (coordinator_id, staff_id, session_id, idea_id, message, sent_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [intval($current_user['admin_id']), $staff_id, $session_id, ($idea_id > 0 ? $idea_id : null), $message]
            );
            $invitation_subject = 'Invitation to submit ideas';
            $invitation_message = emailServiceBuildPlainText(
                'Hello ' . trim($staff['name'] ?? 'there') . ',',
                trim($current_user['full_name'] ?? 'Your coordinator') . ' has invited you to participate in an idea campaign.',
                [
                    'Campaign' => trim($session['session_name'] ?? ''),
                    'Coordinator' => trim($current_user['full_name'] ?? ''),
                    'Department' => trim($current_user['department'] ?? ''),
                    'Coordinator Message' => $message,
                    'Closing Date' => trim($session['final_closure_date'] ?? $session['closes_at'] ?? ''),
                ],
                [
                    'Please sign in to the system and submit your ideas before the closing date.',
                    'This is an automated notification from Ideas System',
                ]
            );
            sendSystemEmail($connection, [
                'recipient_email' => $staff['email'],
                'recipient_type' => 'Staff',
                'notification_type' => 'Invitation_Sent',
                'subject' => $invitation_subject,
                'message' => $invitation_message,
                'session_id' => $session_id
            ]);

            echo ApiResponse::success(null, 'Invitation sent successfully');
            break;

        // ===== SESSIONS =====
        case 'sessions_closure_dates':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $department = trim($current_user['department'] ?? '');
            $rows = $helper->fetchAll(
                "SELECT s.id, s.session_name, cat.name as category_name, s.opens_at, s.closes_at, s.final_closure_date,
                        s.status, a.year_label,
                        DATEDIFF(COALESCE(s.final_closure_date, s.closes_at), NOW()) as days_until_closure,
                        (SELECT COUNT(*) FROM ideas WHERE session_id = s.id AND department = ?) as total_ideas,
                        (SELECT COUNT(*) FROM staff WHERE department = ? AND is_active = 1) as total_staff
                 FROM sessions s
                 JOIN idea_categories cat ON cat.id = s.category_id
                 JOIN academic_years a ON a.id = s.academic_year_id
                 WHERE s.status IN ('Active', 'Closed')
                 ORDER BY s.closes_at ASC",
                [$department, $department]
            );
            echo ApiResponse::success($rows);
            break;

        default:
            echo ApiResponse::error('Unknown action', 400);
            break;
    }
} catch (Exception $e) {
    echo ApiResponse::error('Error: ' . $e->getMessage(), 500);
}

?>
