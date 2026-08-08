CREATE TABLE IF NOT EXISTS job_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id      INT UNSIGNED NOT NULL,
    sender_role ENUM('admin','client') NOT NULL,
    message     TEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    INDEX idx_job_messages_job_id (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
