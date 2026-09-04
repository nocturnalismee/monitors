-- Asynchronous alert notification delivery queue

CREATE TABLE IF NOT EXISTS alert_delivery_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    alert_id BIGINT NOT NULL,
    channel ENUM('email','telegram') NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error VARCHAR(500) DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX uq_alert_delivery_channel (alert_id, channel),
    INDEX idx_alert_delivery_pending (delivered_at, available_at),
    CONSTRAINT fk_alert_delivery_alert FOREIGN KEY (alert_id) REFERENCES alert_logs(id) ON DELETE CASCADE
) ENGINE=InnoDB;
