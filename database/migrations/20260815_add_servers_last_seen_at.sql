-- Add last_seen_at column to servers for offline detection
-- independent of raw metrics retention (real-time fix: server offline >24h).
ALTER TABLE servers ADD COLUMN last_seen_at DATETIME DEFAULT NULL AFTER latest_metric_id;
