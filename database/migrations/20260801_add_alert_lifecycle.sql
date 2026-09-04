-- Alert lifecycle state for acknowledgement, resolution, and silencing.

ALTER TABLE alert_logs
    ADD COLUMN status ENUM('active','acknowledged','resolved','silenced') NOT NULL DEFAULT 'active' AFTER context_json,
    ADD COLUMN acknowledged_by INT DEFAULT NULL AFTER status,
    ADD COLUMN acknowledged_at DATETIME DEFAULT NULL AFTER acknowledged_by,
    ADD COLUMN resolved_at DATETIME DEFAULT NULL AFTER acknowledged_at,
    ADD COLUMN silenced_until DATETIME DEFAULT NULL AFTER resolved_at,
    ADD INDEX idx_alert_status_time (status, created_at),
    ADD CONSTRAINT fk_alert_ack_user FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL;
