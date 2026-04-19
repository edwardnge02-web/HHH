<?php
// api/qa_reports.php
// QA Manager: Generate statistical reports and export data

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
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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

function resolveAttachmentPath($raw_path) {
    $raw = trim(strval($raw_path ?? ''));
    if ($raw === '') {
        return null;
    }

    $project_root = realpath(__DIR__ . '/..');
    if ($project_root === false) {
        return null;
    }

    // Direct existing path (absolute or current-working-directory relative).
    if (file_exists($raw)) {
        return $raw;
    }

    // Normalize URL-style path from DB and map it to project root.
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $raw);
    $normalized = ltrim($normalized, DIRECTORY_SEPARATOR);
    if (stripos($normalized, 'uploads' . DIRECTORY_SEPARATOR) === 0) {
        $candidate = $project_root . DIRECTORY_SEPARATOR . $normalized;
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    // Fallback: treat as project-root relative.
    $candidate = $project_root . DIRECTORY_SEPARATOR . $normalized;
    if (file_exists($candidate)) {
        return $candidate;
    }

    return null;
}

function isExportAllowed($helper, $session_id = null) {
    if ($session_id) {
        $session = $helper->fetchOne(
            "SELECT COALESCE(final_closure_date, closes_at) as export_ready_at FROM sessions WHERE id = ?",
            [$session_id]
        );
        if (!$session || empty($session['export_ready_at'])) {
            return false;
        }
        return strtotime($session['export_ready_at']) <= time();
    }

    $pending_sessions = $helper->fetchOne(
        "SELECT COUNT(*) as count
         FROM sessions
         WHERE COALESCE(final_closure_date, closes_at) > NOW()",
        []
    );

    return intval($pending_sessions['count'] ?? 0) === 0;
}

