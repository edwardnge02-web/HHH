<?php
// api/coordinator_reports.php
// QA Coordinator: Report inappropriate content (swearing, libel, etc.)

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
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

// Check if QA Coordinator
$current_user = $auth->getCurrentUser();
if (!$current_user || $current_user['role'] !== 'QACoordinator') {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    switch ($action) {
        // ===== REPORT INAPPROPRIATE CONTENT =====
        case 'report_content':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);
                
                $content_type = $input['content_type'] ?? null; // 'Idea' or 'Comment'
                $content_id = intval($input['content_id'] ?? 0);
                $report_category = $input['report_category'] ?? null; // 'Swearing', 'Libel', etc.
                $report_reason = $input['report_reason'] ?? '';
                $description = $input['description'] ?? '';
                $severity = $input['severity'] ?? 'Medium';
                
                // Validate
                if (!$content_type || !$content_id || !$report_category) {
                    echo ApiResponse::error('Missing required fields', 400);
                    exit();
                }
                
                if (!in_array($content_type, ['Idea', 'Comment'])) {
                    echo ApiResponse::error('Invalid content type', 400);
                    exit();
                }
                
                if (!in_array($report_category, ['Swearing', 'Libel', 'Defamation', 'Harassment', 'Offensive', 'Other'])) {
                    echo ApiResponse::error('Invalid report category', 400);
                    exit();
                }
                
                // Create report
                $helper->execute(
                    "INSERT INTO coordinator_content_reports 
                     (coordinator_id, content_type, content_id, report_reason, report_category, description, severity, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'Reported')",
                    [
                        $current_user['admin_id'],
                        $content_type,
                        $content_id,
                        $report_reason,
                        $report_category,
                        $description,
                        $severity
                    ]
                );
                
                $report_id = $connection->lastInsertId();
                
                // Log email to admin
                $admin_report_subject = "Inappropriate content report: {$report_category}";
                $admin_report_message = emailServiceBuildPlainText(
                    'Hello Admin,',
                    'A QA coordinator has submitted a new inappropriate content report.',
                    [
                        'Coordinator' => trim($current_user['full_name'] ?? ''),
                        'Department' => trim($current_user['department'] ?? ''),
                        'Content Type' => trim($content_type),
                        'Content ID' => strval($content_id),
                        'Report Category' => trim($report_category),
                        'Reason' => trim($report_reason),
                        'Description' => trim($description),
                        'Severity' => trim($severity),
                    ],
                    [
                        'Please sign in to the system to review and moderate the reported content.',
                        'This is an automated notification from Ideas System',
                    ]
                );

                sendSystemEmail($connection, [
                    'recipient_email' => 'admin@example.com',
                    'recipient_type' => 'Coordinator',
                    'notification_type' => 'Inappropriate_Report',
                    'subject' => $admin_report_subject,
                    'message' => $admin_report_message
                ]);
                
                $report = [
                    'id' => $report_id,
                    'content_type' => $content_type,
                    'content_id' => $content_id,
                    'report_category' => $report_category,
                    'severity' => $severity,
                    'status' => 'Reported',
                    'reported_at' => date('Y-m-d H:i:s')
                ];
                
                echo ApiResponse::success($report, 'Content reported successfully', 201);
            }
            break;
        
        // ===== GET MY REPORTS =====
        case 'my_reports':
            if ($method === 'GET') {
                $page = intval($_GET['page'] ?? 1);
                $per_page = intval($_GET['per_page'] ?? 10);
                $offset = ($page - 1) * $per_page;
                $status = $_GET['status'] ?? null;
                
                $where = "WHERE ccr.coordinator_id = ?";
                $params = [$current_user['admin_id']];
                
                if ($status) {
                    $where .= " AND ccr.status = ?";
                    $params[] = $status;
                }
                
                // Count total
                $count_result = $helper->fetchOne(
                    "SELECT COUNT(*) as count FROM coordinator_content_reports ccr $where",
                    $params
                );
                $total = $count_result['count'];
                
                // Get reports
                $count_params = $params;
                $count_params[] = $per_page;
                $count_params[] = $offset;
                
                $reports = $helper->fetchAll(
                    "SELECT ccr.*, 
                            CASE 
                                WHEN ccr.content_type = 'Idea' THEN (SELECT title FROM ideas WHERE id = ccr.content_id)
                                WHEN ccr.content_type = 'Comment' THEN (SELECT content FROM comments WHERE id = ccr.content_id)
                            END as content_preview
                     FROM coordinator_content_reports ccr
                     $where
                     ORDER BY ccr.reported_at DESC
                     LIMIT ? OFFSET ?",
                    $count_params
                );
                
                echo ApiResponse::paginated($reports, $total, $page, $per_page);
            }
            break;
        
        // ===== GET REPORT DETAILS =====
        case 'report_details':
            if ($method === 'GET') {
                $report_id = intval($_GET['report_id'] ?? 0);
                
                if (!$report_id) {
                    echo ApiResponse::error('Report ID required', 400);
                    exit();
                }
                
                $report = $helper->fetchOne(
                    "SELECT * FROM coordinator_content_reports WHERE id = ? AND coordinator_id = ?",
                    [$report_id, $current_user['admin_id']]
                );
                
                if (!$report) {
                    echo ApiResponse::error('Report not found', 404);
                    exit();
                }
                
                // Get content details
                if ($report['content_type'] === 'Idea') {
                    $content = $helper->fetchOne(
                        "SELECT i.*, c.name as contributor_name, c.email as contributor_email
                         FROM ideas i
                         JOIN contributors c ON i.contributor_id = c.id
                         WHERE i.id = ?",
                        [$report['content_id']]
                    );
                } else {
                    $content = $helper->fetchOne(
                        "SELECT c.*, con.name as contributor_name, con.email as contributor_email, i.title as idea_title
                         FROM comments c
                         JOIN contributors con ON c.contributor_id = con.id
                         JOIN ideas i ON c.idea_id = i.id
                         WHERE c.id = ?",
                        [$report['content_id']]
                    );
                }
                
                $report['content'] = $content;
                
                echo ApiResponse::success($report);
            }
            break;
        
        // ===== ESCALATE TO ADMIN =====
        case 'escalate_report':
            if ($method === 'PUT') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);
                $report_id = intval($input['report_id'] ?? 0);
                
                if (!$report_id) {
                    echo ApiResponse::error('Report ID required', 400);
                    exit();
                }
                
                // Update report
                $helper->execute(
                    "UPDATE coordinator_content_reports 
                     SET escalated_to_admin = 1, escalated_at = NOW(), status = 'Under_Review'
                     WHERE id = ? AND coordinator_id = ?",
                    [$report_id, $current_user['admin_id']]
                );
                
                echo ApiResponse::success(null, 'Report escalated to admin');
            }
            break;
        
        // ===== GET REPORT STATISTICS =====
        case 'report_stats':
            if ($method === 'GET') {
                $session_id = $_GET['session_id'] ?? null;
                
                $where = "WHERE ccr.coordinator_id = ?";
                $params = [$current_user['admin_id']];
                
                // Get stats
                $stats = [
                    'total_reports' => $helper->fetchOne(
                        "SELECT COUNT(*) as count FROM coordinator_content_reports WHERE coordinator_id = ? AND reported_at > DATE_SUB(NOW(), INTERVAL 30 DAY)",
                        [$current_user['admin_id']]
                    )['count'] ?? 0,
                    
                    'by_category' => $helper->fetchAll(
                        "SELECT report_category, COUNT(*) as count FROM coordinator_content_reports 
                         WHERE coordinator_id = ? AND reported_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                         GROUP BY report_category
                         ORDER BY count DESC",
                        [$current_user['admin_id']]
                    ),
                    
                    'by_severity' => $helper->fetchAll(
                        "SELECT severity, COUNT(*) as count FROM coordinator_content_reports 
                         WHERE coordinator_id = ? AND reported_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                         GROUP BY severity
                         ORDER BY count DESC",
                        [$current_user['admin_id']]
                    ),
                    
                    'escalated' => $helper->fetchOne(
                        "SELECT COUNT(*) as count FROM coordinator_content_reports 
                         WHERE coordinator_id = ? AND escalated_to_admin = 1",
                        [$current_user['admin_id']]
                    )['count'] ?? 0
                ];
                
                echo ApiResponse::success($stats);
            }
            break;
        
        default:
            echo ApiResponse::error('Unknown action', 400);
    }
} catch (Exception $e) {
    echo ApiResponse::error('Error: ' . $e->getMessage(), 500);
}

?>
