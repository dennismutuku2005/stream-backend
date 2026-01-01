<?php
// config/db.php
class Database {
    private $host = "localhost";
    private $db_name = "stream";
    private $username = "root";
    private $password = "";
    private $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->conn->exec("set names utf8");
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            return null;
        }

        return $this->conn;
    }
}

// Create database instance
$database = new Database();
$db = $database->getConnection();

// If connection fails, return error
if (!$db) {
    http_response_code(503);
    echo json_encode(array("message" => "Database connection failed"));
    exit();
}
?>