<?php
// api/qa_management.php
// QA Manager: Manage comments, flag inappropriate, manage user accounts

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

// Check QA Manager access
$current_user = $auth->getCurrentUser();
if (!$current_user || !in_array($current_user['role'], ['Admin', 'QAManager'])) {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$type = $_GET['type'] ?? null;

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

function tableColumns($helper, $table_name) {
    $rows = $helper->fetchAll(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table_name]
    );
    $cols = [];
    foreach ($rows as $row) {
        $cols[$row['COLUMN_NAME']] = true;
    }
    return $cols;
}

function normalizeDepartmentName($name) {
    return trim(preg_replace('/\s+/', ' ', strval($name)));
}

function syncCoordinatorAssignment($helper, $department_name, $coordinator_id) {
    if (!$department_name) {
        return;
    }

    $helper->execute(
        "UPDATE qa_coordinator_departments SET is_active = 0 WHERE department = ?",
        [$department_name]
    );

    if ($coordinator_id) {
        $helper->execute(
            "INSERT INTO qa_coordinator_departments (coordinator_id, department, assigned_at, is_active)
             VALUES (?, ?, NOW(), 1)
             ON DUPLICATE KEY UPDATE assigned_at = NOW(), is_active = 1",
            [$coordinator_id, $department_name]
        );
    }
}

function ensureQASchema($helper) {
    $helper->execute(
        "CREATE TABLE IF NOT EXISTS qa_hidden_content_records (
            id INT PRIMARY KEY AUTO_INCREMENT,
            contributor_id INT NOT NULL,
            content_type ENUM('Idea','Comment','Reply') NOT NULL,
            content_id INT NOT NULL,
            previous_state_json LONGTEXT NOT NULL,
            hidden_by_admin_id INT NOT NULL,
            hidden_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_restored TINYINT(1) NOT NULL DEFAULT 0,
            restored_by_admin_id INT NULL,
            restored_at TIMESTAMP NULL,
            INDEX idx_hidden_contributor (contributor_id, is_restored),
            INDEX idx_hidden_content (content_type, content_id)
        )",
        []
    );

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

function hideContributorContent($helper, $connection, $contributor_id, $admin_id) {
    $has_idea_approval_status = columnExists($helper, 'ideas', 'approval_status');
    $has_comment_replies = tableExists($helper, 'comment_replies');

    $idea_rows = $helper->fetchAll(
        "SELECT id, is_inappropriate, inappropriate_reason" . ($has_idea_approval_status ? ", approval_status" : "") . "
         FROM ideas
         WHERE contributor_id = ? AND is_inappropriate = 0",
        [$contributor_id]
    );
    $comment_rows = $helper->fetchAll(
        "SELECT id, is_deleted
         FROM comments
         WHERE contributor_id = ? AND is_deleted = 0",
        [$contributor_id]
    );
    $reply_rows = $has_comment_replies
        ? $helper->fetchAll(
            "SELECT id, is_deleted
             FROM comment_replies
             WHERE contributor_id = ? AND is_deleted = 0",
            [$contributor_id]
        )
        : [];

    $marker = 'Hidden by QA Manager - user moderation action';
    $hidden = ['ideas' => 0, 'comments' => 0, 'replies' => 0];

    $connection->beginTransaction();
    try {
        foreach ($idea_rows as $row) {
            $previous_state = [
                'is_inappropriate' => intval($row['is_inappropriate'] ?? 0),
                'inappropriate_reason' => $row['inappropriate_reason'] ?? null,
            ];
            if ($has_idea_approval_status) {
                $previous_state['approval_status'] = $row['approval_status'] ?? null;
            }

            $helper->execute(
                "INSERT INTO qa_hidden_content_records (contributor_id, content_type, content_id, previous_state_json, hidden_by_admin_id)
                 VALUES (?, 'Idea', ?, ?, ?)",
                [$contributor_id, intval($row['id']), json_encode($previous_state), $admin_id]
            );

            if ($has_idea_approval_status) {
                $helper->execute(
                    "UPDATE ideas
                     SET is_inappropriate = 1, inappropriate_reason = ?, approval_status = 'Flagged'
                     WHERE id = ?",
                    [$marker, intval($row['id'])]
                );
            } else {
                $helper->execute(
                    "UPDATE ideas
                     SET is_inappropriate = 1, inappropriate_reason = ?
                     WHERE id = ?",
                    [$marker, intval($row['id'])]
                );
            }
            $hidden['ideas']++;
        }

        foreach ($comment_rows as $row) {
            $previous_state = ['is_deleted' => intval($row['is_deleted'] ?? 0)];
            $helper->execute(
                "INSERT INTO qa_hidden_content_records (contributor_id, content_type, content_id, previous_state_json, hidden_by_admin_id)
                 VALUES (?, 'Comment', ?, ?, ?)",
                [$contributor_id, intval($row['id']), json_encode($previous_state), $admin_id]
            );
            $helper->execute("UPDATE comments SET is_deleted = 1 WHERE id = ?", [intval($row['id'])]);
            $hidden['comments']++;
        }

        foreach ($reply_rows as $row) {
            $previous_state = ['is_deleted' => intval($row['is_deleted'] ?? 0)];
            $helper->execute(
                "INSERT INTO qa_hidden_content_records (contributor_id, content_type, content_id, previous_state_json, hidden_by_admin_id)
                 VALUES (?, 'Reply', ?, ?, ?)",
                [$contributor_id, intval($row['id']), json_encode($previous_state), $admin_id]
            );
            $helper->execute("UPDATE comment_replies SET is_deleted = 1 WHERE id = ?", [intval($row['id'])]);
            $hidden['replies']++;
        }

        $connection->commit();
    } catch (Exception $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $e;
    }

    return $hidden;
}

