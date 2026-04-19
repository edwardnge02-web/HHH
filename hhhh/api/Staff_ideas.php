<?php
// api/staff_ideas.php
// Staff: submit ideas, browse/filter ideas, vote, terms, categories

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

function ensureStaffIdeaSchema($helper) {
    $idea_is_anonymous_col = $helper->fetchOne(
        "SELECT COUNT(*) as count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ideas' AND COLUMN_NAME = 'is_anonymous'",
        []
    );
    if (intval($idea_is_anonymous_col['count'] ?? 0) === 0) {
        $helper->execute("ALTER TABLE ideas ADD COLUMN is_anonymous TINYINT(1) NOT NULL DEFAULT 0", []);
    }

    $final_closure_col = $helper->fetchOne(
        "SELECT COUNT(*) as count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessions' AND COLUMN_NAME = 'final_closure_date'",
        []
    );
    if (intval($final_closure_col['count'] ?? 0) === 0) {
        $helper->execute("ALTER TABLE sessions ADD COLUMN final_closure_date DATETIME NULL AFTER closes_at", []);
    }
    $helper->execute("UPDATE sessions SET final_closure_date = closes_at WHERE final_closure_date IS NULL", []);

    $helper->execute(
        "CREATE TABLE IF NOT EXISTS idea_category_tags (
            id INT PRIMARY KEY AUTO_INCREMENT,
            idea_id INT NOT NULL,
            category_id INT NOT NULL,
            tagged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_idea_category (idea_id, category_id),
            INDEX idx_idea (idea_id),
            INDEX idx_category (category_id),
            CONSTRAINT fk_idea_category_tags_idea FOREIGN KEY (idea_id) REFERENCES ideas(id) ON DELETE CASCADE,
            CONSTRAINT fk_idea_category_tags_category FOREIGN KEY (category_id) REFERENCES idea_categories(id) ON DELETE CASCADE
        )",
        []
    );

    $staff_invitations_table = $helper->fetchOne(
        "SELECT COUNT(*) as count
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_invitations'",
        []
    );
    if (intval($staff_invitations_table['count'] ?? 0) > 0) {
        $staff_invitation_idea_col = $helper->fetchOne(
            "SELECT COUNT(*) as count
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_invitations' AND COLUMN_NAME = 'idea_id'",
            []
        );
        if (intval($staff_invitation_idea_col['count'] ?? 0) === 0) {
            $helper->execute(
                "ALTER TABLE staff_invitations
                 ADD COLUMN idea_id INT NULL AFTER session_id,
                 ADD INDEX idx_staff_invitations_idea (idea_id)",
                []
            );
        }
    }
}

function getCurrentStaffRecord($helper, $current_user) {
    if (!$current_user) {
        return null;
    }

    // Email is the safest cross-table identity between auth user and staff records.
    $staff = null;
    if (!empty($current_user['email'])) {
        $staff = $helper->fetchOne(
            "SELECT id, name, email, department FROM staff WHERE email = ? LIMIT 1",
            [$current_user['email']]
        );
    }

    if (!$staff) {
        $staff = $helper->fetchOne(
            "SELECT id, name, email, department FROM staff WHERE id = ? LIMIT 1",
            [$current_user['admin_id']]
        );
    }

    return $staff ?: null;
}

function getCurrentStaffId($helper, $current_user) {
    $staff = getCurrentStaffRecord($helper, $current_user);
    if (!$staff) {
        return null;
    }
    return intval($staff['id']);
}

function generateUuidV4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function getOrCreateContributorId($helper, $connection, $staff) {
    $contributor = $helper->fetchOne(
        "SELECT id FROM contributors WHERE email = ? LIMIT 1",
        [$staff['email']]
    );

    if ($contributor) {
        return intval($contributor['id']);
    }

    $user_uuid = generateUuidV4();
    $helper->execute(
        "INSERT INTO contributors (user_uuid, email, name, department) VALUES (?, ?, ?, ?)",
        [$user_uuid, $staff['email'], $staff['name'], $staff['department']]
    );

    return intval($connection->lastInsertId());
}

