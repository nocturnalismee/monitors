ALTER TABLE metrics           ADD INDEX idx_metrics_recorded_at (recorded_at);
ALTER TABLE service_metrics   ADD INDEX idx_service_metrics_recorded_at (recorded_at);
ALTER TABLE alert_logs        ADD INDEX idx_alert_logs_created_at (created_at);
ALTER TABLE admin_audit_logs  ADD INDEX idx_admin_audit_logs_created_at (created_at);
ALTER TABLE login_attempts    ADD INDEX idx_login_attempts_attempted_at (attempted_at);
ALTER TABLE alert_logs        ADD INDEX idx_alert_type_server_time (alert_type, server_id, created_at);
ALTER TABLE server_service_states ADD INDEX idx_server_service_states_status (last_status);
