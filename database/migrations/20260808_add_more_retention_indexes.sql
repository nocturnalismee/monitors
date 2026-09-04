-- Standalone retention indexes for tables whose only time-based index was
-- composite with server_id as the leading column (full-scan batch DELETE).

ALTER TABLE metrics_history ADD INDEX idx_metrics_history_recorded_at (recorded_at);
ALTER TABLE disk_health_metrics ADD INDEX idx_disk_health_metrics_recorded_at (recorded_at);
ALTER TABLE disk_health_metrics_history ADD INDEX idx_disk_health_metrics_history_recorded_date (recorded_date);
