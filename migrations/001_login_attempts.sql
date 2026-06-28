CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
