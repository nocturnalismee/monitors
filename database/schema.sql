-- NOTE: no CREATE DATABASE / USE here — import into YOUR target database
-- (the installer and CI pass the DB name explicitly; a hard-coded USE would
-- silently redirect every statement into a different schema).

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','viewer') NOT NULL DEFAULT 'viewer',
    last_login DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS servers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    url VARCHAR(255) DEFAULT NULL,
    location VARCHAR(100) DEFAULT NULL,
    provider VARCHAR(100) DEFAULT NULL,
    host VARCHAR(100) DEFAULT NULL,
    type VARCHAR(50) DEFAULT NULL,
    label VARCHAR(100) DEFAULT NULL,
    agent_mode ENUM('pull','push') NOT NULL DEFAULT 'push',
    token_hash CHAR(64) DEFAULT NULL,
    notify_email VARCHAR(255) DEFAULT NULL,
    notify_telegram VARCHAR(100) DEFAULT NULL,
    maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
    maintenance_until DATETIME DEFAULT NULL,
    push_allowed_ips TEXT DEFAULT NULL,
    latest_metric_id BIGINT DEFAULT NULL,
    last_seen_at DATETIME DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_servers_latest_metric (latest_metric_id),
    UNIQUE INDEX idx_servers_token_hash (token_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS metrics (
    id BIGINT NOT NULL AUTO_INCREMENT,
    server_id INT NOT NULL,
    uptime BIGINT NOT NULL DEFAULT 0,
    ram_total BIGINT NOT NULL DEFAULT 0,
    ram_used BIGINT NOT NULL DEFAULT 0,
    hdd_total BIGINT NOT NULL DEFAULT 0,
    hdd_used BIGINT NOT NULL DEFAULT 0,
    cpu_load FLOAT NOT NULL DEFAULT 0.00,
    network_in_bps BIGINT NOT NULL DEFAULT 0,
    network_out_bps BIGINT NOT NULL DEFAULT 0,
    mail_mta VARCHAR(20) DEFAULT NULL,
    mail_queue_total INT NOT NULL DEFAULT 0,
    panel_profile VARCHAR(20) NOT NULL DEFAULT 'generic',
    ingest_lag_ms INT DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id, recorded_at),
    INDEX idx_server_recorded (server_id, recorded_at),
    INDEX idx_metrics_server_time_id (server_id, recorded_at, id),
    INDEX idx_metrics_recorded_at (recorded_at)
) ENGINE=InnoDB
PARTITION BY RANGE COLUMNS(recorded_at) (
    PARTITION pmin VALUES LESS THAN ('2026-08-01'),
    PARTITION pmax VALUES LESS THAN MAXVALUE
);

