<?php
// api/staff_attachments.php
// Staff: upload supporting files for submitted ideas

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

$action = $_GET['action'] ?? null;
$current_user = $auth->getCurrentUser();

function generateUuidV4ForContributor() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function getCurrentContributorIdForUpload($helper, $connection, $current_user) {
    if (!$current_user) {
        return null;
    }

    $staff = $helper->fetchOne(
        "SELECT id, name, email, department FROM staff WHERE id = ? LIMIT 1",
        [$current_user['admin_id']]
    );

    if (!$staff && !empty($current_user['email'])) {
        $staff = $helper->fetchOne(
            "SELECT id, name, email, department FROM staff WHERE email = ? LIMIT 1",
            [$current_user['email']]
        );
    }

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

    $user_uuid = generateUuidV4ForContributor();
    $helper->execute(
        "INSERT INTO contributors (user_uuid, email, name, department) VALUES (?, ?, ?, ?)",
        [$user_uuid, $staff['email'], $staff['name'], $staff['department']]
    );

    return intval($connection->lastInsertId());
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
        if (($row['value_type'] ?? '') === 'int') {
            return intval($row['setting_value']);
        }
        if (($row['value_type'] ?? '') === 'json') {
            $decoded = json_decode($row['setting_value'], true);
            return $decoded !== null ? $decoded : $default;
        }
        return $row['setting_value'];
    } catch (Exception $e) {
        return $default;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'upload') {
        echo ApiResponse::error('Method not allowed', 405);
        exit();
    }

    $auth->requireAuth();

    $idea_id = intval($_POST['idea_id'] ?? 0);
    if ($idea_id <= 0) {
        echo ApiResponse::error('Idea ID is required', 400);
        exit();
    }

    if (!isset($_FILES['files'])) {
        echo ApiResponse::error('No files uploaded', 400);
        exit();
    }

    $contributor_id = getCurrentContributorIdForUpload($helper, $connection, $current_user);
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
    if (intval($idea['contributor_id']) !== $contributor_id) {
        echo ApiResponse::error('You can only upload attachments to your own idea', 403);
        exit();
    }

    $existing_files = $helper->fetchOne(
        "SELECT COUNT(*) as count FROM idea_attachments WHERE idea_id = ?",
        [$idea_id]
    );
    $existing_count = intval($existing_files['count'] ?? 0);

    $allowed_extensions = getSystemSettingValue($helper, 'allowed_file_types', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif']);
    if (!is_array($allowed_extensions) || empty($allowed_extensions)) {
        $allowed_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif'];
    }
    $allowed_extensions = array_map(static fn($ext) => strtolower(trim(strval($ext))), $allowed_extensions);

    $max_size_mb = intval(getSystemSettingValue($helper, 'max_upload_size_mb', 10));
    if ($max_size_mb <= 0) {
        $max_size_mb = 10;
    }
    $max_size_bytes = $max_size_mb * 1024 * 1024;

    $upload_root = realpath(__DIR__ . '/..');
    $upload_dir = $upload_root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'ideas' . DIRECTORY_SEPARATOR . $idea_id;
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true)) {
        echo ApiResponse::error('Failed to create upload directory', 500);
        exit();
    }

    $file_names = $_FILES['files']['name'];
    $file_tmp_names = $_FILES['files']['tmp_name'];
    $file_sizes = $_FILES['files']['size'];
    $file_errors = $_FILES['files']['error'];

    $is_multiple = is_array($file_names);
    $total_new_files = $is_multiple ? count($file_names) : 1;

    if (($existing_count + $total_new_files) > 5) {
        echo ApiResponse::error('Maximum 5 files allowed per idea', 400);
        exit();
    }

    $uploaded = [];
    $total_items = $is_multiple ? count($file_names) : 1;

    for ($i = 0; $i < $total_items; $i++) {
        $original_name = $is_multiple ? $file_names[$i] : $file_names;
        $tmp_name = $is_multiple ? $file_tmp_names[$i] : $file_tmp_names;
        $size = $is_multiple ? intval($file_sizes[$i]) : intval($file_sizes);
        $error = $is_multiple ? intval($file_errors[$i]) : intval($file_errors);

        if ($error !== UPLOAD_ERR_OK) {
            echo ApiResponse::error("Upload failed for file: $original_name", 400);
            exit();
        }

        if ($size > $max_size_bytes) {
            echo ApiResponse::error("File too large: $original_name (max {$max_size_mb} MB)", 400);
            exit();
        }

        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed_extensions, true)) {
            echo ApiResponse::error("Unsupported file type: $original_name", 400);
            exit();
        }

        $safe_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $original_name);
        $stored_name = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe_name;
        $destination = $upload_dir . DIRECTORY_SEPARATOR . $stored_name;

        if (!move_uploaded_file($tmp_name, $destination)) {
            echo ApiResponse::error("Failed to store file: $original_name", 500);
            exit();
        }

        $relative_path = 'uploads/ideas/' . $idea_id . '/' . $stored_name;

        $helper->execute(
            "INSERT INTO idea_attachments (idea_id, file_name, file_path, file_type, file_size, uploaded_by_contributor_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$idea_id, $original_name, $relative_path, $extension, $size, $contributor_id]
        );

        $uploaded[] = [
            'file_name' => $original_name,
            'file_path' => $relative_path,
            'file_size' => $size,
            'file_type' => $extension,
        ];
    }

    echo ApiResponse::success($uploaded, 'Files uploaded successfully', 201);
} catch (Exception $e) {
    echo ApiResponse::error('Error: ' . $e->getMessage(), 500);
}

?>
