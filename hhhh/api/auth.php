<?php
// api/auth.php
// Authentication and authorization handling

class Auth {
    private $pdo;
    private $secret_key = 'your-secret-key-change-this-in-production'; // Change in production!
    private $token_expiry = 86400; // 24 hours
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Hash password using bcrypt (PHP 5.5+)
     */
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }
    
    /**
     * Verify password against hash
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Login admin user and create session token
     */
    public function login($username, $password) {
        try {
            // Fetch admin user
            $stmt = $this->pdo->prepare("
                SELECT 
                    au.id, 
                    au.username, 
                    au.email, 
                    au.password_hash, 
                    au.full_name,
                    au.last_login,
                    COALESCE(NULLIF(au.department, ''), qm.department, qcd.department) AS department,
                    COALESCE(
                        NULLIF(au.role, ''),
                        CASE 
                            WHEN qm.admin_user_id IS NOT NULL THEN 'QAManager'
                            WHEN qcd.coordinator_id IS NOT NULL THEN 'QACoordinator'
                            ELSE 'Admin'
                        END
                    ) AS role
                FROM admin_users au
                LEFT JOIN qa_managers qm ON qm.admin_user_id = au.id AND qm.is_active = 1
                LEFT JOIN (
                    SELECT coordinator_id, MIN(department) AS department
                    FROM qa_coordinator_departments
                    WHERE is_active = 1
                    GROUP BY coordinator_id
                ) qcd ON qcd.coordinator_id = au.id
                WHERE (au.username = ? OR au.email = ?) AND au.is_active = 1
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return [
                    'status' => 'error',
                    'message' => 'Invalid username or password'
                ];
            }
            
            // Verify password
            if (!$this->verifyPassword($password, $user['password_hash'])) {
                return [
                    'status' => 'error',
                    'message' => 'Invalid username or password'
                ];
            }
            
            // Create session token
            $token = $this->generateToken();
            $session_timeout_seconds = $this->getSessionTimeoutSeconds();
            $expires_at = date('Y-m-d H:i:s', time() + $session_timeout_seconds);
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            // Save session to database
            $stmt = $this->pdo->prepare("INSERT INTO user_sessions (admin_id, session_token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user['id'], $token, $ip_address, $user_agent, $expires_at]);
            
            // Update last login
            $stmt = $this->pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            return [
                'status' => 'success',
                'message' => 'Login successful',
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'full_name' => $user['full_name'],
                    'last_login' => $user['last_login'] ?? null,
                    'role' => $user['role'],
                    'department' => $user['department']
                ]
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Login failed: ' . $e->getMessage()
            ];
        }
    }

    private function getSessionTimeoutSeconds() {
        try {
            $table_check = $this->pdo->prepare(
                "SELECT COUNT(*) as count
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_settings'"
            );
            $table_check->execute();
            $has_table = intval(($table_check->fetch(PDO::FETCH_ASSOC)['count'] ?? 0)) > 0;
            if (!$has_table) {
                return $this->token_expiry;
            }

            $stmt = $this->pdo->prepare(
                "SELECT setting_value
                 FROM system_settings
                 WHERE setting_key = 'session_timeout_minutes'
                 LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $minutes = intval($row['setting_value'] ?? 0);
            if ($minutes <= 0) {
                return $this->token_expiry;
            }
            return $minutes * 60;
        } catch (Exception $e) {
            return $this->token_expiry;
        }
    }
    
    /**
     * Verify session token
     */
    public function verifyToken($token) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    us.admin_id, 
                    us.id as session_id, 
                    au.username, 
                    au.email, 
                    au.full_name,
                    COALESCE(NULLIF(au.department, ''), qm.department, qcd.department) AS department,
                    COALESCE(
                        NULLIF(au.role, ''),
                        CASE 
                            WHEN qm.admin_user_id IS NOT NULL THEN 'QAManager'
                            WHEN qcd.coordinator_id IS NOT NULL THEN 'QACoordinator'
                            ELSE 'Admin'
                        END
                    ) AS role
                FROM user_sessions us 
                JOIN admin_users au ON us.admin_id = au.id 
                LEFT JOIN qa_managers qm ON qm.admin_user_id = au.id AND qm.is_active = 1
                LEFT JOIN (
                    SELECT coordinator_id, MIN(department) AS department
                    FROM qa_coordinator_departments
                    WHERE is_active = 1
                    GROUP BY coordinator_id
                ) qcd ON qcd.coordinator_id = au.id
                WHERE us.session_token = ? 
                AND us.expires_at > NOW() 
                AND au.is_active = 1
            ");
            $stmt->execute([$token]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $session ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Logout user and invalidate session
     */
    public function logout($token) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM user_sessions WHERE session_token = ?");
            return $stmt->execute([$token]);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Generate random token
     */
    private function generateToken() {
        return bin2hex(random_bytes(32));
    }
    
    /**
     * Get current user from request
     */
    public function getCurrentUser() {
        $token = $this->getTokenFromRequest();
        if (!$token) {
            return null;
        }
        return $this->verifyToken($token);
    }
    
    /**
     * Extract token from Authorization header
     */
    private function getTokenFromRequest() {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $matches = [];
            if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    /**
     * Check if user is authenticated (for middleware)
     */
    public function isAuthenticated() {
        return $this->getCurrentUser() !== null;
    }
    
    /**
     * Require authentication - call this at the start of protected endpoints
     */
    public function requireAuth() {
        if (!$this->isAuthenticated()) {
            http_response_code(401);
            die(json_encode([
                'status' => 'error',
                'message' => 'Unauthorized - Token required or expired'
            ]));
        }
    }
}

// API Response helper class
class ApiResponse {
    public static function success($data = null, $message = 'Success', $code = 200) {
        http_response_code($code);
        return json_encode([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ]);
    }
    
    public static function error($message = 'Error', $code = 400, $errors = null) {
        http_response_code($code);
        return json_encode([
            'status' => 'error',
            'message' => $message,
            'errors' => $errors
        ]);
    }
    
    public static function paginated($data, $total, $page, $per_page, $message = 'Success') {
        $total_pages = ceil($total / $per_page);
        http_response_code(200);
        return json_encode([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => $total_pages
            ]
        ]);
    }
}

?>
