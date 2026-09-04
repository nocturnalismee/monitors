-- Repair migration: ensure IP reputation tables exist on installations where
-- the original migration was marked applied before schema parity was fixed.

CREATE TABLE IF NOT EXISTS ip_reputation_targets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    label VARCHAR(120) DEFAULT NULL,
    server_id INT DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    check_interval_hours INT NOT NULL DEFAULT 6,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_ip_rep_targets_ip (ip_address),
    INDEX idx_ip_rep_targets_active (active),
    INDEX idx_ip_rep_targets_server (server_id),
    CONSTRAINT fk_ip_rep_targets_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ip_reputation_states (
    target_id INT NOT NULL PRIMARY KEY,
    overall_status ENUM('clean','listed','unknown') NOT NULL DEFAULT 'unknown',
    listed_count INT NOT NULL DEFAULT 0,
    total_checked INT NOT NULL DEFAULT 0,
    listed_on JSON DEFAULT NULL,
    provider_results JSON DEFAULT NULL,
    last_checked_at DATETIME DEFAULT NULL,
    last_change_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ip_rep_states_target FOREIGN KEY (target_id) REFERENCES ip_reputation_targets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ip_reputation_checks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    target_id INT NOT NULL,
    overall_status ENUM('clean','listed','unknown') NOT NULL DEFAULT 'unknown',
    listed_count INT NOT NULL DEFAULT 0,
    total_checked INT NOT NULL DEFAULT 0,
    listed_on JSON DEFAULT NULL,
    provider_results JSON DEFAULT NULL,
    check_duration_ms INT DEFAULT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ip_rep_checks_target FOREIGN KEY (target_id) REFERENCES ip_reputation_targets(id) ON DELETE CASCADE,
    INDEX idx_ip_rep_checks_target_time (target_id, checked_at),
    INDEX idx_ip_rep_checks_time (checked_at)
) ENGINE=InnoDB;