function getCurrentContributorId($helper, $connection, $current_user, $create_if_missing = true) {
    $staff = getCurrentStaffRecord($helper, $current_user);
    if (!$staff) {
        return null;
    }

    $contributor = $helper->fetchOne(
        "SELECT id FROM contributors WHERE email = ? LIMIT 1",
        [$staff['email']]
    );

    if ($contributor) {
        return intval($contributor['id']);
    }

    if (!$create_if_missing) {
        return null;
    }

    return getOrCreateContributorId($helper, $connection, $staff);
}

function getIdeaTags($helper, $idea_id) {
    return $helper->fetchAll(
        "SELECT ic.id, ic.name
         FROM idea_category_tags ict
         JOIN idea_categories ic ON ict.category_id = ic.id
         WHERE ict.idea_id = ?
         ORDER BY ic.name ASC",
        [$idea_id]
    );
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
    } catch (Exception $e) {
        return $default;
    }
}

function getDepartmentCoordinators($helper, $department) {
    $department = trim(strval($department));
    if ($department === '') {
        return [];
    }

    $mapping_table = $helper->fetchOne(
        "SELECT COUNT(*) as count
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qa_coordinator_departments'",
        []
    );

    if (intval($mapping_table['count'] ?? 0) > 0) {
        $coordinators = $helper->fetchAll(
            "SELECT DISTINCT au.id as admin_id, au.email, au.full_name
             FROM qa_coordinator_departments qcd
             JOIN admin_users au ON au.id = qcd.coordinator_id
             WHERE qcd.department = ?
               AND qcd.is_active = 1
               AND au.role = 'QACoordinator'
               AND au.is_active = 1
             ORDER BY au.id ASC",
            [$department]
        );

        if (!empty($coordinators)) {
            return $coordinators;
        }
    }

    $coordinators = $helper->fetchAll(
        "SELECT id as admin_id, email, full_name
         FROM admin_users
         WHERE role = 'QACoordinator' AND department = ? AND is_active = 1
         ORDER BY id ASC",
        [$department]
    );

    if (!empty($coordinators)) {
        return $coordinators;
    }

    return $helper->fetchAll(
        "SELECT id as admin_id, email, full_name
         FROM admin_users
         WHERE role = 'QACoordinator' AND is_active = 1
         ORDER BY id ASC",
        []
    );
}

function getActiveQaManagers($helper) {
    $mapping_table = $helper->fetchOne(
        "SELECT COUNT(*) as count
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qa_managers'",
        []
    );

    if (intval($mapping_table['count'] ?? 0) > 0) {
        $managers = $helper->fetchAll(
            "SELECT DISTINCT au.id as admin_id, au.email, au.full_name
             FROM qa_managers qm
             JOIN admin_users au ON au.id = qm.admin_user_id
             WHERE qm.is_active = 1
               AND au.role = 'QAManager'
               AND au.is_active = 1
             ORDER BY au.id ASC",
            []
        );

        if (!empty($managers)) {
            return $managers;
        }
    }

    return $helper->fetchAll(
        "SELECT id as admin_id, email, full_name
         FROM admin_users
         WHERE role = 'QAManager' AND is_active = 1
         ORDER BY id ASC",
        []
    );
}