try {
    switch ($action) {
        // ===== STATISTICS: IDEAS BY DEPARTMENT =====
        case 'ideas_by_department':
            $session_id = $_GET['session_id'] ?? null;
            
            $where = $session_id ? "WHERE s.id = ?" : "";
            $params = $session_id ? [$session_id] : [];
            
            $stats = $helper->fetchAll(
                "SELECT i.department, COUNT(*) as total_ideas, 
                        SUM(CASE WHEN i.approval_status = 'Approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN i.approval_status = 'Pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN i.is_inappropriate = 1 THEN 1 ELSE 0 END) as flagged,
                        AVG(i.like_count) as avg_likes
                 FROM ideas i
                 JOIN sessions s ON i.session_id = s.id
                 $where
                 GROUP BY i.department
                 ORDER BY total_ideas DESC",
                $params
            );
            
            echo ApiResponse::success($stats);
            break;
        
        // ===== STATISTICS: PERCENTAGE BY DEPARTMENT =====
        case 'department_percentage':
            $session_id = $_GET['session_id'] ?? null;
            
            $where = $session_id ? "WHERE s.id = ?" : "";
            $params = $session_id ? [$session_id] : [];
            
            // Get total
            $total_result = $helper->fetchOne(
                "SELECT COUNT(*) as total FROM ideas i JOIN sessions s ON i.session_id = s.id $where",
                $params
            );
            $total = $total_result['total'] ?? 1;
            
            $stats = $helper->fetchAll(
                "SELECT i.department, COUNT(*) as count,
                        ROUND((COUNT(*) * 100.0) / $total, 2) as percentage
                 FROM ideas i
                 JOIN sessions s ON i.session_id = s.id
                 $where
                 GROUP BY i.department
                 ORDER BY count DESC",
                $params
            );
            
            echo ApiResponse::success($stats);
            break;
        
        // ===== STATISTICS: CONTRIBUTORS BY DEPARTMENT =====
        case 'contributors_by_department':
            $session_id = $_GET['session_id'] ?? null;
            
            $conditions = [
                "c.department IS NOT NULL",
                "c.is_anonymous = 0",
            ];
            $params = [];
            if ($session_id) {
                $conditions[] = "s.id = ?";
                $params[] = $session_id;
            }
            $where_clause = "WHERE " . implode(" AND ", $conditions);
            
            $stats = $helper->fetchAll(
                "SELECT c.department, COUNT(DISTINCT c.id) as contributor_count,
                        COUNT(i.id) as total_ideas,
                        AVG(i.like_count) as avg_engagement
                 FROM contributors c
                 LEFT JOIN ideas i ON c.id = i.contributor_id
                 LEFT JOIN sessions s ON i.session_id = s.id
                 $where_clause
                 GROUP BY c.department
                 ORDER BY contributor_count DESC",
                $params
            );
            
            echo ApiResponse::success($stats);
            break;
        
        // ===== STATISTICS: IDEAS STATUS SUMMARY =====
        case 'ideas_status_summary':
            $session_id = $_GET['session_id'] ?? null;
            
            $where = $session_id ? "WHERE s.id = ?" : "";
            $params = $session_id ? [$session_id] : [];
            
            $stats = $helper->fetchAll(
                "SELECT i.approval_status as status, COUNT(*) as count
                 FROM ideas i
                 JOIN sessions s ON i.session_id = s.id
                 $where
                 GROUP BY i.approval_status
                 ORDER BY count DESC",
                $params
            );
            
            echo ApiResponse::success($stats);
            break;

        // ===== STATISTICS: IDEAS WITHOUT COMMENTS (SUMMARY) =====
        case 'ideas_without_comments_summary':
            $session_id = $_GET['session_id'] ?? null;

            $where = $session_id ? "WHERE s.id = ?" : "";
            $params = $session_id ? [$session_id] : [];

            $stats = $helper->fetchAll(
                "SELECT i.department,
                        SUM(CASE WHEN i.comment_count = 0 THEN 1 ELSE 0 END) as no_comment_ideas,
                        SUM(CASE WHEN i.comment_count = 0 AND i.approval_status = 'Approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN i.comment_count = 0 AND i.approval_status = 'Pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN i.comment_count = 0 AND i.is_inappropriate = 1 THEN 1 ELSE 0 END) as flagged
                 FROM ideas i
                 JOIN sessions s ON i.session_id = s.id
                 $where
                 GROUP BY i.department
                 HAVING no_comment_ideas > 0
                 ORDER BY no_comment_ideas DESC",
                $params
            );

            echo ApiResponse::success($stats);
            break;

        // ===== STATISTICS: IDEAS WITHOUT COMMENTS (LIST) =====
        case 'ideas_without_comments_list':
            $session_id = $_GET['session_id'] ?? null;
            $where = $session_id ? "WHERE s.id = ? AND i.comment_count = 0" : "WHERE i.comment_count = 0";
            $params = $session_id ? [$session_id] : [];

            $rows = $helper->fetchAll(
                "SELECT i.id, i.title, i.department, i.approval_status, i.is_inappropriate,
                        i.submitted_at, i.comment_count, i.is_anonymous,
                        c.name as contributor_name, c.email as contributor_email,
                        s.session_name
                 FROM ideas i
                 JOIN sessions s ON i.session_id = s.id
                 LEFT JOIN contributors c ON c.id = i.contributor_id
                 $where
                 ORDER BY i.submitted_at DESC
                 LIMIT 200",
                $params
            );

            echo ApiResponse::success($rows);
            break;

        // ===== STATISTICS: ANONYMOUS ACTIVITY SUMMARY =====
        case 'anonymous_activity_summary':
            $session_id = $_GET['session_id'] ?? null;
            $idea_where = $session_id ? "WHERE s.id = ?" : "";
            $idea_params = $session_id ? [$session_id] : [];
            $comment_where = $session_id ? "WHERE s.id = ? AND cm.is_deleted = 0" : "WHERE cm.is_deleted = 0";
            $comment_params = $session_id ? [$session_id] : [];

            $idea_total = $helper->fetchOne(
                "SELECT COUNT(*) as count FROM ideas i JOIN sessions s ON i.session_id = s.id $idea_where",
                $idea_params
            );
            $idea_anon = $helper->fetchOne(
                "SELECT COUNT(*) as count FROM ideas i JOIN sessions s ON i.session_id = s.id $idea_where AND i.is_anonymous = 1",
                $idea_params
            );
            $comment_total = $helper->fetchOne(
                "SELECT COUNT(*) as count
                 FROM comments cm
                 JOIN ideas i ON i.id = cm.idea_id
                 JOIN sessions s ON i.session_id = s.id
                 $comment_where",
                $comment_params
            );
            $comment_anon = $helper->fetchOne(
                "SELECT COUNT(*) as count
                 FROM comments cm
                 JOIN ideas i ON i.id = cm.idea_id
                 JOIN sessions s ON i.session_id = s.id
                 $comment_where AND cm.is_anonymous = 1",
                $comment_params
            );

            $idea_total_count = intval($idea_total['count'] ?? 0);
            $idea_anon_count = intval($idea_anon['count'] ?? 0);
            $comment_total_count = intval($comment_total['count'] ?? 0);
            $comment_anon_count = intval($comment_anon['count'] ?? 0);

            echo ApiResponse::success([
                'total_ideas' => $idea_total_count,
                'anonymous_ideas' => $idea_anon_count,
                'total_comments' => $comment_total_count,
                'anonymous_comments' => $comment_anon_count,
                'anonymous_idea_pct' => $idea_total_count > 0 ? round(($idea_anon_count * 100) / $idea_total_count, 2) : 0,
                'anonymous_comment_pct' => $comment_total_count > 0 ? round(($comment_anon_count * 100) / $comment_total_count, 2) : 0
            ]);
            break;

        // ===== STATISTICS: ANONYMOUS ACTIVITY BY DEPARTMENT =====
        case 'anonymous_activity_by_department':
            $session_id = $_GET['session_id'] ?? null;
            $idea_where = $session_id ? "WHERE s.id = ?" : "";
            $idea_params = $session_id ? [$session_id] : [];

            $idea_rows = $helper->fetchAll(
                "SELECT i.department,
                        COUNT(*) as total_ideas,
                        SUM(CASE WHEN i.is_anonymous = 1 THEN 1 ELSE 0 END) as anonymous_ideas
                 FROM ideas i
                 JOIN sessions s ON i.session_id = s.id
                 $idea_where
                 GROUP BY i.department",
                $idea_params
            );

            $comment_rows = $helper->fetchAll(
                "SELECT i.department,
                        COUNT(*) as total_comments,
                        SUM(CASE WHEN cm.is_anonymous = 1 THEN 1 ELSE 0 END) as anonymous_comments
                 FROM comments cm
                 JOIN ideas i ON i.id = cm.idea_id
                 JOIN sessions s ON i.session_id = s.id
                 WHERE cm.is_deleted = 0" . ($session_id ? " AND s.id = ?" : "") . "
                 GROUP BY i.department",
                $idea_params
            );

            $dept_map = [];
            foreach ($idea_rows as $row) {
                $dept = $row['department'] ?? 'Unassigned';
                $dept_map[$dept] = [
                    'department' => $dept,
                    'total_ideas' => intval($row['total_ideas'] ?? 0),
                    'anonymous_ideas' => intval($row['anonymous_ideas'] ?? 0),
                    'total_comments' => 0,
                    'anonymous_comments' => 0
                ];
            }
            foreach ($comment_rows as $row) {
                $dept = $row['department'] ?? 'Unassigned';
                if (!isset($dept_map[$dept])) {
                    $dept_map[$dept] = [
                        'department' => $dept,
                        'total_ideas' => 0,
                        'anonymous_ideas' => 0,
                        'total_comments' => 0,
                        'anonymous_comments' => 0
                    ];
                }
                $dept_map[$dept]['total_comments'] = intval($row['total_comments'] ?? 0);
                $dept_map[$dept]['anonymous_comments'] = intval($row['anonymous_comments'] ?? 0);
            }

            $rows = array_values($dept_map);
            usort($rows, function ($a, $b) {
                $a_total = ($a['anonymous_ideas'] ?? 0) + ($a['anonymous_comments'] ?? 0);
                $b_total = ($b['anonymous_ideas'] ?? 0) + ($b['anonymous_comments'] ?? 0);
                return $b_total <=> $a_total;
            });

            echo ApiResponse::success($rows);
            break;

        // ===== LIST: ANONYMOUS IDEAS =====
        case 'anonymous_ideas_list':
            $session_id = $_GET['session_id'] ?? null;
            $where = $session_id ? "WHERE s.id = ? AND i.is_anonymous = 1" : "WHERE i.is_anonymous = 1";
            $params = $session_id ? [$session_id] : [];

            $rows = $helper->fetchAll(
                "SELECT i.id, i.title, i.department, i.approval_status, i.submitted_at, i.comment_count,
                        c.name as contributor_name, c.email as contributor_email,
                        s.session_name
                 FROM ideas i
                 JOIN sessions s ON i.session_id = s.id
                 LEFT JOIN contributors c ON c.id = i.contributor_id
                 $where
                 ORDER BY i.submitted_at DESC
                 LIMIT 200",
                $params
            );

            echo ApiResponse::success($rows);
            break;

        // ===== LIST: ANONYMOUS COMMENTS =====
        case 'anonymous_comments_list':
            $session_id = $_GET['session_id'] ?? null;
            $where = $session_id ? "WHERE s.id = ? AND cm.is_anonymous = 1 AND cm.is_deleted = 0" : "WHERE cm.is_anonymous = 1 AND cm.is_deleted = 0";
            $params = $session_id ? [$session_id] : [];

            $rows = $helper->fetchAll(
                "SELECT cm.id, cm.content, cm.created_at, cm.idea_id,
                        i.title as idea_title, i.department,
                        c.name as contributor_name, c.email as contributor_email,
                        s.session_name
                 FROM comments cm
                 JOIN ideas i ON i.id = cm.idea_id
                 JOIN sessions s ON i.session_id = s.id
                 LEFT JOIN contributors c ON c.id = cm.contributor_id
                 $where
                 ORDER BY cm.created_at DESC
                 LIMIT 200",
                $params
            );

            echo ApiResponse::success($rows);
            break;
        
        // ===== EXPORT: CSV ALL IDEAS DATA =====
        case 'export_csv':
            $session_id = $_GET['session_id'] ?? null;
            if (!isExportAllowed($helper, $session_id)) {
                echo ApiResponse::error('Export is only allowed after final closure date', 403);
                exit();
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="ideas_export_' . date('Y-m-d_H-i-s') . '.csv"');
            
            $where = $session_id ? "WHERE s.id = ?" : "";
            $params = $session_id ? [$session_id] : [];
            $tag_table = $helper->fetchOne(
                "SELECT COUNT(*) as count
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'idea_category_tags'",
                []
            );
            $has_tag_table = intval($tag_table['count'] ?? 0) > 0;
            $tag_select = $has_tag_table
                ? "GROUP_CONCAT(DISTINCT ict_cat.name ORDER BY ict_cat.name SEPARATOR '; ') as tags"
                : "'' as tags";
            $tag_join = $has_tag_table
                ? "LEFT JOIN idea_category_tags ict ON ict.idea_id = i.id
                   LEFT JOIN idea_categories ict_cat ON ict.category_id = ict_cat.id"
                : "";
            
            $ideas = $helper->fetchAll(
                "SELECT i.id, i.title, i.description, i.department, i.impact_level,
                        i.status, i.approval_status, i.is_inappropriate,
                        c.name as contributor, c.email, c.is_anonymous,
                        i.submitted_at, i.like_count, i.comment_count,
                        cat.name as category, s.session_name,
                        $tag_select
                 FROM ideas i
                 JOIN contributors c ON i.contributor_id = c.id
                 JOIN sessions s ON i.session_id = s.id
                 JOIN idea_categories cat ON s.category_id = cat.id
                 $tag_join
                 $where
                 GROUP BY i.id, i.title, i.description, i.department, i.impact_level,
                          i.status, i.approval_status, i.is_inappropriate,
                          c.name, c.email, c.is_anonymous,
                          i.submitted_at, i.like_count, i.comment_count,
                          cat.name, s.session_name
                 ORDER BY i.created_at DESC",
                $params
            );
            
            // Output CSV header
            $output = fopen('php://output', 'w');
            
            if (!empty($ideas)) {
                // Headers
                fputcsv($output, array_keys($ideas[0]));
                
                // Data
                foreach ($ideas as $idea) {
                    fputcsv($output, $idea);
                }
            }
            
            fclose($output);
            exit();
        
        // ===== EXPORT: ZIP ATTACHMENTS =====
        case 'export_zip':
            $session_id = $_GET['session_id'] ?? null;
            if (!isExportAllowed($helper, $session_id)) {
                echo ApiResponse::error('Export is only allowed after final closure date', 403);
                exit();
            }
            
            $where = $session_id ? "WHERE s.id = ?" : "";
            $params = $session_id ? [$session_id] : [];
            
            $attachments = $helper->fetchAll(
                "SELECT ia.id, ia.file_name, ia.file_path, i.title, i.id as idea_id
                 FROM idea_attachments ia
                 JOIN ideas i ON ia.idea_id = i.id
                 JOIN sessions s ON i.session_id = s.id
                 $where",
                $params
            );
            
            // Create ZIP file
            $zip_filename = 'attachments_export_' . date('Y-m-d_H-i-s') . '.zip';
            $temp_dir = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'temp';
            $zip_path = $temp_dir . DIRECTORY_SEPARATOR . $zip_filename;
            
            // Create temp directory if needed
            if (!is_dir($temp_dir)) {
                mkdir($temp_dir, 0777, true);
            }
            
            if (!class_exists('ZipArchive')) {
                echo ApiResponse::error('ZIP export is not available on this server', 500);
                exit();
            }
            
            $zip = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE) === TRUE) {
                $added_count = 0;
                if (empty($attachments)) {
                    // Keep export successful even when there are currently no files.
                    if ($zip->addFromString('README.txt', "No attachments were found for the selected scope at export time.")) {
                        $added_count++;
                    }
                }
                foreach ($attachments as $attachment) {
                    $real_path = resolveAttachmentPath($attachment['file_path'] ?? '');
                    if ($real_path && file_exists($real_path)) {
                        $local_name = $attachment['idea_id'] . '_' . $attachment['file_name'];
                        if ($zip->addFile($real_path, $local_name)) {
                            $added_count++;
                        }
                    }
                }
                if ($added_count === 0) {
                    // Rows exist but physical files are missing/unreadable; keep archive valid.
                    $zip->addFromString('README.txt', "Attachment records were found, but files are missing or unreadable on the server.");
                }
                if ($zip->close() !== true) {
                    echo ApiResponse::error('Unable to finalize ZIP archive', 500);
                    exit();
                }
            } else {
                echo ApiResponse::error('Unable to create ZIP archive', 500);
                exit();
            }

            if (!file_exists($zip_path)) {
                echo ApiResponse::error('ZIP archive was not created', 500);
                exit();
            }
            
            // Output ZIP file
            if (ob_get_length()) {
                ob_clean();
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
            header('Content-Length: ' . filesize($zip_path));
            readfile($zip_path);
            
            // Clean up temp file
            if (file_exists($zip_path)) {
                unlink($zip_path);
            }
            exit();
        
        // ===== INAPPROPRIATE CONTENT STATISTICS =====
        case 'inappropriate_stats':
            $stats = [
                'total_flagged_ideas' => $helper->fetchOne(
                    "SELECT COUNT(*) as count FROM ideas WHERE is_inappropriate = 1",
                    []
                )['count'],
                'total_flagged_comments' => $helper->fetchOne(
                    "SELECT COUNT(*) as count FROM comments WHERE is_inappropriate = 1",
                    []
                )['count'],
                'disabled_users' => $helper->fetchOne(
                    "SELECT COUNT(*) as count FROM contributors WHERE account_status = 'Disabled'",
                    []
                )['count'],
                'blocked_users' => $helper->fetchOne(
                    "SELECT COUNT(*) as count FROM contributors WHERE account_status = 'Blocked'",
                    []
                )['count'],
                'recent_flags' => $helper->fetchAll(
                    "SELECT admin_id, action, COUNT(*) as count, MAX(created_at) as last_action
                     FROM inappropriate_content_log
                     WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY admin_id, action
                     ORDER BY count DESC",
                    []
                )
            ];
            
            echo ApiResponse::success($stats);
            break;

        // ===== QA ENGAGEMENT OVERVIEW =====
        case 'engagement_overview':
            $most_popular_ideas = $helper->fetchAll(
                "SELECT i.id, i.title, i.department, i.like_count, COALESCE(i.downvote_count, 0) as downvote_count,
                        (i.like_count - COALESCE(i.downvote_count, 0)) as popularity_score,
                        COALESCE(i.view_count, 0) as view_count, i.submitted_at
                 FROM ideas i
                 ORDER BY popularity_score DESC, i.like_count DESC, COALESCE(i.view_count, 0) DESC
                 LIMIT 10",
                []
            );

            $most_viewed_ideas = $helper->fetchAll(
                "SELECT i.id, i.title, i.department, COALESCE(i.view_count, 0) as view_count,
                        i.like_count, COALESCE(i.downvote_count, 0) as downvote_count
                 FROM ideas i
                 ORDER BY COALESCE(i.view_count, 0) DESC, i.submitted_at DESC
                 LIMIT 10",
                []
            );

            $latest_ideas = $helper->fetchAll(
                "SELECT i.id, i.title, i.department, i.submitted_at, i.like_count, COALESCE(i.view_count, 0) as view_count,
                        c.name as contributor_name, c.is_anonymous
                 FROM ideas i
                 LEFT JOIN contributors c ON c.id = i.contributor_id
                 ORDER BY i.submitted_at DESC
                 LIMIT 10",
                []
            );

            $latest_comments = $helper->fetchAll(
                "SELECT cm.id, cm.content, cm.created_at, cm.idea_id, i.title as idea_title,
                        con.name as contributor_name, con.is_anonymous
                 FROM comments cm
                 JOIN ideas i ON i.id = cm.idea_id
                 JOIN sessions s ON s.id = i.session_id
                 LEFT JOIN contributors con ON con.id = cm.contributor_id
                 WHERE cm.is_deleted = 0
                   AND COALESCE(s.final_closure_date, s.closes_at) >= DATE_SUB(NOW(), INTERVAL 180 DAY)
                 ORDER BY cm.created_at DESC
                 LIMIT 10",
                []
            );

            echo ApiResponse::success([
                'most_popular_ideas' => $most_popular_ideas,
                'most_viewed_ideas' => $most_viewed_ideas,
                'latest_ideas' => $latest_ideas,
                'latest_comments' => $latest_comments
            ]);
            break;

        // ===== QA MONITORING OVERVIEW =====
        case 'monitoring_overview':
            $daily_idea_activity = $helper->fetchAll(
                "SELECT DATE(i.submitted_at) as day, COUNT(*) as ideas
                 FROM ideas i
                 WHERE i.submitted_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                 GROUP BY DATE(i.submitted_at)
                 ORDER BY day ASC",
                []
            );
            $daily_comment_activity = $helper->fetchAll(
                "SELECT DATE(c.created_at) as day, COUNT(*) as comments
                 FROM comments c
                 WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                 GROUP BY DATE(c.created_at)
                 ORDER BY day ASC",
                []
            );

            $most_active_users = $helper->fetchAll(
                "SELECT con.id as contributor_id, con.name, con.email, con.department,
                        COALESCE(i.idea_count, 0) as ideas_submitted,
                        COALESCE(cm.comment_count, 0) as comments_posted,
                        (COALESCE(i.idea_count, 0) + COALESCE(cm.comment_count, 0)) as activity_score
                 FROM contributors con
                 LEFT JOIN (
                     SELECT contributor_id, COUNT(*) as idea_count
                     FROM ideas
                     GROUP BY contributor_id
                 ) i ON i.contributor_id = con.id
                 LEFT JOIN (
                     SELECT contributor_id, COUNT(*) as comment_count
                     FROM comments
                     WHERE is_deleted = 0
                     GROUP BY contributor_id
                 ) cm ON cm.contributor_id = con.id
                 ORDER BY activity_score DESC, ideas_submitted DESC
                 LIMIT 10",
                []
            );

            $browser_usage = tableExists($helper, 'user_sessions')
                ? $helper->fetchAll(
                    "SELECT browser_name, COUNT(*) as session_count
                     FROM (
                         SELECT CASE
                                    WHEN user_agent LIKE '%Edg/%' THEN 'Microsoft Edge'
                                    WHEN user_agent LIKE '%Chrome/%' AND user_agent NOT LIKE '%Edg/%' THEN 'Google Chrome'
                                    WHEN user_agent LIKE '%Firefox/%' THEN 'Mozilla Firefox'
                                    WHEN user_agent LIKE '%Safari/%' AND user_agent NOT LIKE '%Chrome/%' THEN 'Safari'
                                    WHEN user_agent LIKE '%OPR/%' THEN 'Opera'
                                    ELSE 'Other'
                                END as browser_name
                         FROM user_sessions
                     ) ua
                     GROUP BY browser_name
                     ORDER BY session_count DESC",
                    []
                )
                : [];

            $most_viewed_pages = $helper->fetchAll(
                "SELECT CONCAT('/ideas/', i.id) as page_path, i.title as page_title, COALESCE(i.view_count, 0) as view_count
                 FROM ideas i
                 ORDER BY COALESCE(i.view_count, 0) DESC, i.submitted_at DESC
                 LIMIT 10",
                []
            );

            $storage = $helper->fetchOne(
                "SELECT COUNT(*) as total_files, COALESCE(SUM(file_size), 0) as total_bytes
                 FROM idea_attachments",
                []
            );

            echo ApiResponse::success([
                'daily_idea_activity' => $daily_idea_activity,
                'daily_comment_activity' => $daily_comment_activity,
                'most_active_users' => $most_active_users,
                'browser_usage' => $browser_usage,
                'most_viewed_pages' => $most_viewed_pages,
                'storage_usage' => $storage
            ]);
            break;

        // ===== QA SECURITY & AUDIT =====
        case 'security_audit':
            $login_activity = tableExists($helper, 'user_sessions')
                ? $helper->fetchAll(
                    "SELECT au.id as admin_id, au.full_name, au.email, au.role,
                            MAX(us.created_at) as last_login_at,
                            COUNT(*) as login_count_30d,
                            COUNT(DISTINCT us.ip_address) as distinct_ip_30d
                     FROM admin_users au
                     LEFT JOIN user_sessions us ON us.admin_id = au.id
                        AND us.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY au.id, au.full_name, au.email, au.role
                     ORDER BY last_login_at DESC",
                    []
                )
                : [];

            $suspicious_activity = tableExists($helper, 'user_sessions')
                ? $helper->fetchAll(
                    "SELECT us.admin_id, au.full_name, au.email, COUNT(DISTINCT us.ip_address) as ip_count,
                            COUNT(*) as session_count, MAX(us.created_at) as last_seen
                     FROM user_sessions us
                     JOIN admin_users au ON au.id = us.admin_id
                     WHERE us.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                     GROUP BY us.admin_id, au.full_name, au.email
                     HAVING COUNT(DISTINCT us.ip_address) >= 3
                     ORDER BY ip_count DESC, session_count DESC",
                    []
                )
                : [];

            $audit_summary = tableExists($helper, 'audit_logs')
                ? [
                    'last_7_days' => intval($helper->fetchOne(
                        "SELECT COUNT(*) as count FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                        []
                    )['count'] ?? 0),
                    'last_30_days' => intval($helper->fetchOne(
                        "SELECT COUNT(*) as count FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                        []
                    )['count'] ?? 0),
                  ]
                : ['last_7_days' => 0, 'last_30_days' => 0];

            echo ApiResponse::success([
                'login_activity' => $login_activity,
                'suspicious_activity' => $suspicious_activity,
                'audit_summary' => $audit_summary
            ]);
            break;

        // ===== QA COORDINATOR OVERSIGHT =====
        case 'coordinator_overview':
            $coordinators = tableExists($helper, 'qa_coordinator_departments')
                ? $helper->fetchAll(
                    "SELECT qcd.coordinator_id, au.full_name as coordinator_name, au.email as coordinator_email,
                            qcd.department, qcd.is_active,
                            COALESCE(i.idea_count, 0) as ideas_in_department,
                            COALESCE(cu.contributor_count, 0) as contributors_in_department
                     FROM qa_coordinator_departments qcd
                     LEFT JOIN admin_users au ON au.id = qcd.coordinator_id
                     LEFT JOIN (
                         SELECT department, COUNT(*) as idea_count
                         FROM ideas
                         GROUP BY department
                     ) i ON i.department = qcd.department
                     LEFT JOIN (
                         SELECT department, COUNT(*) as contributor_count
                         FROM contributors
                         GROUP BY department
                     ) cu ON cu.department = qcd.department
                     ORDER BY qcd.department ASC, qcd.assigned_at DESC",
                    []
                )
                : [];

            $department_coverage = tableExists($helper, 'departments')
                ? $helper->fetchAll(
                    "SELECT d.name as department_name,
                            d.qa_coordinator_id,
                            au.full_name as qa_coordinator_name,
                            CASE WHEN d.qa_coordinator_id IS NULL THEN 0 ELSE 1 END as has_coordinator
                     FROM departments d
                     LEFT JOIN admin_users au ON au.id = d.qa_coordinator_id
                     ORDER BY d.name ASC",
                    []
                )
                : [];

            echo ApiResponse::success([
                'coordinators' => $coordinators,
                'department_coverage' => $department_coverage
            ]);
            break;
        
        default:
            echo ApiResponse::error('Unknown action', 400);
    }
} catch (Exception $e) {
    echo ApiResponse::error('Error: ' . $e->getMessage(), 500);
}

?>
