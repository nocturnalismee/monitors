-- Asynchronous export jobs for large datasets.

CREATE TABLE IF NOT EXISTS export_jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    export_type ENUM('alerts','metrics','services','audits') NOT NULL,
    export_format ENUM('csv','json') NOT NULL DEFAULT 'csv',
    filters_json JSON DEFAULT NULL,
    status ENUM('queued','running','completed','failed','expired') NOT NULL DEFAULT 'queued',
    file_path VARCHAR(500) DEFAULT NULL,
    file_name VARCHAR(255) DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    INDEX idx_export_jobs_user_time (user_id, created_at),
    INDEX idx_export_jobs_status_time (status, created_at),
    CONSTRAINT fk_export_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