function restoreContributorHiddenContent($helper, $connection, $contributor_id, $admin_id) {
    $has_idea_approval_status = columnExists($helper, 'ideas', 'approval_status');
    $has_comment_replies = tableExists($helper, 'comment_replies');

    $records = $helper->fetchAll(
        "SELECT id, content_type, content_id, previous_state_json
         FROM qa_hidden_content_records
         WHERE contributor_id = ? AND is_restored = 0
         ORDER BY id ASC",
        [$contributor_id]
    );

    $restored = ['ideas' => 0, 'comments' => 0, 'replies' => 0];

    $connection->beginTransaction();
    try {
        foreach ($records as $record) {
            $previous_state = json_decode($record['previous_state_json'] ?? '{}', true);
            $content_type = $record['content_type'];
            $content_id = intval($record['content_id']);

            if ($content_type === 'Idea') {
                $old_inappropriate = intval($previous_state['is_inappropriate'] ?? 0);
                $old_reason = $previous_state['inappropriate_reason'] ?? null;
                if ($has_idea_approval_status) {
                    $old_approval = $previous_state['approval_status'] ?? 'Pending';
                    $helper->execute(
                        "UPDATE ideas
                         SET is_inappropriate = ?, inappropriate_reason = ?, approval_status = ?
                         WHERE id = ?",
                        [$old_inappropriate, $old_reason, $old_approval, $content_id]
                    );
                } else {
                    $helper->execute(
                        "UPDATE ideas
                         SET is_inappropriate = ?, inappropriate_reason = ?
                         WHERE id = ?",
                        [$old_inappropriate, $old_reason, $content_id]
                    );
                }
                $restored['ideas']++;
            } elseif ($content_type === 'Comment') {
                $old_deleted = intval($previous_state['is_deleted'] ?? 0);
                $helper->execute("UPDATE comments SET is_deleted = ? WHERE id = ?", [$old_deleted, $content_id]);
                $restored['comments']++;
            } elseif ($content_type === 'Reply' && $has_comment_replies) {
                $old_deleted = intval($previous_state['is_deleted'] ?? 0);
                $helper->execute("UPDATE comment_replies SET is_deleted = ? WHERE id = ?", [$old_deleted, $content_id]);
                $restored['replies']++;
            }

            $helper->execute(
                "UPDATE qa_hidden_content_records
                 SET is_restored = 1, restored_by_admin_id = ?, restored_at = NOW()
                 WHERE id = ?",
                [$admin_id, intval($record['id'])]
            );
        }

        $connection->commit();
    } catch (Exception $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $e;
    }

    return $restored;
}

