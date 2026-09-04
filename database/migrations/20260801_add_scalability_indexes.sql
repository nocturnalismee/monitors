-- Additional covering indexes for history and retention access patterns.

ALTER TABLE metrics ADD INDEX idx_metrics_server_time_id (server_id, recorded_at, id);
ALTER TABLE metrics_history ADD INDEX idx_metrics_history_server_time (server_id, recorded_at);
ALTER TABLE ping_checks ADD INDEX idx_ping_checks_monitor_time_id (monitor_id, checked_at, id);
