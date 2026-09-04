-- Persist agent-to-server ingest lag (ms) measured from the signed
-- X-Server-Timestamp header at push time. NULL for unsigned pushes.

SET @has_ingest_lag := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'metrics' AND column_name = 'ingest_lag_ms'
);
SET @sql := IF(
    @has_ingest_lag = 0,
    'ALTER TABLE metrics ADD COLUMN ingest_lag_ms INT DEFAULT NULL AFTER panel_profile',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
