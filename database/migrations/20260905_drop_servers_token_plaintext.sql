-- Remove plaintext server tokens: all auth now uses token_hash (SHA-256 hex).
-- Defensive backfill for any legacy row written without a hash.

UPDATE servers
SET token_hash = SHA2(token, 256)
WHERE token_hash IS NULL AND token IS NOT NULL AND token <> '';

SET @has_token := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'servers' AND column_name = 'token'
);
SET @sql := IF(
    @has_token > 0,
    'ALTER TABLE servers DROP COLUMN token',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
