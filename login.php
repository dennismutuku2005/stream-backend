<?php
// login.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Include database configuration
require_once 'config/db.php';

// Get posted data
$data = json_decode(file_get_contents("php://input"));

// Validate input
if (
    empty($data->identifier) || 
    empty($data->password)
) {
    http_response_code(400);
    echo json_encode(array(
        "status" => "error",
        "message" => "Identifier (username/email/phone) and password are required"
    ));
    exit();
}

$identifier = trim($data->identifier);
$password = $data->password;

try {
    // Determine if identifier is email, phone, or username
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
    $isPhone = preg_match('/^[0-9\+\-\s\(\)]{10,20}$/', $identifier);
    
    // Build query based on identifier type
    if ($isEmail) {
        $query = "SELECT user_id, username, email, mobile_phone, password_hash, is_active 
                  FROM users 
                  WHERE email = :identifier";
    } elseif ($isPhone) {
        $query = "SELECT user_id, username, email, mobile_phone, password_hash, is_active 
                  FROM users 
                  WHERE mobile_phone = :identifier";
    } else {
        $query = "SELECT user_id, username, email, mobile_phone, password_hash, is_active 
                  FROM users 
                  WHERE username = :identifier";
    }
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(":identifier", $identifier);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        // User not found
        http_response_code(404);
        echo json_encode(array(
            "status" => "error",
            "message" => "Account not found"
        ));
        exit();
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if account is active
    if (!$user['is_active']) {
        http_response_code(403);
        echo json_encode(array(
            "status" => "error",
            "message" => "Account is deactivated"
        ));
        exit();
    }
    
    // Verify password
    if (password_verify($password, $user['password_hash'])) {
        // Password is correct
        
        // Generate session/token (you can implement JWT or sessions here)
        $token = bin2hex(random_bytes(32));
        
        // Update last login time (optional - add last_login column to users table)
        // $updateQuery = "UPDATE users SET last_login = NOW() WHERE user_id = :user_id";
        // $updateStmt = $db->prepare($updateQuery);
        // $updateStmt->bindParam(":user_id", $user['user_id']);
        // $updateStmt->execute();
        
        // Get user's devices and access points count
        $deviceQuery = "SELECT 
                        (SELECT COUNT(*) FROM mikrotik_devices WHERE owner_id = :user_id) as device_count,
                        (SELECT COUNT(*) FROM access_points WHERE owner_id = :user_id) as ap_count";
        $deviceStmt = $db->prepare($deviceQuery);
        $deviceStmt->bindParam(":user_id", $user['user_id']);
        $deviceStmt->execute();
        $counts = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get open issues count
        $issueQuery = "SELECT COUNT(*) as open_issues FROM issues WHERE owner_id = :user_id AND status = 'OPEN'";
        $issueStmt = $db->prepare($issueQuery);
        $issueStmt->bindParam(":user_id", $user['user_id']);
        $issueStmt->execute();
        $issues = $issueStmt->fetch(PDO::FETCH_ASSOC);
        
        // Prepare user data (exclude password hash)
        unset($user['password_hash']);
        
        http_response_code(200);
        echo json_encode(array(
            "status" => "success",
            "message" => "Login successful",
            "data" => array(
                "user" => $user,
                "token" => $token,
                "stats" => array(
                    "devices" => $counts['device_count'],
                    "access_points" => $counts['ap_count'],
                    "open_issues" => $issues['open_issues']
                )
            )
        ));
        
    } else {
        // Wrong password
        http_response_code(401);
        echo json_encode(array(
            "status" => "error",
            "message" => "Wrong password"
        ));
    }
    
} catch(PDOException $exception) {
    error_log("Login error: " . $exception->getMessage());
    http_response_code(500);
    echo json_encode(array(
        "status" => "error",
        "message" => "Internal server error"
    ));
}
?>