<?php
// api/admin_management.php
// Centralized Admin operations: users, departments, config, monitoring, moderation, backups, reports, documents.

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
if (!$current_user || ($current_user['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized - Administrator access required']));
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

function parseJsonInput() {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function tableExists($helper, $table_name) {
    $row = $helper->fetchOne(
        "SELECT COUNT(*) as count
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table_name]
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

function setSystemSetting($helper, $key, $value, $value_type = 'string', $description = null) {
    $serialized_value = is_array($value) ? json_encode($value) : strval($value);
    $helper->execute(
        "INSERT INTO system_settings (setting_key, setting_value, value_type, description, updated_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            value_type = VALUES(value_type),
            description = COALESCE(VALUES(description), description),
            updated_at = NOW()",
        [$key, $serialized_value, $value_type, $description]
    );
}

function getSystemSetting($helper, $key, $default = null) {
    if (!tableExists($helper, 'system_settings')) {
        return $default;
    }
    $row = $helper->fetchOne(
        "SELECT setting_value, value_type FROM system_settings WHERE setting_key = ? LIMIT 1",
        [$key]
    );
    if (!$row) {
        return $default;
    }

    $value = $row['setting_value'];
    $type = $row['value_type'] ?? 'string';
    if ($type === 'int') {
        return intval($value);
    }
    if ($type === 'bool') {
        return in_array(strtolower(strval($value)), ['1', 'true', 'yes', 'on'], true);
    }
    if ($type === 'json') {
        $decoded = json_decode($value, true);
        return $decoded !== null ? $decoded : $default;
    }
    return $value;
}

function normalizeDepartmentName($name) {
    return trim(preg_replace('/\s+/', ' ', strval($name)));
}

function generateRandomPassword($length = 12) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

function logAudit($helper, $admin_id, $action, $table_name = null, $record_id = null, $changes = null) {
    try {
        $helper->execute(
            "INSERT INTO audit_logs (admin_id, action, table_name, record_id, changes, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [
                $admin_id,
                $action,
                $table_name,
                $record_id,
                $changes ? json_encode($changes) : null,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]
        );
    } catch (Exception $ignored) {
        // Keep main flow resilient if audit logging fails.
    }
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

function syncRoleTables($helper, $connection, $user) {
    $role = $user['role'];
    $user_id = intval($user['id']);
    $email = trim($user['email']);
    $name = trim($user['full_name']);
    $department = normalizeDepartmentName($user['department'] ?? '');
    $is_active = intval($user['is_active'] ?? 1);

    if ($role === 'Staff') {
        $staff = $helper->fetchOne("SELECT id FROM staff WHERE email = ? LIMIT 1", [$email]);
        if ($staff) {
            $helper->execute(
                "UPDATE staff SET name = ?, department = ?, role = 'Staff', is_active = ?, updated_at = NOW() WHERE id = ?",
                [$name, $department, $is_active, $staff['id']]
            );
        } else {
            $helper->execute(
                "INSERT INTO staff (name, email, role, department, is_active, created_at)
                 VALUES (?, ?, 'Staff', ?, ?, NOW())",
                [$name, $email, $department, $is_active]
            );
        }
    }

    if ($role === 'QAManager') {
        $existing_manager = $helper->fetchOne("SELECT id FROM qa_managers WHERE admin_user_id = ? LIMIT 1", [$user_id]);
        if ($existing_manager) {
            $helper->execute(
                "UPDATE qa_managers SET department = ?, is_active = ?, updated_at = NOW() WHERE admin_user_id = ?",
                [$department, $is_active, $user_id]
            );
        } else {
            $helper->execute(
                "INSERT INTO qa_managers (admin_user_id, department, is_active, created_at)
                 VALUES (?, ?, ?, NOW())",
                [$user_id, $department, $is_active]
            );
        }
    } else {
        $helper->execute(
            "UPDATE qa_managers SET is_active = 0, updated_at = NOW() WHERE admin_user_id = ?",
            [$user_id]
        );
    }

    if ($role === 'QACoordinator') {
        syncCoordinatorAssignment($helper, $department, $user_id);
    } else {
        $helper->execute(
            "UPDATE qa_coordinator_departments SET is_active = 0 WHERE coordinator_id = ?",
            [$user_id]
        );
    }
}

function hydrateDepartmentTable($helper) {
    $sources = [
        "SELECT DISTINCT department as department_name FROM admin_users WHERE department IS NOT NULL AND TRIM(department) != ''",
        "SELECT DISTINCT department as department_name FROM staff WHERE department IS NOT NULL AND TRIM(department) != ''",
        "SELECT DISTINCT department as department_name FROM contributors WHERE department IS NOT NULL AND TRIM(department) != ''",
        "SELECT DISTINCT department as department_name FROM ideas WHERE department IS NOT NULL AND TRIM(department) != ''",
    ];

    foreach ($sources as $sql) {
        $rows = $helper->fetchAll($sql, []);
        foreach ($rows as $row) {
            $name = normalizeDepartmentName($row['department_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $helper->execute(
                "INSERT IGNORE INTO departments (name, is_active, created_at) VALUES (?, 1, NOW())",
                [$name]
            );
        }
    }
}

function ensureAdminSchema($helper) {
    $helper->execute(
        "CREATE TABLE IF NOT EXISTS departments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL UNIQUE,
            qa_coordinator_id INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_department_active (is_active),
            INDEX idx_department_coordinator (qa_coordinator_id),
            CONSTRAINT fk_departments_coordinator FOREIGN KEY (qa_coordinator_id) REFERENCES admin_users(id) ON DELETE SET NULL
        )",
        []
    );

    $helper->execute(
        "CREATE TABLE IF NOT EXISTS system_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(120) NOT NULL UNIQUE,
            setting_value LONGTEXT NOT NULL,
            value_type ENUM('string','int','bool','json') NOT NULL DEFAULT 'string',
            description VARCHAR(255) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        []
    );

    $helper->execute(
        "CREATE TABLE IF NOT EXISTS notification_templates (
            id INT PRIMARY KEY AUTO_INCREMENT,
            template_key VARCHAR(120) NOT NULL UNIQUE,
            subject VARCHAR(255) NOT NULL,
            body LONGTEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        []
    );

    $helper->execute(
        "CREATE TABLE IF NOT EXISTS notification_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            notification_key VARCHAR(120) NOT NULL UNIQUE,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        []
    );

    $helper->execute(
        "CREATE TABLE IF NOT EXISTS category_backups (
            id INT PRIMARY KEY AUTO_INCREMENT,
            backup_name VARCHAR(255) NOT NULL,
            category_snapshot LONGTEXT NOT NULL,
            backup_note VARCHAR(255) NULL,
            created_by_admin_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_category_backups_admin (created_by_admin_id),
            CONSTRAINT fk_category_backups_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
        )",
        []
    );

    $helper->execute(
        "CREATE TABLE IF NOT EXISTS system_backups (
            id INT PRIMARY KEY AUTO_INCREMENT,
            backup_name VARCHAR(255) NOT NULL,
            backup_scope VARCHAR(255) NOT NULL,
            backup_payload LONGTEXT NOT NULL,
            created_by_admin_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_system_backups_admin (created_by_admin_id),
            CONSTRAINT fk_system_backups_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
        )",
        []
    );

    $helper->execute(
        "CREATE TABLE IF NOT EXISTS role_permissions (
            role_key VARCHAR(60) PRIMARY KEY,
            permissions_json LONGTEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        []
    );

    hydrateDepartmentTable($helper);

    $default_settings = [
        ['idea_submissions_enabled', '1', 'bool', 'Global switch for idea submissions'],
        ['commenting_enabled', '1', 'bool', 'Global switch for comments and replies'],
        ['default_ideas_per_page', '5', 'int', 'Default ideas pagination size'],
        ['max_upload_size_mb', '10', 'int', 'Maximum upload size in MB'],
        ['allowed_file_types', json_encode(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif']), 'json', 'Allowed attachment file extensions'],
        ['session_timeout_minutes', '1440', 'int', 'Session timeout in minutes'],
        ['password_policy', json_encode(['min_length' => 8, 'require_uppercase' => true, 'require_number' => true, 'require_symbol' => false]), 'json', 'Password policy settings'],
        ['smtp_settings', json_encode(['host' => '', 'port' => 587, 'username' => '', 'password' => '', 'encryption' => 'tls', 'from_email' => '', 'from_name' => 'Ideas System']), 'json', 'SMTP server settings'],
    ];
    foreach ($default_settings as $setting) {
        $helper->execute(
            "INSERT IGNORE INTO system_settings (setting_key, setting_value, value_type, description) VALUES (?, ?, ?, ?)",
            $setting
        );
    }

    $default_templates = [
        ['idea_submitted', 'New Idea Submitted', 'A new idea has been submitted to your department.'],
        ['comment_added', 'New Comment Received', 'A new comment has been added to your idea.'],
        ['inappropriate_reported', 'Content Reported', 'A content item has been reported for review.'],
    ];
    foreach ($default_templates as $tpl) {
        $helper->execute(
            "INSERT IGNORE INTO notification_templates (template_key, subject, body, is_active) VALUES (?, ?, ?, 1)",
            $tpl
        );
    }

    $default_notification_settings = [
        'idea_submitted',
        'comment_added',
        'reply_added',
        'inappropriate_reported',
        'session_closing',
    ];
    foreach ($default_notification_settings as $notif_key) {
        $helper->execute(
            "INSERT IGNORE INTO notification_settings (notification_key, is_enabled) VALUES (?, 1)",
            [$notif_key]
        );
    }

    $default_permissions = [
        'Admin' => [
            'manage_users' => true,
            'manage_departments' => true,
            'manage_config' => true,
            'manage_backups' => true,
            'view_audit_logs' => true,
            'moderate_content' => true,
        ],
        'QAManager' => [
            'manage_categories' => true,
            'manage_ideas' => true,
            'view_reports' => true,
            'moderate_content' => true,
            'manage_users' => false,
        ],
        'QACoordinator' => [
            'manage_department_engagement' => true,
            'view_department_reports' => true,
            'moderate_content' => true,
            'manage_system_config' => false,
        ],
        'Staff' => [
            'submit_ideas' => true,
            'comment' => true,
            'vote' => true,
            'manage_users' => false,
        ]
    ];
    foreach ($default_permissions as $role_key => $permissions) {
        $helper->execute(
            "INSERT IGNORE INTO role_permissions (role_key, permissions_json) VALUES (?, ?)",
            [$role_key, json_encode($permissions)]
        );
    }
}

function parseScopes($scope_raw) {
    if (!$scope_raw) {
        return ['users', 'departments', 'settings', 'categories', 'notifications', 'security'];
    }
    if (is_array($scope_raw)) {
        return $scope_raw;
    }
    $items = explode(',', strval($scope_raw));
    $clean = [];
    foreach ($items as $item) {
        $normalized = trim($item);
        if ($normalized !== '') {
            $clean[] = $normalized;
        }
    }
    return $clean;
}

function buildBackupPayload($helper, $scope_items) {
    $payload = [];
    $scope_to_table = [
        'users' => ['admin_users', 'staff', 'qa_managers', 'qa_coordinator_departments'],
        'departments' => ['departments'],
        'settings' => ['system_settings'],
        'categories' => ['idea_categories'],
        'notifications' => ['notification_templates', 'notification_settings'],
        'security' => ['role_permissions'],
    ];

    $tables = [];
    foreach ($scope_items as $scope_key) {
        if (!isset($scope_to_table[$scope_key])) {
            continue;
        }
        foreach ($scope_to_table[$scope_key] as $table_name) {
            $tables[$table_name] = true;
        }
    }

    foreach (array_keys($tables) as $table_name) {
        if (!tableExists($helper, $table_name)) {
            continue;
        }
        $payload[$table_name] = $helper->fetchAll("SELECT * FROM $table_name", []);
    }
    return $payload;
}

function restoreTableData($helper, $connection, $table_name, $rows) {
    if (!is_array($rows)) {
        return;
    }

    $connection->exec("DELETE FROM $table_name");
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row)) {
            continue;
        }
        $columns = array_keys($row);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO $table_name (" . implode(', ', $columns) . ") VALUES ($placeholders)";
        $values = [];
        foreach ($columns as $column) {
            $values[] = $row[$column];
        }
        $helper->execute($sql, $values);
    }
}