CREATE TABLE IF NOT EXISTS metrics_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    bucket_seconds INT NOT NULL DEFAULT 300,
    recorded_at DATETIME NOT NULL,
    uptime BIGINT NOT NULL DEFAULT 0,
    ram_total BIGINT NOT NULL DEFAULT 0,
    ram_used BIGINT NOT NULL DEFAULT 0,
    hdd_total BIGINT NOT NULL DEFAULT 0,
    hdd_used BIGINT NOT NULL DEFAULT 0,
    cpu_load FLOAT NOT NULL DEFAULT 0.00,
    network_in_bps BIGINT NOT NULL DEFAULT 0,
    network_out_bps BIGINT NOT NULL DEFAULT 0,
    mail_mta VARCHAR(20) DEFAULT NULL,
    mail_queue_total INT NOT NULL DEFAULT 0,
    panel_profile VARCHAR(20) NOT NULL DEFAULT 'generic',
    CONSTRAINT fk_metrics_history_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_history_server_bucket_recorded (server_id, bucket_seconds, recorded_at),
    INDEX idx_metrics_history_server_time (server_id, recorded_at),
    INDEX idx_metrics_history_recorded_at (recorded_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    metric_id BIGINT NULL,
    server_id INT NOT NULL,
    service_group VARCHAR(32) NOT NULL,
    service_key VARCHAR(32) NOT NULL,
    unit_name VARCHAR(64) NOT NULL,
    status ENUM('up','down','unknown') NOT NULL,
    source ENUM('systemctl','service','pgrep') NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_service_metrics_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_service_metrics_server_group_key_time (server_id, service_group, service_key, recorded_at),
    INDEX idx_service_metrics_metric (metric_id),
    INDEX idx_service_metrics_recorded_at (recorded_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS server_service_states (
    server_id INT NOT NULL,
    service_group VARCHAR(32) NOT NULL,
    service_key VARCHAR(32) NOT NULL,
    unit_name VARCHAR(64) NOT NULL,
    last_status ENUM('up','down','unknown') NOT NULL,
    last_change_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (server_id, service_group, service_key),
    INDEX idx_server_service_states_status (last_status),
    CONSTRAINT fk_server_service_states_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS disk_health_states (
    server_id INT NOT NULL,
    disk_key VARCHAR(191) NOT NULL,
    device_name VARCHAR(128) DEFAULT NULL,
    model VARCHAR(191) DEFAULT NULL,
    serial VARCHAR(191) DEFAULT NULL,
    firmware VARCHAR(64) DEFAULT NULL,
    disk_type ENUM('ssd','hdd','nvme','unknown') NOT NULL DEFAULT 'unknown',
    capacity_bytes BIGINT UNSIGNED DEFAULT NULL,
    health_status ENUM('ok','warning','critical','unknown') NOT NULL DEFAULT 'unknown',
    health_score DECIMAL(5,2) DEFAULT NULL,
    temperature_c DECIMAL(5,2) DEFAULT NULL,
    power_on_time BIGINT UNSIGNED DEFAULT NULL,
    reallocated_sectors BIGINT UNSIGNED DEFAULT NULL,
    pending_sectors BIGINT UNSIGNED DEFAULT NULL,
    uncorrectable_sectors BIGINT UNSIGNED DEFAULT NULL,
    wearout_pct DECIMAL(5,2) DEFAULT NULL,
    total_written_bytes BIGINT UNSIGNED DEFAULT NULL,
    source_tool ENUM('hdsentinel','smartctl') NOT NULL DEFAULT 'smartctl',
    last_error VARCHAR(255) DEFAULT NULL,
    last_change_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (server_id, disk_key),
    CONSTRAINT fk_disk_health_states_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_disk_health_states_server_status (server_id, health_status),
    INDEX idx_disk_health_states_updated (updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS disk_health_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    disk_key VARCHAR(191) NOT NULL,
    device_name VARCHAR(128) DEFAULT NULL,
    model VARCHAR(191) DEFAULT NULL,
    serial VARCHAR(191) DEFAULT NULL,
    health_status ENUM('ok','warning','critical','unknown') NOT NULL DEFAULT 'unknown',
    health_score DECIMAL(5,2) DEFAULT NULL,
    temperature_c DECIMAL(5,2) DEFAULT NULL,
    power_on_time BIGINT UNSIGNED DEFAULT NULL,
    total_written_bytes BIGINT UNSIGNED DEFAULT NULL,
    source_tool ENUM('hdsentinel','smartctl') NOT NULL DEFAULT 'smartctl',
    raw_summary TEXT DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_disk_health_metrics_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    INDEX idx_disk_health_metrics_server_time (server_id, recorded_at),
    INDEX idx_disk_health_metrics_server_disk_time (server_id, disk_key, recorded_at),
    INDEX idx_disk_health_metrics_recorded_at (recorded_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS disk_health_metrics_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    disk_key VARCHAR(191) NOT NULL,
    recorded_date DATE NOT NULL,
    health_score_avg DECIMAL(5,2) DEFAULT NULL,
    temperature_avg DECIMAL(6,2) DEFAULT NULL,
    temperature_max DECIMAL(6,2) DEFAULT NULL,
    power_on_time_max BIGINT UNSIGNED DEFAULT NULL,
    total_written_bytes_max BIGINT UNSIGNED DEFAULT NULL,
    worst_status ENUM('ok','warning','critical','unknown') NOT NULL DEFAULT 'unknown',
    CONSTRAINT fk_disk_health_metrics_history_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_disk_health_metrics_history_unique (server_id, disk_key, recorded_date),
    INDEX idx_disk_health_metrics_history_server_date (server_id, recorded_date),
    INDEX idx_disk_health_metrics_history_recorded_date (recorded_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) DEFAULT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_ip_time (ip_address, attempted_at),
    INDEX idx_login_attempts_attempted_at (attempted_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS server_states (
    server_id INT PRIMARY KEY,
    is_down TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_server_states_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alert_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    server_id INT DEFAULT NULL,
    alert_type VARCHAR(50) NOT NULL,
    severity ENUM('info','warning','danger','success') NOT NULL DEFAULT 'info',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    context_json JSON DEFAULT NULL,
    status ENUM('active','acknowledged','resolved','silenced') NOT NULL DEFAULT 'active',
    acknowledged_by INT DEFAULT NULL,
    acknowledged_at DATETIME DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    silenced_until DATETIME DEFAULT NULL,
    sent_email TINYINT(1) NOT NULL DEFAULT 0,
    sent_telegram TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_alert_type_time (alert_type, created_at),
    INDEX idx_alert_server_time (server_id, created_at),
    INDEX idx_alert_status_time (status, created_at),
    INDEX idx_alert_logs_created_at (created_at),
    INDEX idx_alert_type_server_time (alert_type, server_id, created_at),
    CONSTRAINT fk_alert_ack_user FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_alert_logs_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    username VARCHAR(50) DEFAULT NULL,
    action_type VARCHAR(50) NOT NULL,
    action_detail VARCHAR(255) NOT NULL,
    target_type VARCHAR(50) DEFAULT NULL,
    target_id INT DEFAULT NULL,
    context_json JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_audit_user_time (user_id, created_at),
    INDEX idx_admin_audit_action_time (action_type, created_at),
    INDEX idx_admin_audit_logs_created_at (created_at),
    CONSTRAINT fk_admin_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ping_monitors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    target VARCHAR(255) NOT NULL,
    target_type ENUM('ip','domain','url') NOT NULL DEFAULT 'domain',
    check_method ENUM('icmp','http') NOT NULL DEFAULT 'icmp',
    check_interval_seconds INT NOT NULL DEFAULT 60,
    timeout_seconds INT NOT NULL DEFAULT 2,
    failure_threshold INT NOT NULL DEFAULT 2,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ping_monitors_active (active),
    INDEX idx_ping_monitors_target (target)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ping_monitor_states (
    monitor_id INT PRIMARY KEY,
    last_status ENUM('up','down','unknown') NOT NULL DEFAULT 'unknown',
    consecutive_failures INT NOT NULL DEFAULT 0,
    last_latency_ms DECIMAL(10,2) DEFAULT NULL,
    last_error VARCHAR(255) DEFAULT NULL,
    last_checked_at DATETIME DEFAULT NULL,
    last_change_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ping_monitor_states_monitor FOREIGN KEY (monitor_id) REFERENCES ping_monitors(id) ON DELETE CASCADE,
    INDEX idx_ping_monitor_states_status (last_status),
    INDEX idx_ping_monitor_states_last_checked (last_checked_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ping_checks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    monitor_id INT NOT NULL,
    status ENUM('up','down') NOT NULL,
    latency_ms DECIMAL(10,2) DEFAULT NULL,
    error_message VARCHAR(255) DEFAULT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ping_checks_monitor FOREIGN KEY (monitor_id) REFERENCES ping_monitors(id) ON DELETE CASCADE,
    INDEX idx_ping_checks_monitor_time (monitor_id, checked_at),
    INDEX idx_ping_checks_time (checked_at),
    INDEX idx_ping_checks_monitor_time_id (monitor_id, checked_at, id)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS worker_health (
    worker_name VARCHAR(50) NOT NULL PRIMARY KEY,
    last_state ENUM('running','ok','error') NOT NULL DEFAULT 'running',
    last_started_at DATETIME DEFAULT NULL,
    last_success_at DATETIME DEFAULT NULL,
    last_failure_at DATETIME DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

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
