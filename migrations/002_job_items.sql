CREATE TABLE IF NOT EXISTS job_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id     INT UNSIGNED NOT NULL,
    file_id    INT UNSIGNED NULL,
    name       VARCHAR(200) NOT NULL,
    quantity   SMALLINT     NOT NULL DEFAULT 1,
    status     ENUM('pending','printing','done','failed') NOT NULL DEFAULT 'pending',
    notes      TEXT         NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (job_id)  REFERENCES jobs(id)       ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES job_files(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
