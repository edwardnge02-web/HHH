<?php
// config/db_connection.php
// Database connection using PDO with error handling

class Database {
    private $host = 'localhost';
    private $db_name = 'ewsd1';
    private $user = 'root'; // XAMPP default
    private $pass = ''; // XAMPP default (empty password)
    private $charset = 'utf8mb4';
    private $pdo;
    
    public function connect() {
        $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
        
        $options = [
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        try {
            $this->pdo = new PDO($dsn, $this->user, $this->pass, $options);
            return $this->pdo;
        } catch (PDOException $e) {
            http_response_code(500);
            exit(json_encode([
                'status' => 'error',
                'message' => 'Database connection failed',
                'error' => $e->getMessage()
            ]));
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}

// Create a global database instance
$db = new Database();
$db->connect();

// Database helper class for common operations
class DatabaseHelper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // Execute prepared statement safely (prevents SQL injection)
    public function execute($query, $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception("Database error: " . $e->getMessage());
        }
    }
    
    // Fetch single row
    public function fetchOne($query, $params = []) {
        $stmt = $this->execute($query, $params);
        return $stmt->fetch();
    }
    
    // Fetch all rows
    public function fetchAll($query, $params = []) {
        $stmt = $this->execute($query, $params);
        return $stmt->fetchAll();
    }
    
    // Get last inserted ID
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    // Count rows
    public function count($query, $params = []) {
        $stmt = $this->execute($query, $params);
        $value = $stmt->fetchColumn();
        return $value !== false ? intval($value) : 0;
    }
}

?>
