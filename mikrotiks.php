<?php
// api/mikrotiks.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database configuration
require_once '../config/db.php';

// Get the HTTP method
$method = $_SERVER['REQUEST_METHOD'];

// Get user_id from query parameters or request body
if ($method == 'GET') {
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
} else {
    $input = json_decode(file_get_contents("php://input"));
    $user_id = isset($input->user_id) ? intval($input->user_id) : 0;
}

if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(array(
        "status" => "error",
        "message" => "User ID is required"
    ));
    exit();
}

try {
    switch ($method) {
        case 'GET':
            // ==================== GET ALL MIKROTIK DEVICES ====================
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5;
            
            // Validate pagination
            $page = max(1, $page);
            $limit = max(1, min(100, $limit)); // Limit to 100 max
            $offset = ($page - 1) * $limit;
            
            // Build base query
            $baseQuery = "FROM mikrotik_devices WHERE owner_id = :user_id";
            $params = [':user_id' => $user_id];
            
            // Add search filter if provided
            if (!empty($search)) {
                $baseQuery .= " AND (name LIKE :search OR location LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }
            
            // Get total count for pagination
            $countQuery = "SELECT COUNT(*) as total " . $baseQuery;
            $countStmt = $db->prepare($countQuery);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            $totalItems = intval($totalResult['total']);
            $totalPages = ceil($totalItems / $limit);
            
            // Get devices with pagination
            $devicesQuery = "
                SELECT 
                    mikrotik_id as id,
                    name as alias,
                    location,
                    is_online,
                    last_seen,
                    created_at,
                    -- Generate IP from mikrotik_id (you should add ip_address column to your table)
                    CONCAT(
                        '192.168.', 
                        FLOOR((mikrotik_id / 256) + 1), 
                        '.', 
                        (mikrotik_id % 256)
                    ) as ip,
                    -- Generate port based on device type
                    CASE 
                        WHEN is_online = 1 AND name LIKE '%Gateway%' THEN 8291
                        WHEN is_online = 1 THEN 8728
                        ELSE 22
                    END as port,
                    -- Mock model based on ID (add model column to your table)
                    CASE 
                        WHEN mikrotik_id % 5 = 0 THEN 'CCR1009'
                        WHEN mikrotik_id % 5 = 1 THEN 'RB4011'
                        WHEN mikrotik_id % 5 = 2 THEN 'hAP ax3'
                        WHEN mikrotik_id % 5 = 3 THEN 'LHG 5'
                        ELSE 'RB5009'
                    END as model
                " . $baseQuery . "
                ORDER BY is_online DESC, name ASC
                LIMIT :limit OFFSET :offset";
            
            $devicesStmt = $db->prepare($devicesQuery);
            
            // Bind all parameters
            foreach ($params as $key => $value) {
                $devicesStmt->bindValue($key, $value);
            }
            $devicesStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $devicesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $devicesStmt->execute();
            
            $devices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Prepare response
            $response = array(
                "status" => "success",
                "data" => array(
                    "devices" => $devices,
                    "pagination" => array(
                        "current_page" => $page,
                        "items_per_page" => $limit,
                        "total_items" => $totalItems,
                        "total_pages" => $totalPages,
                        "has_next_page" => $page < $totalPages,
                        "has_prev_page" => $page > 1
                    ),
                    "search" => $search
                )
            );
            
            http_response_code(200);
            echo json_encode($response);
            break;
            
        case 'PUT':
        case 'POST':
            // ==================== UPDATE MIKROTIK DEVICE ====================
            $input = json_decode(file_get_contents("php://input"));
            
            // Validate required fields
            if (!isset($input->mikrotik_id) || !isset($input->name) || !isset($input->location)) {
                http_response_code(400);
                echo json_encode(array(
                    "status" => "error",
                    "message" => "Missing required fields: mikrotik_id, name, and location are required"
                ));
                exit();
            }
            
            $mikrotik_id = intval($input->mikrotik_id);
            $name = trim($input->name);
            $location = trim($input->location);
            
            // Validate data
            if (empty($name) || empty($location)) {
                http_response_code(400);
                echo json_encode(array(
                    "status" => "error",
                    "message" => "Name and location cannot be empty"
                ));
                exit();
            }
            
            // Check if device exists and belongs to user
            $checkQuery = "SELECT mikrotik_id FROM mikrotik_devices 
                          WHERE mikrotik_id = :mikrotik_id AND owner_id = :user_id";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->bindParam(":mikrotik_id", $mikrotik_id);
            $checkStmt->bindParam(":user_id", $user_id);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() == 0) {
                http_response_code(404);
                echo json_encode(array(
                    "status" => "error",
                    "message" => "Device not found or you don't have permission to edit it"
                ));
                exit();
            }
            
            // Check if name is already taken by another device (excluding current device)
            $nameCheckQuery = "SELECT mikrotik_id FROM mikrotik_devices 
                              WHERE name = :name 
                              AND owner_id = :user_id 
                              AND mikrotik_id != :mikrotik_id";
            $nameCheckStmt = $db->prepare($nameCheckQuery);
            $nameCheckStmt->bindParam(":name", $name);
            $nameCheckStmt->bindParam(":user_id", $user_id);
            $nameCheckStmt->bindParam(":mikrotik_id", $mikrotik_id);
            $nameCheckStmt->execute();
            
            if ($nameCheckStmt->rowCount() > 0) {
                http_response_code(409);
                echo json_encode(array(
                    "status" => "error",
                    "message" => "Device name already exists"
                ));
                exit();
            }
            
            // Update the device
            $updateQuery = "UPDATE mikrotik_devices 
                           SET name = :name, location = :location, 
                               last_seen = COALESCE(last_seen, CURRENT_TIMESTAMP)
                           WHERE mikrotik_id = :mikrotik_id AND owner_id = :user_id";
            
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(":name", $name);
            $updateStmt->bindParam(":location", $location);
            $updateStmt->bindParam(":mikrotik_id", $mikrotik_id);
            $updateStmt->bindParam(":user_id", $user_id);
            
            if ($updateStmt->execute()) {
                // Get updated device info
                $getQuery = "SELECT 
                    mikrotik_id as id,
                    name as alias,
                    location,
                    is_online,
                    last_seen,
                    CONCAT('192.168.', FLOOR((mikrotik_id / 256) + 1), '.', (mikrotik_id % 256)) as ip,
                    CASE 
                        WHEN is_online = 1 AND name LIKE '%Gateway%' THEN 8291
                        WHEN is_online = 1 THEN 8728
                        ELSE 22
                    END as port
                FROM mikrotik_devices 
                WHERE mikrotik_id = :mikrotik_id";
                
                $getStmt = $db->prepare($getQuery);
                $getStmt->bindParam(":mikrotik_id", $mikrotik_id);
                $getStmt->execute();
                $updatedDevice = $getStmt->fetch(PDO::FETCH_ASSOC);
                
                http_response_code(200);
                echo json_encode(array(
                    "status" => "success",
                    "message" => "Device updated successfully",
                    "data" => $updatedDevice
                ));
            } else {
                http_response_code(500);
                echo json_encode(array(
                    "status" => "error",
                    "message" => "Failed to update device"
                ));
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(array(
                "status" => "error",
                "message" => "Method not allowed"
            ));
    }
    
} catch(PDOException $exception) {
    error_log("Mikrotiks API error: " . $exception->getMessage());
    http_response_code(500);
    echo json_encode(array(
        "status" => "error",
        "message" => "Internal server error",
        "debug" => $exception->getMessage() // Remove in production
    ));
}
?>