try {
    ensureStaffIdeaSchema($helper);

    switch ($action) {
        case 'get_sessions':
            if ($method === 'GET') {
                $auth->requireAuth();

                $sessions = $helper->fetchAll(
                    "SELECT s.id, s.session_name, s.opens_at, s.closes_at, s.final_closure_date, s.status,
                            cat.name as category_name,
                            a.year_label,
                            DATEDIFF(s.closes_at, NOW()) as days_until_closure,
                            CASE
                                WHEN s.status = 'Active' AND s.opens_at <= NOW() AND s.closes_at >= NOW() THEN 1
                                ELSE 0
                            END as can_submit
                     FROM sessions s
                     JOIN idea_categories cat ON s.category_id = cat.id
                     JOIN academic_years a ON s.academic_year_id = a.id
                     WHERE s.status IN ('Active', 'Closed')
                     ORDER BY can_submit DESC, s.closes_at ASC",
                    []
                );

                echo ApiResponse::success($sessions);
            }
            break;

        case 'get_categories':
            if ($method === 'GET') {
                $auth->requireAuth();

                $categories = $helper->fetchAll(
                    "SELECT id, name, description
                     FROM idea_categories
                     WHERE is_active = 1
                     ORDER BY name ASC",
                    []
                );

                echo ApiResponse::success($categories);
            }
            break;

        case 'get_tc':
            if ($method === 'GET') {
                $tc = $helper->fetchOne(
                    "SELECT id, version, content FROM terms_and_conditions WHERE is_active = TRUE ORDER BY version DESC LIMIT 1",
                    []
                );

                if (!$tc) {
                    echo ApiResponse::success([
                        'id' => null,
                        'version' => 1,
                        'content' => '',
                        'accepted' => false,
                    ]);
                    break;
                }

                $accepted = false;
                if ($current_user) {
                    $staff_id = getCurrentStaffId($helper, $current_user);
                    if ($staff_id) {
                        $acceptance = $helper->fetchOne(
                            "SELECT id FROM staff_tc_acceptance WHERE staff_id = ? AND tc_version = ?",
                            [$staff_id, $tc['version']]
                        );
                        $accepted = !empty($acceptance);
                    }
                }

                $tc['accepted'] = $accepted;
                echo ApiResponse::success($tc);
            }
            break;

        case 'accept_tc':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);
                $tc_version = intval($input['tc_version'] ?? 1);
                $staff_id = getCurrentStaffId($helper, $current_user);
                if (!$staff_id) {
                    echo ApiResponse::error('Staff profile not found for the current user', 403);
                    exit();
                }

                $active_tc = $helper->fetchOne(
                    "SELECT version FROM terms_and_conditions WHERE is_active = TRUE ORDER BY version DESC LIMIT 1",
                    []
                );
                if (!$active_tc) {
                    echo ApiResponse::error('No active terms and conditions found', 404);
                    exit();
                }

                if ($tc_version !== intval($active_tc['version'])) {
                    echo ApiResponse::error('Please accept the latest Terms and Conditions version', 400);
                    exit();
                }

                $existing = $helper->fetchOne(
                    "SELECT id FROM staff_tc_acceptance WHERE staff_id = ? AND tc_version = ?",
                    [$staff_id, $tc_version]
                );

                if ($existing) {
                    echo ApiResponse::success(null, 'T&C already accepted');
                    exit();
                }

                $helper->execute(
                    "INSERT INTO staff_tc_acceptance (staff_id, tc_version, accepted_at, ip_address)
                     VALUES (?, ?, NOW(), ?)",
                    [$staff_id, $tc_version, $_SERVER['REMOTE_ADDR'] ?? null]
                );

                echo ApiResponse::success(null, 'T&C accepted successfully');
            }
            break;

        case 'submit_idea':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);

                $submissions_enabled = getSystemSettingValue($helper, 'idea_submissions_enabled', true);
                if (!$submissions_enabled) {
                    echo ApiResponse::error('Idea submissions are currently disabled by administrator', 403);
                    exit();
                }

                $title = trim($input['title'] ?? '');
                $description = trim($input['description'] ?? '');
                $session_id = intval($input['session_id'] ?? 0);
                $category_ids_raw = is_array($input['category_ids'] ?? null) ? $input['category_ids'] : [];
                $category_ids = [];
                foreach ($category_ids_raw as $cid) {
                    $cid_int = intval($cid);
                    if ($cid_int > 0) {
                        $category_ids[] = $cid_int;
                    }
                }
                $category_ids = array_values(array_unique($category_ids));
                $is_anonymous = !empty($input['is_anonymous']) ? 1 : 0;

                if ($title === '' || $description === '' || $session_id <= 0) {
                    echo ApiResponse::error('Title, description, and session are required', 400);
                    exit();
                }

                // Enforce T&C acceptance at API layer.
                $active_tc = $helper->fetchOne(
                    "SELECT version FROM terms_and_conditions WHERE is_active = TRUE ORDER BY version DESC LIMIT 1",
                    []
                );
                if ($active_tc) {
                    $staff_id = getCurrentStaffId($helper, $current_user);
                    if (!$staff_id) {
                        echo ApiResponse::error('Staff profile not found for the current user', 403);
                        exit();
                    }
                    $accepted = $helper->fetchOne(
                        "SELECT id FROM staff_tc_acceptance WHERE staff_id = ? AND tc_version = ?",
                        [$staff_id, intval($active_tc['version'])]
                    );
                    if (!$accepted) {
                        echo ApiResponse::error('Please accept Terms and Conditions before submitting ideas', 403);
                        exit();
                    }
                }

                $session = $helper->fetchOne(
                    "SELECT id, session_name, opens_at, closes_at, final_closure_date FROM sessions WHERE id = ?",
                    [$session_id]
                );
                if (!$session) {
                    echo ApiResponse::error('Session not found', 404);
                    exit();
                }

                $now = date('Y-m-d H:i:s');
                if ($session['opens_at'] > $now) {
                    echo ApiResponse::error('Submission window has not opened yet', 400);
                    exit();
                }
                if ($session['closes_at'] < $now) {
                    echo ApiResponse::error('Submission window closed', 400);
                    exit();
                }

                $staff = getCurrentStaffRecord($helper, $current_user);
                if (!$staff) {
                    echo ApiResponse::error('Staff profile not found for the current user', 403);
                    exit();
                }

                $contributor_id = getOrCreateContributorId($helper, $connection, $staff);

                $helper->execute(
                    "INSERT INTO ideas (title, description, session_id, contributor_id, department, status, approval_status, submitted_at, is_anonymous)
                     VALUES (?, ?, ?, ?, ?, 'Submitted', 'Approved', NOW(), ?)",
                    [$title, $description, $session_id, $contributor_id, $staff['department'], $is_anonymous]
                );
                $idea_id = intval($connection->lastInsertId());

                if (!empty($category_ids)) {
                    foreach ($category_ids as $cat_id) {
                        $category_exists = $helper->fetchOne(
                            "SELECT id FROM idea_categories WHERE id = ? AND is_active = 1",
                            [$cat_id]
                        );
                        if ($category_exists) {
                            $helper->execute(
                                "INSERT IGNORE INTO idea_category_tags (idea_id, category_id, tagged_at) VALUES (?, ?, NOW())",
                                [$idea_id, $cat_id]
                            );
                        }
                    }
                }

                $coordinators = getDepartmentCoordinators($helper, $staff['department']);
                $coordinator_email_subject = "New idea submitted in {$session['session_name']} campaign";
                $coordinator_email_message = emailServiceBuildPlainText(
                    'Hello Coordinator,',
                    "Staff {$staff['name']} from {$staff['department']} department has submitted a new idea.",
                    [
                        'Staff Name' => trim($staff['name'] ?? ''),
                        'Department' => trim($staff['department'] ?? ''),
                        'Campaign' => trim($session['session_name'] ?? ''),
                        'Idea Title' => trim($title),
                        'Anonymous Submission' => $is_anonymous ? 'Yes' : 'No',
                        'Idea Preview' => mb_substr(trim($description), 0, 220),
                    ],
                    [
                        'Please sign in to the system to review the full idea submission.',
                        'This is an automated notification from Ideas System',
                    ]
                );

                foreach ($coordinators as $coordinator) {
                    sendSystemEmail($connection, [
                        'recipient_email' => $coordinator['email'],
                        'recipient_type' => 'Coordinator',
                        'notification_type' => 'Idea_Submitted',
                        'subject' => $coordinator_email_subject,
                        'message' => $coordinator_email_message,
                        'idea_id' => $idea_id,
                        'session_id' => $session_id,
                    ]);

                    $helper->execute(
                        "INSERT INTO system_notifications (recipient_type, recipient_id, recipient_email, notification_type, title, message, idea_id, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                        [
                            'Coordinator',
                            $coordinator['admin_id'],
                            $coordinator['email'],
                            'Idea_Submitted',
                            $coordinator_email_subject,
                            $coordinator_email_message,
                            $idea_id,
                        ]
                    );
                }

                $qa_managers = getActiveQaManagers($helper);

                foreach ($qa_managers as $manager) {
                    $manager_email_subject = "New idea submitted in {$session['session_name']} campaign";
                    $manager_email_message = emailServiceBuildPlainText(
                        'Hello QA Manager,',
                        "A new idea has been submitted for review.",
                        [
                            'Staff Name' => trim($staff['name'] ?? ''),
                            'Department' => trim($staff['department'] ?? ''),
                            'Campaign' => trim($session['session_name'] ?? ''),
                            'Idea Title' => trim($title),
                            'Anonymous Submission' => $is_anonymous ? 'Yes' : 'No',
                            'Idea Preview' => mb_substr(trim($description), 0, 220),
                        ],
                        [
                            'Please sign in to the system to review the full idea submission.',
                            'This is an automated notification from Ideas System',
                        ]
                    );

                    sendSystemEmail($connection, [
                        'recipient_email' => $manager['email'],
                        'recipient_type' => 'QAManager',
                        'notification_type' => 'Idea_Submitted',
                        'subject' => $manager_email_subject,
                        'message' => $manager_email_message,
                        'idea_id' => $idea_id,
                        'session_id' => $session_id,
                    ]);

                    $helper->execute(
                        "INSERT INTO system_notifications (recipient_type, recipient_id, recipient_email, notification_type, title, message, idea_id, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                        [
                            'QAManager',
                            $manager['admin_id'],
                            $manager['email'],
                            'Idea_Submitted',
                            $manager_email_subject,
                            $manager_email_message,
                            $idea_id,
                        ]
                    );
                }

                $helper->execute(
                    "INSERT INTO staff_submission_tracking (session_id, department, staff_id, has_submitted, first_submission_date, idea_count)
                     VALUES (?, ?, ?, 1, NOW(), 1)
                     ON DUPLICATE KEY UPDATE has_submitted = 1, idea_count = idea_count + 1, last_idea_date = NOW()",
                    [$session_id, $staff['department'], $staff['id']]
                );

                echo ApiResponse::success(['id' => $idea_id], 'Idea submitted successfully', 201);
            }
            break;

        case 'get_ideas':
            if ($method === 'GET') {
                $auth->requireAuth();

                $filter = $_GET['filter'] ?? 'latest';
                $session_id = intval($_GET['session_id'] ?? 0);
                $category_id = intval($_GET['category_id'] ?? 0);
                $page = max(1, intval($_GET['page'] ?? 1));
                $per_page_setting = intval(getSystemSettingValue($helper, 'default_ideas_per_page', 5));
                $per_page = max(1, min(50, $per_page_setting));
                $offset = ($page - 1) * $per_page;

                $where = "WHERE i.status = 'Submitted' AND i.approval_status = 'Approved'";
                $params = [];
                $order = "i.submitted_at DESC";

                if ($session_id > 0) {
                    $where .= " AND i.session_id = ?";
                    $params[] = $session_id;
                }

                if ($category_id > 0) {
                    $where .= " AND (EXISTS (SELECT 1 FROM idea_category_tags ict WHERE ict.idea_id = i.id AND ict.category_id = ?) OR s.category_id = ?)";
                    $params[] = $category_id;
                    $params[] = $category_id;
                }

                if ($filter === 'popular') {
                    $order = "(COALESCE(i.upvote_count, 0) - COALESCE(i.downvote_count, 0)) DESC, i.submitted_at DESC";
                } elseif ($filter === 'unpopular') {
                    $order = "(COALESCE(i.upvote_count, 0) - COALESCE(i.downvote_count, 0)) ASC, i.submitted_at DESC";
                } elseif ($filter === 'viewed') {
                    $order = "i.view_count DESC, i.submitted_at DESC";
                } elseif ($filter === 'comments') {
                    $order = "i.comment_count DESC, i.submitted_at DESC";
                }

                $count_result = $helper->fetchOne(
                    "SELECT COUNT(*) as count FROM ideas i
                     JOIN sessions s ON i.session_id = s.id
                     $where",
                    $params
                );
                $total = intval($count_result['count'] ?? 0);

                $ideas = $helper->fetchAll(
                    "SELECT i.id, i.title, i.description, i.department, i.view_count,
                            COALESCE(i.upvote_count, 0) as upvote_count,
                            COALESCE(i.downvote_count, 0) as downvote_count,
                            COALESCE(i.upvote_count, 0) - COALESCE(i.downvote_count, 0) as net_votes,
                            i.comment_count, i.submitted_at, i.is_anonymous,
                            IF(i.is_anonymous = 1, 'Anonymous', c.name) as contributor_name,
                            s.session_name, s.closes_at, s.final_closure_date,
                            cat.name as session_category_name
                     FROM ideas i
                     JOIN sessions s ON i.session_id = s.id
                     JOIN contributors c ON i.contributor_id = c.id
                     LEFT JOIN idea_categories cat ON s.category_id = cat.id
                     $where
                     ORDER BY $order
                     LIMIT ? OFFSET ?",
                    array_merge($params, [$per_page, $offset])
                );

                $current_contributor_id = getCurrentContributorId($helper, $connection, $current_user, false);

                foreach ($ideas as &$idea) {
                    $idea['tags'] = getIdeaTags($helper, $idea['id']);

                    if ($current_contributor_id) {
                        $vote = $helper->fetchOne(
                            "SELECT vote_type FROM idea_votes WHERE idea_id = ? AND contributor_id = ?",
                            [$idea['id'], $current_contributor_id]
                        );
                        $idea['user_vote'] = $vote['vote_type'] ?? null;
                    } else {
                        $idea['user_vote'] = null;
                    }
                }

                echo ApiResponse::paginated($ideas, $total, $page, $per_page);
            }
            break;

        case 'get_idea':
            if ($method === 'GET') {
                $auth->requireAuth();

                $idea_id = intval($_GET['idea_id'] ?? 0);
                if ($idea_id <= 0) {
                    echo ApiResponse::error('Idea ID required', 400);
                    exit();
                }

                $current_contributor_id = getCurrentContributorId($helper, $connection, $current_user, true);
                if ($current_contributor_id) {
                    $helper->execute(
                        "INSERT INTO idea_views (idea_id, viewer_id, viewed_at) VALUES (?, ?, NOW())",
                        [$idea_id, $current_contributor_id]
                    );
                }

                $idea = $helper->fetchOne(
                    "SELECT i.*, c.name as contributor_name, c.email as contributor_email,
                            IF(i.is_anonymous = 1, 'Anonymous', c.name) as display_name,
                            s.session_name, s.closes_at, s.final_closure_date,
                            cat.name as session_category_name
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

                $idea['attachments'] = $helper->fetchAll(
                    "SELECT id, file_name, file_path, file_type, file_size, created_at FROM idea_attachments WHERE idea_id = ? ORDER BY created_at DESC",
                    [$idea_id]
                );

                $idea['tags'] = getIdeaTags($helper, $idea_id);

                $final_closure = $idea['final_closure_date'] ?: $idea['closes_at'];
                $submissions_enabled = getSystemSettingValue($helper, 'idea_submissions_enabled', true);
                $commenting_enabled = getSystemSettingValue($helper, 'commenting_enabled', true);
                $idea['can_submit'] = $submissions_enabled && (strtotime($idea['closes_at']) > time());
                $idea['can_comment'] = $commenting_enabled && (strtotime($final_closure) > time());
                $idea['is_owner'] = ($current_contributor_id && intval($idea['contributor_id']) === intval($current_contributor_id));

                // Show latest coordinator invitation for this staff + session in the idea view.
                // Show the latest invitation for this related idea to all staff viewers.
                $latest_invitation = $helper->fetchOne(
                    "SELECT si.id, si.message, si.sent_at, si.session_id,
                            au.full_name as coordinator_name,
                            s_recipient.name as recipient_name,
                            s_recipient.email as recipient_email
                     FROM staff_invitations si
                     JOIN staff s_recipient ON s_recipient.id = si.staff_id
                     LEFT JOIN admin_users au ON au.id = si.coordinator_id
                     WHERE si.session_id = ?
                       AND si.idea_id = ?
                     ORDER BY si.sent_at DESC
                     LIMIT 1",
                    [
                        intval($idea['session_id']),
                        intval($idea_id)
                    ]
                );
                $idea['latest_invitation'] = $latest_invitation ?: null;

                if ($current_contributor_id) {
                    $vote = $helper->fetchOne(
                        "SELECT vote_type FROM idea_votes WHERE idea_id = ? AND contributor_id = ?",
                        [$idea_id, $current_contributor_id]
                    );
                    $idea['user_vote'] = $vote['vote_type'] ?? null;
                } else {
                    $idea['user_vote'] = null;
                }

                echo ApiResponse::success($idea);
            }
            break;

        case 'update_idea':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);

                $idea_id = intval($input['idea_id'] ?? 0);
                $title = trim($input['title'] ?? '');
                $description = trim($input['description'] ?? '');
                $is_anonymous = !empty($input['is_anonymous']) ? 1 : 0;
                $category_ids_raw = is_array($input['category_ids'] ?? null) ? $input['category_ids'] : [];
                $category_ids = [];
                foreach ($category_ids_raw as $cid) {
                    $cid_int = intval($cid);
                    if ($cid_int > 0) {
                        $category_ids[] = $cid_int;
                    }
                }
                $category_ids = array_values(array_unique($category_ids));

                if ($idea_id <= 0 || $title === '' || $description === '') {
                    echo ApiResponse::error('Idea ID, title, and description are required', 400);
                    exit();
                }

                $contributor_id = getCurrentContributorId($helper, $connection, $current_user, true);
                if (!$contributor_id) {
                    echo ApiResponse::error('Contributor profile not found for current user', 403);
                    exit();
                }

                $idea = $helper->fetchOne(
                    "SELECT i.id, i.contributor_id, s.closes_at
                     FROM ideas i
                     JOIN sessions s ON i.session_id = s.id
                     WHERE i.id = ?",
                    [$idea_id]
                );
                if (!$idea) {
                    echo ApiResponse::error('Idea not found', 404);
                    exit();
                }
                if (intval($idea['contributor_id']) !== intval($contributor_id)) {
                    echo ApiResponse::error('You can only edit your own ideas', 403);
                    exit();
                }
                if (strtotime($idea['closes_at']) < time()) {
                    echo ApiResponse::error('Idea editing is closed for this session', 400);
                    exit();
                }

                $helper->execute(
                    "UPDATE ideas SET title = ?, description = ?, is_anonymous = ?, updated_at = NOW() WHERE id = ?",
                    [$title, $description, $is_anonymous, $idea_id]
                );

                $helper->execute("DELETE FROM idea_category_tags WHERE idea_id = ?", [$idea_id]);
                if (!empty($category_ids)) {
                    foreach ($category_ids as $cat_id) {
                        $category_exists = $helper->fetchOne(
                            "SELECT id FROM idea_categories WHERE id = ? AND is_active = 1",
                            [$cat_id]
                        );
                        if ($category_exists) {
                            $helper->execute(
                                "INSERT IGNORE INTO idea_category_tags (idea_id, category_id, tagged_at) VALUES (?, ?, NOW())",
                                [$idea_id, $cat_id]
                            );
                        }
                    }
                }

                echo ApiResponse::success(null, 'Idea updated successfully');
            }
            break;

        case 'delete_idea':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);

                $idea_id = intval($input['idea_id'] ?? 0);
                if ($idea_id <= 0) {
                    echo ApiResponse::error('Idea ID is required', 400);
                    exit();
                }

                $contributor_id = getCurrentContributorId($helper, $connection, $current_user, true);
                if (!$contributor_id) {
                    echo ApiResponse::error('Contributor profile not found for current user', 403);
                    exit();
                }

                $idea = $helper->fetchOne(
                    "SELECT id, contributor_id FROM ideas WHERE id = ?",
                    [$idea_id]
                );
                if (!$idea) {
                    echo ApiResponse::error('Idea not found', 404);
                    exit();
                }
                if (intval($idea['contributor_id']) !== intval($contributor_id)) {
                    echo ApiResponse::error('You can only delete your own ideas', 403);
                    exit();
                }

                $helper->execute(
                    "UPDATE ideas SET status = 'Deleted', approval_status = 'Deleted', updated_at = NOW() WHERE id = ?",
                    [$idea_id]
                );

                echo ApiResponse::success(null, 'Idea deleted successfully');
            }
            break;

        case 'vote_idea':
            if ($method === 'POST') {
                $auth->requireAuth();
                $input = json_decode(file_get_contents('php://input'), true);

                $idea_id = intval($input['idea_id'] ?? 0);
                $vote_type = $input['vote_type'] ?? null;

                if ($idea_id <= 0 || !in_array($vote_type, ['up', 'down'], true)) {
                    echo ApiResponse::error('Idea ID and vote type required', 400);
                    exit();
                }

                $idea_exists = $helper->fetchOne("SELECT id FROM ideas WHERE id = ?", [$idea_id]);
                if (!$idea_exists) {
                    echo ApiResponse::error('Idea not found', 404);
                    exit();
                }

                $contributor_id = getCurrentContributorId($helper, $connection, $current_user, true);
                if (!$contributor_id) {
                    echo ApiResponse::error('Contributor profile not found for current user', 403);
                    exit();
                }

                $existing = $helper->fetchOne(
                    "SELECT id FROM idea_votes WHERE idea_id = ? AND contributor_id = ?",
                    [$idea_id, $contributor_id]
                );

                if ($existing) {
                    echo ApiResponse::error('You have already voted on this idea', 400);
                    exit();
                }

                $helper->execute(
                    "INSERT INTO idea_votes (idea_id, contributor_id, vote_type, voted_at) VALUES (?, ?, ?, NOW())",
                    [$idea_id, $contributor_id, $vote_type]
                );

                echo ApiResponse::success(null, 'Vote recorded successfully');
            }
            break;

        case 'latest_comments':
            if ($method === 'GET') {
                $limit = max(1, min(50, intval($_GET['limit'] ?? 10)));
                $session_id = intval($_GET['session_id'] ?? 0);
                $where = ["c.is_deleted = 0", "i.status <> 'Deleted'"];
                $params = [];
                if ($session_id > 0) {
                    $where[] = "s.id = ?";
                    $params[] = $session_id;
                }
                $where_clause = "WHERE " . implode(" AND ", $where);
                $params[] = $limit;

                $comments = $helper->fetchAll(
                    "SELECT c.id, c.content, c.created_at,
                            IF(c.is_anonymous = 1, 'Anonymous', con.name) as contributor_name,
                            i.id as idea_id, i.title as idea_title,
                            s.session_name
                     FROM comments c
                     JOIN ideas i ON c.idea_id = i.id
                     JOIN sessions s ON i.session_id = s.id
                     JOIN contributors con ON c.contributor_id = con.id
                     $where_clause
                     ORDER BY c.created_at DESC
                     LIMIT ?",
                    $params
                );

                echo ApiResponse::success($comments);
            }
            break;

        case 'notifications':
            if ($method === 'GET') {
                $auth->requireAuth();

                $notifications_table = $helper->fetchOne(
                    "SELECT COUNT(*) as count
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_notifications'",
                    []
                );
                if (intval($notifications_table['count'] ?? 0) === 0) {
                    echo ApiResponse::success([]);
                    break;
                }

                $limit = max(1, min(200, intval($_GET['limit'] ?? 50)));
                $email = trim($current_user['email'] ?? '');
                $admin_id = intval($current_user['admin_id'] ?? 0);

                $rows = $helper->fetchAll(
                    "SELECT id, notification_type, title, message, idea_id, comment_id, created_at
                     FROM system_notifications
                     WHERE recipient_type = 'Staff'
                       AND (recipient_email = ? OR recipient_id = ?)
                     ORDER BY created_at DESC
                     LIMIT ?",
                    [$email, $admin_id, $limit]
                );

                echo ApiResponse::success($rows);
            }
            break;

        default:
            echo ApiResponse::error('Unknown action', 400);
    }
} catch (Exception $e) {
    echo ApiResponse::error('Error: ' . $e->getMessage(), 500);
}

?>