try {
    ensureQASchema($helper);

    switch ($action) {
        case 'notifications':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            if (!tableExists($helper, 'email_notifications')) {
                echo ApiResponse::success([]);
                break;
            }
            $columns = tableColumns($helper, 'email_notifications');
            if (!isset($columns['recipient_email'])) {
                echo ApiResponse::success([]);
                break;
            }

            $selectable = ['id', 'recipient_email', 'recipient_type', 'notification_type', 'subject', 'message', 'status', 'sent_at', 'created_at'];
            $select = [];
            foreach ($selectable as $col) {
                if (isset($columns[$col])) {
                    $select[] = $col;
                }
            }
            if (empty($select)) {
                echo ApiResponse::success([]);
                break;
            }

            $order_by = isset($columns['sent_at']) ? 'sent_at' : (isset($columns['created_at']) ? 'created_at' : null);
            $recipient_email = trim($current_user['email'] ?? '');
            $sql = "SELECT " . implode(', ', $select) . " FROM email_notifications WHERE recipient_email = ?";
            if ($order_by) {
                $sql .= " ORDER BY " . $order_by . " DESC";
            }
            $sql .= " LIMIT 200";
            $rows = $helper->fetchAll($sql, [$recipient_email]);
            echo ApiResponse::success($rows);
            break;

        case 'coordinators':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $rows = $helper->fetchAll(
                "SELECT id as user_id, full_name as name, email
                 FROM admin_users
                 WHERE role = 'QACoordinator' AND is_active = 1
                 ORDER BY full_name ASC",
                []
            );
            echo ApiResponse::success($rows);
            break;

        case 'departments':
            if ($method === 'GET') {
                if (!tableExists($helper, 'departments')) {
                    echo ApiResponse::success([]);
                    break;
                }
                $rows = $helper->fetchAll(
                    "SELECT d.id, d.name, d.is_active, d.qa_coordinator_id, d.created_at, d.updated_at,
                            au.full_name as qa_coordinator_name, au.email as qa_coordinator_email,
                            (SELECT COUNT(*) FROM admin_users u WHERE u.department = d.name) as total_users,
                            (SELECT COUNT(*) FROM admin_users u WHERE u.department = d.name AND u.is_active = 1) as active_users,
                            (SELECT COUNT(*) FROM staff s WHERE s.department = d.name) as total_staff,
                            (SELECT COUNT(*) FROM ideas i WHERE i.department = d.name) as total_ideas,
                            (SELECT COUNT(*) FROM comments c
                             JOIN ideas i2 ON i2.id = c.idea_id
                             WHERE i2.department = d.name AND c.is_deleted = 0) as total_comments
                     FROM departments d
                     LEFT JOIN admin_users au ON au.id = d.qa_coordinator_id
                     ORDER BY d.name ASC",
                    []
                );
                echo ApiResponse::success($rows);
                break;
            }

            $body = json_decode(file_get_contents('php://input'), true) ?: [];

            if ($method === 'POST') {
                $name = normalizeDepartmentName($body['name'] ?? '');
                $qa_coordinator_id = intval($body['qa_coordinator_id'] ?? 0);
                if ($name === '') {
                    echo ApiResponse::error('Department name is required', 400);
                    exit();
                }

                $exists = $helper->fetchOne("SELECT id FROM departments WHERE name = ?", [$name]);
                if ($exists) {
                    echo ApiResponse::error('Department already exists', 409);
                    exit();
                }

                $helper->execute(
                    "INSERT INTO departments (name, qa_coordinator_id, is_active, created_at)
                     VALUES (?, ?, 1, NOW())",
                    [$name, $qa_coordinator_id ?: null]
                );
                $new_id = intval($connection->lastInsertId());
                syncCoordinatorAssignment($helper, $name, $qa_coordinator_id ?: null);
                echo ApiResponse::success(['id' => $new_id], 'Department created successfully', 201);
                break;
            }

            if ($method === 'PUT') {
                $department_id = intval($body['department_id'] ?? 0);
                if ($department_id <= 0) {
                    echo ApiResponse::error('department_id is required', 400);
                    exit();
                }
                $existing = $helper->fetchOne("SELECT * FROM departments WHERE id = ?", [$department_id]);
                if (!$existing) {
                    echo ApiResponse::error('Department not found', 404);
                    exit();
                }

                $new_name = array_key_exists('name', $body) ? normalizeDepartmentName($body['name']) : $existing['name'];
                $is_active = array_key_exists('is_active', $body) ? intval($body['is_active']) : intval($existing['is_active']);
                $qa_coordinator_id = array_key_exists('qa_coordinator_id', $body)
                    ? intval($body['qa_coordinator_id'])
                    : intval($existing['qa_coordinator_id']);

                if ($new_name === '') {
                    echo ApiResponse::error('Department name cannot be empty', 400);
                    exit();
                }
                $dup = $helper->fetchOne("SELECT id FROM departments WHERE name = ? AND id != ?", [$new_name, $department_id]);
                if ($dup) {
                    echo ApiResponse::error('Department name already exists', 409);
                    exit();
                }

                $old_name = $existing['name'];
                $helper->execute(
                    "UPDATE departments
                     SET name = ?, qa_coordinator_id = ?, is_active = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$new_name, $qa_coordinator_id ?: null, $is_active, $department_id]
                );

                if ($old_name !== $new_name) {
                    $helper->execute("UPDATE admin_users SET department = ? WHERE department = ?", [$new_name, $old_name]);
                    $helper->execute("UPDATE staff SET department = ? WHERE department = ?", [$new_name, $old_name]);
                    $helper->execute("UPDATE contributors SET department = ? WHERE department = ?", [$new_name, $old_name]);
                    $helper->execute("UPDATE ideas SET department = ? WHERE department = ?", [$new_name, $old_name]);
                    $helper->execute("UPDATE qa_coordinator_departments SET department = ? WHERE department = ?", [$new_name, $old_name]);
                }

                syncCoordinatorAssignment($helper, $new_name, $qa_coordinator_id ?: null);
                echo ApiResponse::success(null, 'Department updated successfully');
                break;
            }

            if ($method === 'DELETE') {
                $department_id = intval($body['department_id'] ?? ($_GET['department_id'] ?? 0));
                if ($department_id <= 0) {
                    echo ApiResponse::error('department_id is required', 400);
                    exit();
                }
                $department = $helper->fetchOne("SELECT * FROM departments WHERE id = ?", [$department_id]);
                if (!$department) {
                    echo ApiResponse::error('Department not found', 404);
                    exit();
                }
                $name = $department['name'];

                $assigned_count = 0;
                $assigned_count += intval($helper->fetchOne("SELECT COUNT(*) as count FROM staff WHERE department = ?", [$name])['count'] ?? 0);
                $assigned_count += intval($helper->fetchOne("SELECT COUNT(*) as count FROM admin_users WHERE department = ?", [$name])['count'] ?? 0);
                if ($assigned_count > 0) {
                    echo ApiResponse::error('Cannot delete department with assigned staff/users', 409);
                    exit();
                }

                $helper->execute("DELETE FROM departments WHERE id = ?", [$department_id]);
                $helper->execute("DELETE FROM qa_coordinator_departments WHERE department = ?", [$name]);
                echo ApiResponse::success(null, 'Department deleted successfully');
                break;
            }

            echo ApiResponse::error('Method not allowed', 405);
            break;


        // ===== COMMENT MANAGEMENT =====
        case 'manage_comment':
            if ($method === 'PUT') {
                $input = json_decode(file_get_contents('php://input'), true);
                $comment_id = $input['comment_id'] ?? null;
                $comment_action = $input['comment_action'] ?? null; // 'flag', 'unflag', 'delete'
                
                if (!$comment_id || !$comment_action) {
                    echo ApiResponse::error('Comment ID and action required', 400);
                    exit();
                }
                
                if ($comment_action === 'flag') {
                    $reason = $input['reason'] ?? 'Inappropriate content';
                    $previous_state = $helper->fetchOne(
                        "SELECT is_inappropriate, inappropriate_reason, flagged_by_admin_id, flagged_at
                         FROM comments WHERE id = ?",
                        [$comment_id]
                    ) ?: [];
                    $helper->execute(
                        "INSERT INTO qa_content_action_audit (content_type, content_id, action, previous_state_json, admin_id)
                         VALUES ('Comment', ?, 'flag', ?, ?)",
                        [$comment_id, json_encode($previous_state), $current_user['admin_id']]
                    );
                    $helper->execute(
                        "UPDATE comments SET is_inappropriate = 1, inappropriate_reason = ?, flagged_by_admin_id = ?, flagged_at = NOW() WHERE id = ?",
                        [$reason, $current_user['admin_id'], $comment_id]
                    );
                    
                    $helper->execute(
                        "INSERT INTO inappropriate_content_log (admin_id, content_type, content_id, reason, action, notes) VALUES (?, ?, ?, ?, ?, ?)",
                        [$current_user['admin_id'], 'Comment', $comment_id, $reason, 'Flagged', $input['notes'] ?? '']
                    );
                } elseif ($comment_action === 'unflag') {
                    $helper->execute(
                        "UPDATE comments SET is_inappropriate = 0, inappropriate_reason = NULL, flagged_by_admin_id = NULL, flagged_at = NULL WHERE id = ?",
                        [$comment_id]
                    );
                } elseif ($comment_action === 'delete') {
                    $reason = $input['reason'] ?? 'Removed by QA Manager';
                    $previous_state = $helper->fetchOne(
                        "SELECT is_deleted, deleted_by, deleted_at, deleted_reason
                         FROM comments WHERE id = ?",
                        [$comment_id]
                    ) ?: [];
                    $helper->execute(
                        "INSERT INTO qa_content_action_audit (content_type, content_id, action, previous_state_json, admin_id)
                         VALUES ('Comment', ?, 'delete', ?, ?)",
                        [$comment_id, json_encode($previous_state), $current_user['admin_id']]
                    );
                    $helper->execute(
                        "UPDATE comments SET is_deleted = 1, deleted_by = ?, deleted_at = NOW(), deleted_reason = ? WHERE id = ?",
                        [$current_user['admin_id'], $reason, $comment_id]
                    );
                } elseif ($comment_action === 'undo_flag' || $comment_action === 'undo_hide') {
                    $undo_action = $comment_action === 'undo_flag' ? 'flag' : 'delete';
                    $audit = $helper->fetchOne(
                        "SELECT id, previous_state_json
                         FROM qa_content_action_audit
                         WHERE content_type = 'Comment' AND content_id = ? AND action = ? AND is_undone = 0
                         ORDER BY id DESC
                         LIMIT 1",
                        [$comment_id, $undo_action]
                    );
                    if (!$audit) {
                        echo ApiResponse::error('No undo history found for this comment', 404);
                        exit();
                    }
                    $prev = json_decode($audit['previous_state_json'] ?? '{}', true);
                    $helper->execute(
                        "UPDATE comments
                         SET is_inappropriate = ?, inappropriate_reason = ?, flagged_by_admin_id = ?, flagged_at = ?,
                             is_deleted = ?, deleted_by = ?, deleted_at = ?, deleted_reason = ?
                         WHERE id = ?",
                        [
                            intval($prev['is_inappropriate'] ?? 0),
                            $prev['inappropriate_reason'] ?? null,
                            $prev['flagged_by_admin_id'] ?? null,
                            $prev['flagged_at'] ?? null,
                            intval($prev['is_deleted'] ?? 0),
                            $prev['deleted_by'] ?? null,
                            $prev['deleted_at'] ?? null,
                            $prev['deleted_reason'] ?? null,
                            $comment_id
                        ]
                    );
                    $helper->execute(
                        "UPDATE qa_content_action_audit
                         SET is_undone = 1, undone_at = NOW()
                         WHERE id = ?",
                        [$audit['id']]
                    );
                }
                
                echo ApiResponse::success(null, 'Comment action completed');
            }
            break;
        
        // ===== USER ACCOUNT MANAGEMENT =====
        case 'manage_contributor':
            if ($method === 'PUT') {
                $input = json_decode(file_get_contents('php://input'), true);
                $contributor_id = $input['contributor_id'] ?? null;
                $contributor_action = $input['contributor_action'] ?? null; // 'disable', 'enable', 'block', 'unblock', 'hide_content', 'restore_hidden_content', 'disable_and_hide'
                
                if (!$contributor_id || !$contributor_action) {
                    echo ApiResponse::error('Contributor ID and action required', 400);
                    exit();
                }
                
                $reason = $input['reason'] ?? '';
                
                if ($contributor_action === 'disable') {
                    $helper->execute(
                        "UPDATE contributors SET account_status = 'Disabled', disabled_reason = ?, disabled_by_admin_id = ?, disabled_at = NOW() WHERE id = ?",
                        [$reason, $current_user['admin_id'], $contributor_id]
                    );
                    
                    $helper->execute(
                        "INSERT INTO contributor_account_history (contributor_id, admin_id, action, reason) VALUES (?, ?, ?, ?)",
                        [$contributor_id, $current_user['admin_id'], 'Disabled', $reason]
                    );
                } elseif ($contributor_action === 'enable') {
                    $helper->execute(
                        "UPDATE contributors SET account_status = 'Active', disabled_reason = NULL, disabled_by_admin_id = NULL, disabled_at = NULL, re_enabled_at = NOW() WHERE id = ?",
                        [$contributor_id]
                    );
                    
                    $helper->execute(
                        "INSERT INTO contributor_account_history (contributor_id, admin_id, action, reason) VALUES (?, ?, ?, ?)",
                        [$contributor_id, $current_user['admin_id'], 'Re-enabled', $reason]
                    );
                } elseif ($contributor_action === 'block') {
                    $helper->execute(
                        "UPDATE contributors SET account_status = 'Blocked', disabled_reason = ?, disabled_by_admin_id = ?, disabled_at = NOW() WHERE id = ?",
                        [$reason, $current_user['admin_id'], $contributor_id]
                    );
                    
                    $helper->execute(
                        "INSERT INTO contributor_account_history (contributor_id, admin_id, action, reason) VALUES (?, ?, ?, ?)",
                        [$contributor_id, $current_user['admin_id'], 'Blocked', $reason]
                    );
                } elseif ($contributor_action === 'unblock') {
                    $helper->execute(
                        "UPDATE contributors SET account_status = 'Active', disabled_reason = NULL, disabled_by_admin_id = NULL, disabled_at = NULL WHERE id = ?",
                        [$contributor_id]
                    );
                    
                    $helper->execute(
                        "INSERT INTO contributor_account_history (contributor_id, admin_id, action, reason) VALUES (?, ?, ?, ?)",
                        [$contributor_id, $current_user['admin_id'], 'Unblocked', $reason]
                    );
                } elseif ($contributor_action === 'hide_content') {
                    $hidden_stats = hideContributorContent($helper, $connection, intval($contributor_id), intval($current_user['admin_id']));
                    $helper->execute(
                        "INSERT INTO contributor_account_history (contributor_id, admin_id, action, reason) VALUES (?, ?, ?, ?)",
                        [
                            $contributor_id,
                            $current_user['admin_id'],
                            'Content Hidden',
                            $reason ?: 'All contributor content hidden by QA Manager'
                        ]
                    );
                    echo ApiResponse::success($hidden_stats, 'Contributor content hidden successfully');
                    exit();
                } elseif ($contributor_action === 'disable_and_hide') {
                    $hidden_stats = hideContributorContent($helper, $connection, intval($contributor_id), intval($current_user['admin_id']));
                    $helper->execute(
                        "UPDATE contributors SET account_status = 'Disabled', disabled_reason = ?, disabled_by_admin_id = ?, disabled_at = NOW() WHERE id = ?",
                        [$reason, $current_user['admin_id'], $contributor_id]
                    );
                    $helper->execute(
                        "INSERT INTO contributor_account_history (contributor_id, admin_id, action, reason) VALUES (?, ?, ?, ?)",
                        [
                            $contributor_id,
                            $current_user['admin_id'],
                            'Disabled + Content Hidden',
                            $reason ?: 'Account disabled and all content hidden by QA Manager'
                        ]
                    );
                    echo ApiResponse::success($hidden_stats, 'Contributor disabled and content hidden successfully');
                    exit();
                } elseif ($contributor_action === 'restore_hidden_content') {
                    $restored_stats = restoreContributorHiddenContent($helper, $connection, intval($contributor_id), intval($current_user['admin_id']));
                    $helper->execute(
                        "INSERT INTO contributor_account_history (contributor_id, admin_id, action, reason) VALUES (?, ?, ?, ?)",
                        [
                            $contributor_id,
                            $current_user['admin_id'],
                            'Content Restored',
                            $reason ?: 'Hidden contributor content restored by QA Manager'
                        ]
                    );
                    echo ApiResponse::success($restored_stats, 'Hidden contributor content restored successfully');
                    exit();
                }
                
                echo ApiResponse::success(null, 'Account action completed');
            } elseif ($method === 'GET') {
                // Get all contributors
                $contributors = $helper->fetchAll(
                    "SELECT c.id, c.name, c.email, c.department, c.account_status, c.disabled_reason, c.disabled_at, c.created_at,
                            (
                                SELECT COUNT(*)
                                FROM qa_hidden_content_records qh
                                WHERE qh.contributor_id = c.id AND qh.is_restored = 0
                            ) as hidden_content_count
                     FROM contributors c
                     ORDER BY c.created_at DESC"
                );
                
                echo ApiResponse::success($contributors);
            }
            break;
        
        // ===== GET INAPPROPRIATE CONTENT LIST =====
        case 'inappropriate_content':
            if ($method === 'GET') {
                $page = intval($_GET['page'] ?? 1);
                $per_page = intval($_GET['per_page'] ?? 10);
                $offset = ($page - 1) * $per_page;
                $type = $_GET['type'] ?? null; // 'all', 'ideas', 'comments'
                
                $where = "is_inappropriate = 1";
                $params = [];
                
                if ($type === 'ideas') {
                    $where = "i.is_inappropriate = 1";
                } elseif ($type === 'comments') {
                    $where = "c.is_inappropriate = 1";
                }
                
                // Get inappropriate ideas and comments
                $results = [];
                
                // Ideas
                $ideas = $helper->fetchAll(
                    "SELECT id, 'Idea' as type, title, inappropriate_reason, flagged_at, NULL as idea_title 
                     FROM ideas 
                     WHERE is_inappropriate = 1
                     ORDER BY flagged_at DESC
                     LIMIT 100"
                );
                
                // Comments
                $comments = $helper->fetchAll(
                    "SELECT c.id, 'Comment' as type, c.content as title, c.inappropriate_reason, c.flagged_at, i.title as idea_title
                     FROM comments c
                     JOIN ideas i ON c.idea_id = i.id
                     WHERE c.is_inappropriate = 1
                     ORDER BY c.flagged_at DESC
                     LIMIT 100"
                );
                
                $results = array_merge($ideas, $comments);
                
                echo ApiResponse::success($results);
            }
            break;
        
        // ===== ANONYMOUS CONTENT REPORT =====
        case 'anonymous_content':
            if ($method === 'GET') {
                // Get anonymous ideas and comments
                $anonymous_ideas = $helper->fetchAll(
                    "SELECT i.id, 'Idea' as type, i.title, i.department, i.created_at,
                            COUNT(c.id) as comment_count
                     FROM ideas i
                     LEFT JOIN comments c ON i.id = c.idea_id
                     JOIN contributors con ON i.contributor_id = con.id
                     WHERE con.is_anonymous = 1
                     GROUP BY i.id
                     ORDER BY i.created_at DESC"
                );
                
                $anonymous_comments = $helper->fetchAll(
                    "SELECT c.id, 'Comment' as type, c.content as title, i.title as idea_title, c.created_at,
                            con.name as contributor_name
                     FROM comments c
                     JOIN contributors con ON c.contributor_id = con.id
                     JOIN ideas i ON c.idea_id = i.id
                     WHERE con.is_anonymous = 1
                     ORDER BY c.created_at DESC"
                );
                
                echo ApiResponse::success([
                    'ideas' => $anonymous_ideas,
                    'comments' => $anonymous_comments,
                    'anonymous_ideas' => $anonymous_ideas,
                    'anonymous_comments' => $anonymous_comments
                ]);
            }
            break;
        
        // ===== COMMENTS WITHOUT RESPONSES =====
        case 'unresponded_ideas':
            if ($method === 'GET') {
                $ideas = $helper->fetchAll(
                    "SELECT i.id, i.title, i.department, i.created_at, i.comment_count,
                            c.name as contributor_name, cat.name as category_name
                     FROM ideas i
                     JOIN contributors c ON i.contributor_id = c.id
                     JOIN sessions s ON i.session_id = s.id
                     JOIN idea_categories cat ON s.category_id = cat.id
                     WHERE i.comment_count = 0
                     ORDER BY i.created_at DESC"
                );
                
                echo ApiResponse::success($ideas);
            }
            break;
        
        default:
            echo ApiResponse::error('Unknown action', 400);
    }
} catch (Exception $e) {
    echo ApiResponse::error('Error: ' . $e->getMessage(), 500);
}

?>