try {
    ensureAdminSchema($helper);
    $body = parseJsonInput();

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

        case 'users':
            if ($method === 'GET') {
                $search = trim($_GET['search'] ?? '');
                $status = $_GET['status'] ?? null;
                $role = $_GET['role'] ?? null;
                $page = max(1, intval($_GET['page'] ?? 1));
                $per_page = max(1, min(200, intval($_GET['per_page'] ?? 20)));
                $offset = ($page - 1) * $per_page;

                $where = [];
                $params = [];
                if ($search !== '') {
                    $where[] = "(au.full_name LIKE ? OR au.email LIKE ? OR au.username LIKE ? OR au.department LIKE ?)";
                    $like = '%' . $search . '%';
                    array_push($params, $like, $like, $like, $like);
                }
                if ($status === 'Active') {
                    $where[] = "au.is_active = 1";
                } elseif ($status === 'Inactive') {
                    $where[] = "au.is_active = 0";
                }
                if ($role && in_array($role, ['Admin', 'QAManager', 'QACoordinator', 'Staff'], true)) {
                    $where[] = "au.role = ?";
                    $params[] = $role;
                }
                $where_sql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

                $total_row = $helper->fetchOne(
                    "SELECT COUNT(*) as count
                     FROM admin_users au
                     $where_sql",
                    $params
                );
                $total = intval($total_row['count'] ?? 0);

                $rows = $helper->fetchAll(
                    "SELECT au.id as staff_id, au.id as user_id, au.full_name as name, au.email, au.username,
                            au.department, au.role, au.is_active, au.last_login, au.created_at,
                            st.phone as phone_number
                     FROM admin_users au
                     LEFT JOIN staff st ON st.email = au.email
                     $where_sql
                     ORDER BY au.created_at DESC
                     LIMIT ? OFFSET ?",
                    array_merge($params, [$per_page, $offset])
                );

                foreach ($rows as &$row) {
                    $row['account_status'] = intval($row['is_active']) === 1 ? 'Active' : 'Inactive';
                }

                echo ApiResponse::paginated($rows, $total, $page, $per_page);
                break;
            }

            if ($method === 'POST') {
                $required = ['full_name', 'email', 'role'];
                foreach ($required as $required_field) {
                    if (empty($body[$required_field])) {
                        echo ApiResponse::error("$required_field is required", 400);
                        exit();
                    }
                }

                $full_name = trim($body['full_name']);
                $email = trim($body['email']);
                $role = trim($body['role']);
                $department = normalizeDepartmentName($body['department'] ?? '');
                $username = trim($body['username'] ?? '');
                $is_active = isset($body['is_active']) ? intval($body['is_active']) : 1;

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo ApiResponse::error('Invalid email format', 400);
                    exit();
                }
                if (!in_array($role, ['Admin', 'QAManager', 'QACoordinator', 'Staff'], true)) {
                    echo ApiResponse::error('Invalid role', 400);
                    exit();
                }

                if ($username === '') {
                    $username_base = preg_replace('/[^a-z0-9_]/', '', strtolower(explode('@', $email)[0]));
                    $username = $username_base !== '' ? $username_base : 'user';
                    $counter = 1;
                    while ($helper->fetchOne("SELECT id FROM admin_users WHERE username = ?", [$username])) {
                        $counter++;
                        $username = $username_base . $counter;
                    }
                }

                if ($helper->fetchOne("SELECT id FROM admin_users WHERE email = ?", [$email])) {
                    echo ApiResponse::error('Email already exists', 409);
                    exit();
                }
                if ($helper->fetchOne("SELECT id FROM admin_users WHERE username = ?", [$username])) {
                    echo ApiResponse::error('Username already exists', 409);
                    exit();
                }

                $password = trim($body['password'] ?? '');
                if ($password === '') {
                    $password = generateRandomPassword();
                }
                $password_hash = $auth->hashPassword($password);

                $helper->execute(
                    "INSERT INTO admin_users (username, email, password_hash, full_name, role, department, is_active, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                    [$username, $email, $password_hash, $full_name, $role, $department, $is_active]
                );
                $new_id = intval($connection->lastInsertId());

                $new_user = $helper->fetchOne("SELECT * FROM admin_users WHERE id = ?", [$new_id]);
                syncRoleTables($helper, $connection, $new_user);

                if ($department !== '') {
                    $helper->execute(
                        "INSERT IGNORE INTO departments (name, is_active, created_at) VALUES (?, 1, NOW())",
                        [$department]
                    );
                }

                logAudit($helper, $current_user['admin_id'], 'ADMIN_CREATE_USER', 'admin_users', $new_id, [
                    'email' => $email,
                    'role' => $role,
                    'department' => $department
                ]);

                echo ApiResponse::success([
                    'user_id' => $new_id,
                    'generated_password' => $password
                ], 'User account created successfully', 201);
                break;
            }

            if ($method === 'PUT') {
                $user_id = intval($body['user_id'] ?? 0);
                if ($user_id <= 0) {
                    echo ApiResponse::error('user_id is required', 400);
                    exit();
                }

                $existing = $helper->fetchOne("SELECT * FROM admin_users WHERE id = ?", [$user_id]);
                if (!$existing) {
                    echo ApiResponse::error('User not found', 404);
                    exit();
                }

                $updates = [];
                $params = [];

                $allowed_fields = ['full_name', 'email', 'username', 'department', 'role', 'is_active'];
                foreach ($allowed_fields as $field) {
                    if (!array_key_exists($field, $body)) {
                        continue;
                    }
                    $value = $body[$field];
                    if ($field === 'email') {
                        $value = trim($value);
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            echo ApiResponse::error('Invalid email format', 400);
                            exit();
                        }
                        $dup = $helper->fetchOne("SELECT id FROM admin_users WHERE email = ? AND id != ?", [$value, $user_id]);
                        if ($dup) {
                            echo ApiResponse::error('Email already exists', 409);
                            exit();
                        }
                    }
                    if ($field === 'username') {
                        $value = trim($value);
                        if ($value === '') {
                            echo ApiResponse::error('Username cannot be empty', 400);
                            exit();
                        }
                        $dup = $helper->fetchOne("SELECT id FROM admin_users WHERE username = ? AND id != ?", [$value, $user_id]);
                        if ($dup) {
                            echo ApiResponse::error('Username already exists', 409);
                            exit();
                        }
                    }
                    if ($field === 'role' && !in_array($value, ['Admin', 'QAManager', 'QACoordinator', 'Staff'], true)) {
                        echo ApiResponse::error('Invalid role', 400);
                        exit();
                    }
                    if ($field === 'department') {
                        $value = normalizeDepartmentName($value);
                    }
                    $updates[] = "$field = ?";
                    $params[] = $value;
                }

                if (empty($updates)) {
                    echo ApiResponse::error('No updates provided', 400);
                    exit();
                }

                $params[] = $user_id;
                $helper->execute(
                    "UPDATE admin_users SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?",
                    $params
                );

                $updated = $helper->fetchOne("SELECT * FROM admin_users WHERE id = ?", [$user_id]);
                syncRoleTables($helper, $connection, $updated);
                if (!empty($updated['department'])) {
                    $helper->execute(
                        "INSERT IGNORE INTO departments (name, is_active, created_at) VALUES (?, 1, NOW())",
                        [normalizeDepartmentName($updated['department'])]
                    );
                }

                logAudit($helper, $current_user['admin_id'], 'ADMIN_UPDATE_USER', 'admin_users', $user_id, $body);
                echo ApiResponse::success($updated, 'User updated successfully');
                break;
            }

            if ($method === 'DELETE') {
                $user_id = intval($body['user_id'] ?? ($_GET['user_id'] ?? 0));
                $mode = strtolower(trim($body['mode'] ?? ($_GET['mode'] ?? 'deactivate')));
                if ($user_id <= 0) {
                    echo ApiResponse::error('user_id is required', 400);
                    exit();
                }

                $existing = $helper->fetchOne("SELECT id, role, email FROM admin_users WHERE id = ?", [$user_id]);
                if (!$existing) {
                    echo ApiResponse::error('User not found', 404);
                    exit();
                }
                if (intval($current_user['admin_id']) === $user_id) {
                    echo ApiResponse::error('You cannot delete your own account', 400);
                    exit();
                }

                if ($mode === 'delete') {
                    $helper->execute("DELETE FROM admin_users WHERE id = ?", [$user_id]);
                    $helper->execute("UPDATE staff SET is_active = 0 WHERE email = ?", [$existing['email']]);
                    logAudit($helper, $current_user['admin_id'], 'ADMIN_DELETE_USER', 'admin_users', $user_id, ['mode' => 'delete']);
                    echo ApiResponse::success(null, 'User deleted successfully');
                } else {
                    $helper->execute("UPDATE admin_users SET is_active = 0, updated_at = NOW() WHERE id = ?", [$user_id]);
                    $helper->execute("UPDATE staff SET is_active = 0 WHERE email = ?", [$existing['email']]);
                    logAudit($helper, $current_user['admin_id'], 'ADMIN_DEACTIVATE_USER', 'admin_users', $user_id, ['mode' => 'deactivate']);
                    echo ApiResponse::success(null, 'User deactivated successfully');
                }
                break;
            }

            echo ApiResponse::error('Method not allowed', 405);
            break;

        case 'reset_password':
            if ($method !== 'POST') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $user_id = intval($body['user_id'] ?? 0);
            if ($user_id <= 0) {
                echo ApiResponse::error('user_id is required', 400);
                exit();
            }
            $existing = $helper->fetchOne("SELECT id FROM admin_users WHERE id = ?", [$user_id]);
            if (!$existing) {
                echo ApiResponse::error('User not found', 404);
                exit();
            }

            $new_password = trim($body['new_password'] ?? '');
            if ($new_password === '') {
                $new_password = generateRandomPassword();
            }
            $password_hash = $auth->hashPassword($new_password);
            $helper->execute(
                "UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ?",
                [$password_hash, $user_id]
            );

            logAudit($helper, $current_user['admin_id'], 'ADMIN_RESET_PASSWORD', 'admin_users', $user_id, null);
            echo ApiResponse::success(['generated_password' => $new_password], 'Password reset successfully');
            break;

        case 'departments':
            if ($method === 'GET') {
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
                logAudit($helper, $current_user['admin_id'], 'ADMIN_CREATE_DEPARTMENT', 'departments', $new_id, ['name' => $name]);

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
                logAudit($helper, $current_user['admin_id'], 'ADMIN_UPDATE_DEPARTMENT', 'departments', $department_id, $body);
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
                logAudit($helper, $current_user['admin_id'], 'ADMIN_DELETE_DEPARTMENT', 'departments', $department_id, ['name' => $name]);
                echo ApiResponse::success(null, 'Department deleted successfully');
                break;
            }

            echo ApiResponse::error('Method not allowed', 405);
            break;

        case 'terms':
            if ($method === 'GET') {
                $active = $helper->fetchOne(
                    "SELECT id, version, content, is_active, created_at
                     FROM terms_and_conditions
                     WHERE is_active = 1
                     ORDER BY version DESC
                     LIMIT 1",
                    []
                );
                if (!$active) {
                    $active = ['id' => null, 'version' => 0, 'content' => ''];
                }
                echo ApiResponse::success($active);
                break;
            }

            if ($method === 'PUT' || $method === 'POST') {
                $content = trim($body['content'] ?? '');
                if ($content === '') {
                    echo ApiResponse::error('Terms content is required', 400);
                    exit();
                }

                $max_version = $helper->fetchOne("SELECT COALESCE(MAX(version), 0) as max_version FROM terms_and_conditions", []);
                $next_version = intval($max_version['max_version'] ?? 0) + 1;

                $helper->execute("UPDATE terms_and_conditions SET is_active = 0 WHERE is_active = 1", []);
                $helper->execute(
                    "INSERT INTO terms_and_conditions (version, content, is_active, created_at)
                     VALUES (?, ?, 1, NOW())",
                    [$next_version, $content]
                );

                logAudit($helper, $current_user['admin_id'], 'ADMIN_UPDATE_TERMS', 'terms_and_conditions', null, ['version' => $next_version]);
                echo ApiResponse::success(['version' => $next_version], 'Terms updated successfully');
                break;
            }

            echo ApiResponse::error('Method not allowed', 405);
            break;

        case 'system_settings':
            if ($method === 'GET') {
                $rows = $helper->fetchAll(
                    "SELECT setting_key, setting_value, value_type, description, updated_at
                     FROM system_settings
                     ORDER BY setting_key ASC",
                    []
                );
                $settings = [];
                foreach ($rows as $row) {
                    $settings[$row['setting_key']] = getSystemSetting($helper, $row['setting_key'], null);
                }
                echo ApiResponse::success($settings);
                break;
            }

            if ($method === 'PUT') {
                $settings = $body['settings'] ?? null;
                if (!is_array($settings) || empty($settings)) {
                    echo ApiResponse::error('settings object is required', 400);
                    exit();
                }

                foreach ($settings as $key => $value) {
                    $type = 'string';
                    if (is_bool($value)) {
                        $type = 'bool';
                    } elseif (is_int($value)) {
                        $type = 'int';
                    } elseif (is_array($value)) {
                        $type = 'json';
                    }
                    setSystemSetting($helper, $key, $value, $type);
                }

                logAudit($helper, $current_user['admin_id'], 'ADMIN_UPDATE_SYSTEM_SETTINGS', 'system_settings', null, array_keys($settings));
                echo ApiResponse::success(null, 'System settings updated successfully');
                break;
            }

            echo ApiResponse::error('Method not allowed', 405);
            break;

        case 'notification_templates':
            if ($method === 'GET') {
                $rows = $helper->fetchAll(
                    "SELECT id, template_key, subject, body, is_active, updated_at
                     FROM notification_templates
                     ORDER BY template_key ASC",
                    []
                );
                echo ApiResponse::success($rows);
                break;
            }

            if ($method === 'POST') {
                $template_key = trim($body['template_key'] ?? '');
                $subject = trim($body['subject'] ?? '');
                $body_text = trim($body['body'] ?? '');
                $is_active = isset($body['is_active']) ? intval($body['is_active']) : 1;
                if ($template_key === '' || $subject === '' || $body_text === '') {
                    echo ApiResponse::error('template_key, subject, and body are required', 400);
                    exit();
                }

                $helper->execute(
                    "INSERT INTO notification_templates (template_key, subject, body, is_active)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), is_active = VALUES(is_active)",
                    [$template_key, $subject, $body_text, $is_active]
                );

                logAudit($helper, $current_user['admin_id'], 'ADMIN_UPSERT_TEMPLATE', 'notification_templates', null, ['template_key' => $template_key]);
                echo ApiResponse::success(null, 'Template saved successfully');
                break;
            }

            if ($method === 'PUT') {
                $template_id = intval($body['template_id'] ?? 0);
                if ($template_id <= 0) {
                    echo ApiResponse::error('template_id is required', 400);
                    exit();
                }

                $updates = [];
                $params = [];
                foreach (['subject', 'body', 'is_active'] as $field) {
                    if (!array_key_exists($field, $body)) {
                        continue;
                    }
                    $updates[] = "$field = ?";
                    $params[] = $body[$field];
                }
                if (empty($updates)) {
                    echo ApiResponse::error('No updates provided', 400);
                    exit();
                }
                $params[] = $template_id;
                $helper->execute(
                    "UPDATE notification_templates SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?",
                    $params
                );

                logAudit($helper, $current_user['admin_id'], 'ADMIN_UPDATE_TEMPLATE', 'notification_templates', $template_id, $body);
                echo ApiResponse::success(null, 'Template updated successfully');
                break;
            }

            echo ApiResponse::error('Method not allowed', 405);
            break;

        case 'notification_settings':
            if ($method === 'GET') {
                $rows = $helper->fetchAll(
                    "SELECT id, notification_key, is_enabled, updated_at
                     FROM notification_settings
                     ORDER BY notification_key ASC",
                    []
                );
                echo ApiResponse::success($rows);
                break;
            }

            if ($method === 'PUT') {
                $notification_key = trim($body['notification_key'] ?? '');
                $is_enabled = isset($body['is_enabled']) ? intval($body['is_enabled']) : null;
                if ($notification_key === '' || $is_enabled === null) {
                    echo ApiResponse::error('notification_key and is_enabled are required', 400);
                    exit();
                }

                $helper->execute(
                    "INSERT INTO notification_settings (notification_key, is_enabled)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_at = NOW()",
                    [$notification_key, $is_enabled]
                );

                logAudit($helper, $current_user['admin_id'], 'ADMIN_UPDATE_NOTIFICATION_SETTING', 'notification_settings', null, [
                    'notification_key' => $notification_key,
                    'is_enabled' => $is_enabled,
                ]);
                echo ApiResponse::success(null, 'Notification setting updated');
                break;
            }

            echo ApiResponse::error('Method not allowed', 405);
            break;

        case 'test_email':
            if ($method !== 'POST') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $recipient_email = trim($body['recipient_email'] ?? '');
            $template_key = trim($body['template_key'] ?? 'idea_submitted');
            if (!filter_var($recipient_email, FILTER_VALIDATE_EMAIL)) {
                echo ApiResponse::error('Valid recipient_email is required', 400);
                exit();
            }

            $template = $helper->fetchOne(
                "SELECT subject, body FROM notification_templates WHERE template_key = ? LIMIT 1",
                [$template_key]
            );
            if (!$template) {
                $template = [
                    'subject' => 'Test Notification',
                    'body' => 'This is a test notification from the admin system.',
                ];
            }

            $delivery = sendSystemEmail($connection, [
                'recipient_email' => $recipient_email,
                'recipient_type' => 'Staff',
                'notification_type' => 'Invitation_Sent',
                'subject' => $template['subject'],
                'message' => $template['body']
            ]);

            logAudit($helper, $current_user['admin_id'], 'ADMIN_TEST_EMAIL', 'email_notifications', null, [
                'recipient_email' => $recipient_email,
                'template_key' => $template_key,
                'sent' => $delivery['ok'] ? 1 : 0
            ]);
            if (!$delivery['ok']) {
                echo ApiResponse::error('SMTP send failed: ' . ($delivery['error'] ?? 'Unknown error'), 500);
                break;
            }
            echo ApiResponse::success(null, 'Test email sent successfully');
            break;

        case 'categories':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $rows = $helper->fetchAll(
                "SELECT id, name, description, is_active, created_at, updated_at
                 FROM idea_categories
                 ORDER BY name ASC",
                []
            );
            echo ApiResponse::success($rows);
            break;

        case 'category_backup_create':
            if ($method !== 'POST') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $categories = $helper->fetchAll("SELECT * FROM idea_categories ORDER BY id ASC", []);
            $backup_name = trim($body['backup_name'] ?? ('Category Backup ' . date('Y-m-d H:i:s')));
            $backup_note = trim($body['backup_note'] ?? '');

            $helper->execute(
                "INSERT INTO category_backups (backup_name, category_snapshot, backup_note, created_by_admin_id, created_at)
                 VALUES (?, ?, ?, ?, NOW())",
                [$backup_name, json_encode($categories), $backup_note, $current_user['admin_id']]
            );
            $backup_id = intval($connection->lastInsertId());

            logAudit($helper, $current_user['admin_id'], 'ADMIN_CATEGORY_BACKUP', 'category_backups', $backup_id, ['count' => count($categories)]);
            echo ApiResponse::success(['backup_id' => $backup_id], 'Category backup created successfully', 201);
            break;

        case 'category_backups':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $rows = $helper->fetchAll(
                "SELECT cb.id, cb.backup_name, cb.backup_note, cb.created_at, au.full_name as created_by
                 FROM category_backups cb
                 JOIN admin_users au ON au.id = cb.created_by_admin_id
                 ORDER BY cb.created_at DESC",
                []
            );
            echo ApiResponse::success($rows);
            break;

        case 'category_restore':
            if ($method !== 'POST') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $backup_id = intval($body['backup_id'] ?? 0);
            if ($backup_id <= 0) {
                echo ApiResponse::error('backup_id is required', 400);
                exit();
            }
            $backup = $helper->fetchOne("SELECT * FROM category_backups WHERE id = ?", [$backup_id]);
            if (!$backup) {
                echo ApiResponse::error('Backup not found', 404);
                exit();
            }
            $snapshot = json_decode($backup['category_snapshot'], true);
            if (!is_array($snapshot)) {
                echo ApiResponse::error('Backup payload is invalid', 500);
                exit();
            }

            $restored_count = 0;
            foreach ($snapshot as $category) {
                $name = trim($category['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $existing = $helper->fetchOne("SELECT id FROM idea_categories WHERE name = ? LIMIT 1", [$name]);
                if ($existing) {
                    $helper->execute(
                        "UPDATE idea_categories SET description = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
                        [$category['description'] ?? '', intval($category['is_active'] ?? 1), $existing['id']]
                    );
                } else {
                    $helper->execute(
                        "INSERT INTO idea_categories (name, description, is_active, created_at)
                         VALUES (?, ?, ?, NOW())",
                        [$name, $category['description'] ?? '', intval($category['is_active'] ?? 1)]
                    );
                }
                $restored_count++;
            }

            logAudit($helper, $current_user['admin_id'], 'ADMIN_CATEGORY_RESTORE', 'category_backups', $backup_id, ['restored_count' => $restored_count]);
            echo ApiResponse::success(['restored_count' => $restored_count], 'Categories restored successfully');
            break;

        case 'create_backup':
            if ($method !== 'POST') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $scope_items = parseScopes($body['scope'] ?? null);
            $payload = buildBackupPayload($helper, $scope_items);
            $backup_name = trim($body['backup_name'] ?? ('System Backup ' . date('Y-m-d H:i:s')));
            $scope_csv = implode(',', $scope_items);

            $helper->execute(
                "INSERT INTO system_backups (backup_name, backup_scope, backup_payload, created_by_admin_id, created_at)
                 VALUES (?, ?, ?, ?, NOW())",
                [$backup_name, $scope_csv, json_encode($payload), $current_user['admin_id']]
            );
            $backup_id = intval($connection->lastInsertId());

            logAudit($helper, $current_user['admin_id'], 'ADMIN_CREATE_BACKUP', 'system_backups', $backup_id, ['scope' => $scope_items]);
            echo ApiResponse::success(['backup_id' => $backup_id], 'System backup created successfully', 201);
            break;

        case 'backups':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $rows = $helper->fetchAll(
                "SELECT sb.id, sb.backup_name, sb.backup_scope, sb.created_at, au.full_name as created_by
                 FROM system_backups sb
                 JOIN admin_users au ON au.id = sb.created_by_admin_id
                 ORDER BY sb.created_at DESC",
                []
            );
            echo ApiResponse::success($rows);
            break;

        case 'restore_backup':
            if ($method !== 'POST') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $backup_id = intval($body['backup_id'] ?? 0);
            if ($backup_id <= 0) {
                echo ApiResponse::error('backup_id is required', 400);
                exit();
            }
            $backup = $helper->fetchOne("SELECT * FROM system_backups WHERE id = ?", [$backup_id]);
            if (!$backup) {
                echo ApiResponse::error('Backup not found', 404);
                exit();
            }

            $payload = json_decode($backup['backup_payload'], true);
            if (!is_array($payload)) {
                echo ApiResponse::error('Backup payload is invalid', 500);
                exit();
            }

            $allowed_tables = [
                'departments',
                'system_settings',
                'notification_templates',
                'notification_settings',
                'role_permissions',
                'idea_categories',
            ];

            $connection->beginTransaction();
            try {
                $connection->exec("SET FOREIGN_KEY_CHECKS = 0");
                foreach ($allowed_tables as $table_name) {
                    if (!array_key_exists($table_name, $payload)) {
                        continue;
                    }
                    restoreTableData($helper, $connection, $table_name, $payload[$table_name]);
                }
                $connection->exec("SET FOREIGN_KEY_CHECKS = 1");
                $connection->commit();
            } catch (Exception $restore_error) {
                $connection->rollBack();
                $connection->exec("SET FOREIGN_KEY_CHECKS = 1");
                throw $restore_error;
            }

            logAudit($helper, $current_user['admin_id'], 'ADMIN_RESTORE_BACKUP', 'system_backups', $backup_id, ['scope' => $backup['backup_scope']]);
            echo ApiResponse::success(null, 'Backup restored successfully');
            break;

        case 'export_system_data':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $scope_items = parseScopes($_GET['scope'] ?? null);
            $payload = buildBackupPayload($helper, $scope_items);
            $export_payload = [
                'exported_at' => date('c'),
                'exported_by_admin_id' => $current_user['admin_id'],
                'scope' => $scope_items,
                'data' => $payload
            ];

            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="system_export_' . date('Y-m-d_H-i-s') . '.json"');
            echo json_encode($export_payload);
            exit();

        case 'export_logs':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $logs = $helper->fetchAll(
                "SELECT al.id, al.created_at, al.action, al.table_name, al.record_id, al.ip_address,
                        au.full_name as admin_name, au.email as admin_email
                 FROM audit_logs al
                 LEFT JOIN admin_users au ON au.id = al.admin_id
                 ORDER BY al.created_at DESC
                 LIMIT 5000",
                []
            );

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d_H-i-s') . '.csv"');
            $out = fopen('php://output', 'w');
            if ($out === false) {
                echo ApiResponse::error('Unable to export logs', 500);
                exit();
            }
            fputcsv($out, ['id', 'created_at', 'action', 'table_name', 'record_id', 'ip_address', 'admin_name', 'admin_email']);
            foreach ($logs as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['created_at'],
                    $row['action'],
                    $row['table_name'],
                    $row['record_id'],
                    $row['ip_address'],
                    $row['admin_name'],
                    $row['admin_email'],
                ]);
            }
            fclose($out);
            exit();

        case 'activity_logs':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $page = max(1, intval($_GET['page'] ?? 1));
            $per_page = max(1, min(200, intval($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $per_page;

            $total = intval($helper->fetchOne("SELECT COUNT(*) as count FROM audit_logs", [])['count'] ?? 0);
            $rows = $helper->fetchAll(
                "SELECT al.id, al.created_at, al.action, al.table_name, al.record_id, al.changes, al.ip_address,
                        au.full_name as admin_name
                 FROM audit_logs al
                 LEFT JOIN admin_users au ON au.id = al.admin_id
                 ORDER BY al.created_at DESC
                 LIMIT ? OFFSET ?",
                [$per_page, $offset]
            );
            echo ApiResponse::paginated($rows, $total, $page, $per_page);
            break;

        case 'login_history':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $page = max(1, intval($_GET['page'] ?? 1));
            $per_page = max(1, min(200, intval($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $per_page;

            $total = intval($helper->fetchOne("SELECT COUNT(*) as count FROM user_sessions", [])['count'] ?? 0);
            $rows = $helper->fetchAll(
                "SELECT us.id, us.admin_id, us.ip_address, us.user_agent, us.created_at, us.expires_at,
                        au.full_name as user_name, au.email as user_email, au.role
                 FROM user_sessions us
                 JOIN admin_users au ON au.id = us.admin_id
                 ORDER BY us.created_at DESC
                 LIMIT ? OFFSET ?",
                [$per_page, $offset]
            );
            echo ApiResponse::paginated($rows, $total, $page, $per_page);
            break;

        case 'storage_usage':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $summary = $helper->fetchOne(
                "SELECT COUNT(*) as total_files,
                        COALESCE(SUM(file_size), 0) as total_bytes,
                        SUM(CASE WHEN is_flagged = 1 THEN 1 ELSE 0 END) as flagged_files
                 FROM idea_attachments",
                []
            );
            $by_type = $helper->fetchAll(
                "SELECT LOWER(file_type) as file_type, COUNT(*) as file_count, COALESCE(SUM(file_size), 0) as bytes
                 FROM idea_attachments
                 GROUP BY LOWER(file_type)
                 ORDER BY bytes DESC",
                []
            );
            echo ApiResponse::success([
                'summary' => $summary,
                'by_type' => $by_type
            ]);
            break;

        case 'suspicious_activities':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $multi_ip = $helper->fetchAll(
                "SELECT us.admin_id, au.full_name as user_name, au.email, COUNT(DISTINCT us.ip_address) as ip_count,
                        COUNT(*) as session_count, MAX(us.created_at) as last_seen
                 FROM user_sessions us
                 JOIN admin_users au ON au.id = us.admin_id
                 WHERE us.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY us.admin_id, au.full_name, au.email
                 HAVING COUNT(DISTINCT us.ip_address) >= 3
                 ORDER BY ip_count DESC, session_count DESC",
                []
            );
            $high_severity_reports = $helper->fetchAll(
                "SELECT r.id, r.content_type, r.content_id, r.report_category, r.severity, r.status, r.reported_at
                 FROM staff_idea_reports r
                 WHERE r.severity IN ('High', 'Critical') AND r.status IN ('Reported', 'Under_Review')
                 ORDER BY r.reported_at DESC
                 LIMIT 200",
                []
            );

            echo ApiResponse::success([
                'multi_ip_logins' => $multi_ip,
                'high_severity_reports' => $high_severity_reports,
                'active_user_count' => intval($helper->fetchOne(
                    "SELECT COUNT(DISTINCT admin_id) as count FROM user_sessions WHERE expires_at > NOW()",
                    []
                )['count'] ?? 0)
            ]);
            break;

        case 'reported_content':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $rows = $helper->fetchAll(
                "SELECT r.id, r.content_type, r.content_id, r.report_category, r.reason, r.description, r.severity,
                        r.status, r.reported_at, r.resolved_at, rep.name as reporter_name,
                        CASE
                            WHEN r.content_type = 'Idea' THEN i.title
                            WHEN r.content_type = 'Comment' THEN LEFT(c.content, 200)
                            WHEN r.content_type = 'Reply' THEN LEFT(cr.content, 200)
                            ELSE ''
                        END as content_preview
                 FROM staff_idea_reports r
                 LEFT JOIN contributors rep ON rep.id = r.reporter_id
                 LEFT JOIN ideas i ON r.content_type = 'Idea' AND i.id = r.content_id
                 LEFT JOIN comments c ON r.content_type = 'Comment' AND c.id = r.content_id
                 LEFT JOIN comment_replies cr ON r.content_type = 'Reply' AND cr.id = r.content_id
                 ORDER BY r.reported_at DESC
                 LIMIT 500",
                []
            );
            echo ApiResponse::success($rows);
            break;

        case 'moderate_content':
            if ($method !== 'POST') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $content_type = trim($body['content_type'] ?? '');
            $content_id = intval($body['content_id'] ?? 0);
            $moderation_action = strtolower(trim($body['moderation_action'] ?? ''));
            $reason = trim($body['reason'] ?? 'Moderated by administrator');

            if (!in_array($content_type, ['Idea', 'Comment', 'Reply'], true) || $content_id <= 0 || !in_array($moderation_action, ['disable', 'enable'], true)) {
                echo ApiResponse::error('content_type, content_id, and moderation_action are required', 400);
                exit();
            }

            if ($content_type === 'Idea') {
                if ($moderation_action === 'disable') {
                    $helper->execute(
                        "UPDATE ideas
                         SET is_inappropriate = 1, inappropriate_reason = ?, flagged_by_admin_id = ?, flagged_at = NOW(), approval_status = 'Rejected'
                         WHERE id = ?",
                        [$reason, $current_user['admin_id'], $content_id]
                    );
                } else {
                    $helper->execute(
                        "UPDATE ideas
                         SET is_inappropriate = 0, inappropriate_reason = NULL, flagged_by_admin_id = NULL, flagged_at = NULL
                         WHERE id = ?",
                        [$content_id]
                    );
                }
            } elseif ($content_type === 'Comment') {
                if ($moderation_action === 'disable') {
                    $helper->execute(
                        "UPDATE comments
                         SET is_inappropriate = 1, inappropriate_reason = ?, flagged_by_admin_id = ?, flagged_at = NOW(), is_deleted = 1, deleted_reason = ?
                         WHERE id = ?",
                        [$reason, $current_user['admin_id'], $reason, $content_id]
                    );
                } else {
                    $helper->execute(
                        "UPDATE comments
                         SET is_inappropriate = 0, inappropriate_reason = NULL, flagged_by_admin_id = NULL, flagged_at = NULL, is_deleted = 0, deleted_reason = NULL
                         WHERE id = ?",
                        [$content_id]
                    );
                }
            } else {
                if ($moderation_action === 'disable') {
                    $helper->execute(
                        "UPDATE comment_replies
                         SET is_inappropriate = 1, inappropriate_reason = ?, is_deleted = 1, deleted_at = NOW()
                         WHERE id = ?",
                        [$reason, $content_id]
                    );
                } else {
                    $helper->execute(
                        "UPDATE comment_replies
                         SET is_inappropriate = 0, inappropriate_reason = NULL, is_deleted = 0, deleted_at = NULL
                         WHERE id = ?",
                        [$content_id]
                    );
                }
            }

            $new_status = ($moderation_action === 'disable') ? 'Flagged' : 'Dismissed';
            $helper->execute(
                "UPDATE staff_idea_reports
                 SET status = ?, resolved_at = NOW()
                 WHERE content_type = ? AND content_id = ? AND status IN ('Reported', 'Under_Review', 'Flagged')",
                [$new_status, $content_type, $content_id]
            );

            logAudit($helper, $current_user['admin_id'], 'ADMIN_MODERATE_CONTENT', strtolower($content_type), $content_id, [
                'action' => $moderation_action,
                'reason' => $reason
            ]);

            echo ApiResponse::success(null, 'Content moderation completed');
            break;

        case 'reveal_author':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $content_type = trim($_GET['content_type'] ?? '');
            $content_id = intval($_GET['content_id'] ?? 0);
            if (!in_array($content_type, ['Idea', 'Comment', 'Reply'], true) || $content_id <= 0) {
                echo ApiResponse::error('content_type and content_id are required', 400);
                exit();
            }

            if ($content_type === 'Idea') {
                $author = $helper->fetchOne(
                    "SELECT con.id as contributor_id, con.name, con.email, con.department, i.is_anonymous
                     FROM ideas i
                     JOIN contributors con ON con.id = i.contributor_id
                     WHERE i.id = ?",
                    [$content_id]
                );
            } elseif ($content_type === 'Comment') {
                $author = $helper->fetchOne(
                    "SELECT con.id as contributor_id, con.name, con.email, con.department, c.is_anonymous
                     FROM comments c
                     JOIN contributors con ON con.id = c.contributor_id
                     WHERE c.id = ?",
                    [$content_id]
                );
            } else {
                $author = $helper->fetchOne(
                    "SELECT con.id as contributor_id, con.name, con.email, con.department, cr.is_anonymous
                     FROM comment_replies cr
                     JOIN contributors con ON con.id = cr.contributor_id
                     WHERE cr.id = ?",
                    [$content_id]
                );
            }

            if (!$author) {
                echo ApiResponse::error('Author not found', 404);
                exit();
            }

            logAudit($helper, $current_user['admin_id'], 'ADMIN_REVEAL_AUTHOR', strtolower($content_type), $content_id, null);
            echo ApiResponse::success($author);
            break;

        case 'documents':
            if ($method === 'GET') {
                $search = trim($_GET['search'] ?? '');
                $page = max(1, intval($_GET['page'] ?? 1));
                $per_page = max(1, min(200, intval($_GET['per_page'] ?? 20)));
                $offset = ($page - 1) * $per_page;
                $where = [];
                $params = [];
                if ($search !== '') {
                    $where[] = "(ia.file_name LIKE ? OR i.title LIKE ? OR con.name LIKE ?)";
                    $like = '%' . $search . '%';
                    array_push($params, $like, $like, $like);
                }
                $where_sql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

                $total = intval($helper->fetchOne(
                    "SELECT COUNT(*) as count
                     FROM idea_attachments ia
                     JOIN ideas i ON i.id = ia.idea_id
                     LEFT JOIN contributors con ON con.id = ia.uploaded_by_contributor_id
                     $where_sql",
                    $params
                )['count'] ?? 0);

                $rows = $helper->fetchAll(
                    "SELECT ia.id, ia.idea_id, ia.file_name, ia.file_path, ia.file_type, ia.file_size, ia.is_flagged, ia.flagged_reason, ia.created_at,
                            i.title as idea_title,
                            con.name as uploader_name
                     FROM idea_attachments ia
                     JOIN ideas i ON i.id = ia.idea_id
                     LEFT JOIN contributors con ON con.id = ia.uploaded_by_contributor_id
                     $where_sql
                     ORDER BY ia.created_at DESC
                     LIMIT ? OFFSET ?",
                    array_merge($params, [$per_page, $offset])
                );

                echo ApiResponse::paginated($rows, $total, $page, $per_page);
                break;
            }

            if ($method === 'DELETE') {
                $document_id = intval($body['document_id'] ?? ($_GET['document_id'] ?? 0));
                if ($document_id <= 0) {
                    echo ApiResponse::error('document_id is required', 400);
                    exit();
                }
                $document = $helper->fetchOne("SELECT * FROM idea_attachments WHERE id = ?", [$document_id]);
                if (!$document) {
                    echo ApiResponse::error('Document not found', 404);
                    exit();
                }

                $abs_path = realpath(__DIR__ . '/../' . $document['file_path']);
                $uploads_root = realpath(__DIR__ . '/../uploads');
                if ($abs_path && $uploads_root && strpos($abs_path, $uploads_root) === 0 && file_exists($abs_path)) {
                    @unlink($abs_path);
                }

                $helper->execute("DELETE FROM idea_attachments WHERE id = ?", [$document_id]);
                logAudit($helper, $current_user['admin_id'], 'ADMIN_DELETE_DOCUMENT', 'idea_attachments', $document_id, ['file_name' => $document['file_name']]);
                echo ApiResponse::success(null, 'Document deleted successfully');
                break;
            }

            echo ApiResponse::error('Method not allowed', 405);
            break;

        case 'download_document':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }
            $document_id = intval($_GET['document_id'] ?? 0);
            if ($document_id <= 0) {
                echo ApiResponse::error('document_id is required', 400);
                exit();
            }
            $document = $helper->fetchOne("SELECT id, file_name, file_path, file_type FROM idea_attachments WHERE id = ?", [$document_id]);
            if (!$document) {
                echo ApiResponse::error('Document not found', 404);
                exit();
            }

            $file_path = realpath(__DIR__ . '/../' . $document['file_path']);
            $uploads_root = realpath(__DIR__ . '/../uploads');
            if (!$file_path || !$uploads_root || strpos($file_path, $uploads_root) !== 0 || !file_exists($file_path)) {
                echo ApiResponse::error('File is not available on server', 404);
                exit();
            }

            $extension = strtolower(pathinfo($document['file_name'], PATHINFO_EXTENSION));
            $mime_map = [
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
            ];
            $mime = $mime_map[$extension] ?? 'application/octet-stream';

            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . basename($document['file_name']) . '"');
            header('Content-Length: ' . filesize($file_path));
            readfile($file_path);
            exit();

        case 'system_report':
            if ($method !== 'GET') {
                echo ApiResponse::error('Method not allowed', 405);
                break;
            }

            $totals = [
                'total_users' => intval($helper->fetchOne("SELECT COUNT(*) as count FROM admin_users", [])['count'] ?? 0),
                'active_users' => intval($helper->fetchOne("SELECT COUNT(*) as count FROM admin_users WHERE is_active = 1", [])['count'] ?? 0),
                'active_sessions' => intval($helper->fetchOne("SELECT COUNT(DISTINCT admin_id) as count FROM user_sessions WHERE expires_at > NOW()", [])['count'] ?? 0),
                'total_ideas' => intval($helper->fetchOne("SELECT COUNT(*) as count FROM ideas", [])['count'] ?? 0),
                'total_comments' => intval($helper->fetchOne("SELECT COUNT(*) as count FROM comments WHERE is_deleted = 0", [])['count'] ?? 0),
                'total_documents' => intval($helper->fetchOne("SELECT COUNT(*) as count FROM idea_attachments", [])['count'] ?? 0),
            ];

            $ideas_by_department = $helper->fetchAll(
                "SELECT department, COUNT(*) as total_ideas
                 FROM ideas
                 WHERE department IS NOT NULL AND TRIM(department) != ''
                 GROUP BY department
                 ORDER BY total_ideas DESC",
                []
            );

            $comments_per_idea = $helper->fetchAll(
                "SELECT i.id as idea_id, i.title, COUNT(c.id) as total_comments
                 FROM ideas i
                 LEFT JOIN comments c ON c.idea_id = i.id AND c.is_deleted = 0
                 GROUP BY i.id, i.title
                 ORDER BY total_comments DESC
                 LIMIT 20",
                []
            );

            $most_active_staff = $helper->fetchAll(
                "SELECT con.name, con.email, con.department,
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
                 ORDER BY activity_score DESC
                 LIMIT 20",
                []
            );

            $storage_usage = $helper->fetchOne(
                "SELECT COUNT(*) as total_files, COALESCE(SUM(file_size), 0) as total_bytes
                 FROM idea_attachments",
                []
            );

            echo ApiResponse::success([
                'totals' => $totals,
                'ideas_by_department' => $ideas_by_department,
                'comments_per_idea' => $comments_per_idea,
                'most_active_staff' => $most_active_staff,
                'system_usage' => [
                    'storage' => $storage_usage,
                    'recent_audit_entries' => intval($helper->fetchOne(
                        "SELECT COUNT(*) as count FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                        []
                    )['count'] ?? 0),
                ],
            ]);
            break;

        case 'role_permissions':
            if ($method === 'GET') {
                $rows = $helper->fetchAll("SELECT role_key, permissions_json, updated_at FROM role_permissions ORDER BY role_key ASC", []);
                foreach ($rows as &$row) {
                    $decoded = json_decode($row['permissions_json'], true);
                    $row['permissions'] = is_array($decoded) ? $decoded : [];
                    unset($row['permissions_json']);
                }
                echo ApiResponse::success($rows);
                break;
            }

            if ($method === 'PUT') {
                $role_key = trim($body['role_key'] ?? '');
                $permissions = $body['permissions'] ?? null;
                if (!in_array($role_key, ['Admin', 'QAManager', 'QACoordinator', 'Staff'], true)) {
                    echo ApiResponse::error('Invalid role_key', 400);
                    exit();
                }
                if (!is_array($permissions)) {
                    echo ApiResponse::error('permissions object is required', 400);
                    exit();
                }

                $helper->execute(
                    "INSERT INTO role_permissions (role_key, permissions_json)
                     VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE permissions_json = VALUES(permissions_json), updated_at = NOW()",
                    [$role_key, json_encode($permissions)]
                );

                logAudit($helper, $current_user['admin_id'], 'ADMIN_UPDATE_ROLE_PERMISSIONS', 'role_permissions', null, ['role_key' => $role_key]);
                echo ApiResponse::success(null, 'Role permissions updated successfully');
                break;
            }

            echo ApiResponse::error('Method not allowed', 405);
            break;

        default:
            echo ApiResponse::error('Unknown action', 400);
            break;
    }
} catch (Exception $e) {
    echo ApiResponse::error('Server error: ' . $e->getMessage(), 500);
}

?>
