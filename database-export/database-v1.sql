-- Users table for account management
CREATE TABLE users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    mobile_phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE
);

-- Mikrotik devices table with location
CREATE TABLE mikrotik_devices (
    mikrotik_id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) UNIQUE NOT NULL,
    location VARCHAR(255) NOT NULL,
    owner_id INT NOT NULL,
    is_online BOOLEAN DEFAULT FALSE,
    last_seen TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_mikrotik_owner (owner_id),
    INDEX idx_mikrotik_online (is_online),
    INDEX idx_mikrotik_name (name),
    INDEX idx_mikrotik_location (location)
);

-- Access Points (APs) table with location and online tracking
CREATE TABLE access_points (
    ap_id INT PRIMARY KEY AUTO_INCREMENT,
    mac_address VARCHAR(17) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(255) NOT NULL,
    mikrotik_id INT NOT NULL,
    owner_id INT NOT NULL,
    is_online BOOLEAN DEFAULT FALSE,
    last_seen TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mikrotik_id) REFERENCES mikrotik_devices(mikrotik_id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_aps_mac (mac_address),
    INDEX idx_aps_mikrotik (mikrotik_id),
    INDEX idx_aps_owner (owner_id),
    INDEX idx_aps_online (is_online),
    INDEX idx_aps_location (location),
    INDEX idx_aps_name (name)
);

-- Issues table - consolidated for all issues
CREATE TABLE issues (
    issue_id INT PRIMARY KEY AUTO_INCREMENT,
    issue_type ENUM('OFFLINE', 'DHCP_ERROR', 'AUTO_BLOCKED') NOT NULL,
    mikrotik_id INT,
    ap_id INT,
    owner_id INT NOT NULL,
    status ENUM('OPEN', 'RESOLVED', 'IN_PROGRESS') DEFAULT 'OPEN',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (mikrotik_id) REFERENCES mikrotik_devices(mikrotik_id) ON DELETE CASCADE,
    FOREIGN KEY (ap_id) REFERENCES access_points(ap_id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_issues_type (issue_type),
    INDEX idx_issues_status (status),
    INDEX idx_issues_owner (owner_id),
    INDEX idx_issues_mikrotik (mikrotik_id),
    INDEX idx_issues_ap (ap_id),
    INDEX idx_issues_created (created_at)
);

-- SMS logs table
CREATE TABLE sms_logs (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    issue_id INT,
    message TEXT NOT NULL,
    recipient_number VARCHAR(20) NOT NULL,
    sms_status ENUM('SENT', 'FAILED') DEFAULT 'SENT',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (issue_id) REFERENCES issues(issue_id) ON DELETE SET NULL,
    INDEX idx_sms_user (user_id),
    INDEX idx_sms_issue (issue_id),
    INDEX idx_sms_created (created_at)
);