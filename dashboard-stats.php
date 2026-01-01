<?php
// api/dashboard-stats.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Include database configuration
require_once '../config/db.php';

// Get user_id from query parameter
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(array(
        "status" => "error",
        "message" => "User ID is required"
    ));
    exit();
}

try {
    // 1. FETCH STATS FOR TOP CARDS
    $statsQuery = "
        SELECT 
            -- Total Mikrotik devices
            (SELECT COUNT(*) FROM mikrotik_devices WHERE owner_id = :user_id) as total_devices,
            
            -- Online Mikrotik devices (for Active Routers card)
            (SELECT COUNT(*) FROM mikrotik_devices WHERE owner_id = :user_id AND is_online = 1) as online_devices,
            
            -- Total Access Points (for Total APs card)
            (SELECT COUNT(*) FROM access_points WHERE owner_id = :user_id) as total_aps,
            
            -- Issues this month (for Issues This Month card)
            (SELECT COUNT(*) FROM issues 
             WHERE owner_id = :user_id 
             AND MONTH(created_at) = MONTH(CURRENT_DATE())
             AND YEAR(created_at) = YEAR(CURRENT_DATE())) as issues_this_month,
            
            -- Issues last month (for calculating change)
            (SELECT COUNT(*) FROM issues 
             WHERE owner_id = :user_id 
             AND MONTH(created_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
             AND YEAR(created_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))) as issues_last_month,
            
            -- Security Score (mock calculation)
            (SELECT 
                CASE 
                    WHEN (
                        (SELECT COUNT(*) FROM mikrotik_devices WHERE owner_id = :user_id AND is_online = 1) * 100 / 
                        GREATEST((SELECT COUNT(*) FROM mikrotik_devices WHERE owner_id = :user_id), 1) > 90
                    ) THEN 98
                    WHEN (
                        (SELECT COUNT(*) FROM mikrotik_devices WHERE owner_id = :user_id AND is_online = 1) * 100 / 
                        GREATEST((SELECT COUNT(*) FROM mikrotik_devices WHERE owner_id = :user_id), 1) > 70
                    ) THEN 85
                    ELSE 65
                END
            ) as security_score
    ";
    
    $statsStmt = $db->prepare($statsQuery);
    $statsStmt->bindParam(":user_id", $user_id);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate change for Issues This Month card
    $issues_change = "0";
    $trend = "up";
    
    if ($stats['issues_last_month'] > 0) {
        $change = $stats['issues_this_month'] - $stats['issues_last_month'];
        $issues_change = ($change > 0 ? "+" : "") . $change;
        $trend = $change > 0 ? "warning" : "down";
    } elseif ($stats['issues_this_month'] > 0) {
        $issues_change = "+" . $stats['issues_this_month'];
        $trend = "warning";
    }
    
    // 2. FETCH MIKROTIK DEVICES
    $devicesQuery = "
        SELECT 
            mikrotik_id,
            name,
            location,
            is_online,
            last_seen,
            created_at,
            -- Mock IP (replace with real data if you have ip_address column)
            CONCAT('192.168.', FLOOR(1 + RAND() * 254), '.', FLOOR(1 + RAND() * 254)) as ip_address,
            -- Mock model based on ID
            CASE 
                WHEN mikrotik_id % 5 = 0 THEN 'CCR1009'
                WHEN mikrotik_id % 5 = 1 THEN 'RB4011'
                WHEN mikrotik_id % 5 = 2 THEN 'hAP ax3'
                WHEN mikrotik_id % 5 = 3 THEN 'LHG 5'
                ELSE 'RB5009'
            END as model,
            -- Determine status
            CASE 
                WHEN is_online = 1 THEN 'online'
                WHEN DATEDIFF(NOW(), COALESCE(last_seen, created_at)) > 7 THEN 'offline'
                ELSE 'warning'
            END as status
        FROM mikrotik_devices 
        WHERE owner_id = :user_id
        ORDER BY 
            is_online DESC,
            name ASC
        LIMIT 10
    ";
    
    $devicesStmt = $db->prepare($devicesQuery);
    $devicesStmt->bindParam(":user_id", $user_id);
    $devicesStmt->execute();
    $devices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare response
    $response = array(
        "status" => "success",
        "data" => array(
            // Stats for the 4 metric cards
            "stats" => array(
                "active_routers" => array(
                    "title" => "Active Routers",
                    "value" => intval($stats['online_devices']),
                    "change" => intval($stats['online_devices']) . "/" . intval($stats['total_devices']),
                    "trend" => "up"
                ),
                "total_aps" => array(
                    "title" => "Total APs",
                    "value" => intval($stats['total_aps']),
                    "change" => "+" . intval($stats['total_aps'] * 0.1), // Mock 10% increase
                    "trend" => "up"
                ),
                "issues_this_month" => array(
                    "title" => "Issues This Month",
                    "value" => intval($stats['issues_this_month']),
                    "change" => $issues_change,
                    "trend" => $trend
                ),
                "security_score" => array(
                    "title" => "Security Score",
                    "value" => intval($stats['security_score']),
                    "change" => $stats['security_score'] > 90 ? "High" : "Medium",
                    "trend" => $stats['security_score'] > 90 ? "up" : "down"
                )
            ),
            // Devices for the router list
            "devices" => $devices
        )
    );
    
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch(PDOException $exception) {
    error_log("Dashboard API error: " . $exception->getMessage());
    http_response_code(500);
    echo json_encode(array(
        "status" => "error",
        "message" => "Internal server error"
    ));
}
?>