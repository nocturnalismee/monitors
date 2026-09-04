-- Store hashes for newly generated server tokens while keeping legacy tokens
-- readable during the migration period.

SET @has_token_hash := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'servers' AND column_name = 'token_hash'
);
SET @sql := IF(
    @has_token_hash = 0,
    'ALTER TABLE servers ADD COLUMN token_hash CHAR(64) DEFAULT NULL AFTER token',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE servers MODIFY token VARCHAR(64) DEFAULT NULL;

UPDATE servers
SET token_hash = SHA2(token, 256)
WHERE token_hash IS NULL AND token IS NOT NULL AND token <> '';

SET @has_token_hash_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'servers' AND index_name = 'idx_servers_token_hash'
);
SET @sql := IF(
    @has_token_hash_index = 0,
    'ALTER TABLE servers ADD UNIQUE INDEX idx_servers_token_hash (token_hash)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
