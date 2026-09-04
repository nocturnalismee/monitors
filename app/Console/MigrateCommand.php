<?php
declare(strict_types=1);

namespace App\Console;

use PDO;

final class MigrateCommand
{
    public static function run(array $args = []): int
    {
        if (php_sapi_name() !== 'cli') {
            http_response_code(403);
            echo 'CLI only.';
            return 1;
        }

        $localPath = SERVMON_BASE_DIR . '/config/local.php';
        if (!is_file($localPath)) {
            fwrite(STDERR, "Error: config/local.php not found. Run install.php first.\n");
            return 1;
        }
        if (!is_readable($localPath)) {
            fwrite(STDERR, "Error: config/local.php exists but is not readable (permission denied). Fix with: chmod 644 {$localPath} && chown www:www {$localPath} (adjust user as needed)\n");
            return 1;
        }

        try {
            $config = require $localPath;
        } catch (\Throwable $e) {
            fwrite(STDERR, "Error: Failed to load config/local.php: " . $e->getMessage() . "\n");
            return 1;
        }
        if (!is_array($config)) {
            fwrite(STDERR, "Error: config/local.php is invalid.\n");
            return 1;
        }

        $dbName = (string) ($config['DB_NAME'] ?? '');
        $dbUser = (string) ($config['DB_USER'] ?? '');
        if ($dbName === '' || $dbUser === '') {
            fwrite(STDERR, "Error: DB_NAME and DB_USER must be set in local.php.\n");
            return 1;
        }

        try {
            $pdo = \App\Repositories\Database::connection();
        } catch (\Throwable $e) {
            fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
            return 1;
        }

        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    migration_name VARCHAR(255) NOT NULL UNIQUE,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    checksum_sha256 CHAR(64) NOT NULL
                ) ENGINE=InnoDB'
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, "Failed to ensure schema_migrations table: " . $e->getMessage() . "\n");
            return 1;
        }

        $appliedMap = [];
        try {
            $rows = $pdo->query('SELECT migration_name, checksum_sha256 FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Failed to read schema_migrations: " . $e->getMessage() . "\n");
            return 1;
        }
        foreach ($rows as $row) {
            $name = (string) ($row['migration_name'] ?? '');
            if ($name !== '') {
                $appliedMap[$name] = (string) ($row['checksum_sha256'] ?? '');
            }
        }

        $migrationDir = SERVMON_BASE_DIR . '/database/migrations';
        if (!is_dir($migrationDir)) {
            echo "No migrations directory found.\n";
            return 0;
        }

        $files = glob($migrationDir . '/*.sql');
        if ($files === false || empty($files)) {
            echo "No migration files found.\n";
            return 0;
        }
        natsort($files);
        $files = array_values($files);

        $applied = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $migrationName = basename($file);
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                fwrite(STDERR, "Error: Failed to read checksum for {$migrationName}\n");
                return 1;
            }

            if (isset($appliedMap[$migrationName])) {
                if ($appliedMap[$migrationName] !== $checksum) {
                    fwrite(STDERR, "Error: Checksum changed for already-applied migration: {$migrationName}\n");
                    return 1;
                }
                $skipped++;
                continue;
            }

            echo "Applying: {$migrationName} ... ";

            $sql = file_get_contents($file);
            if ($sql === false) {
                fwrite(STDERR, "\nError: Failed to read {$migrationName}\n");
                return 1;
            }

            $statements = self::splitSqlStatements($sql);
            foreach ($statements as $statement) {
                try {
                    self::executeMigrationStatement($pdo, $statement);
                } catch (\Throwable $e) {
                    $preview = substr(preg_replace('/\s+/', ' ', trim($statement)) ?? '', 0, 120);
                    fwrite(STDERR, "\nFailed: " . $e->getMessage() . "\nSQL: {$preview}\n");
                    return 1;
                }
            }

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO schema_migrations (migration_name, checksum_sha256, applied_at)
                     VALUES (:name, :checksum, NOW())'
                );
                $stmt->execute([':name' => $migrationName, ':checksum' => $checksum]);
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nFailed to record migration {$migrationName}: " . $e->getMessage() . "\n");
                return 1;
            }
            echo "OK\n";
            $applied++;
        }

        echo "\nMigration complete: {$applied} applied, {$skipped} already up-to-date, " . count($files) . " total.\n";
        return 0;
    }

    private static function splitSqlStatements(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        $lines = preg_split('/\R/', $sql) ?: [];
        $buffer = '';
        $statements = [];
        $insideBlockComment = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($insideBlockComment) {
                if (str_contains($trimmed, '*/')) {
                    $insideBlockComment = false;
                }
                continue;
            }

            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            if (str_starts_with($trimmed, '/*')) {
                if (!str_contains($trimmed, '*/')) {
                    $insideBlockComment = true;
                }
                continue;
            }

            if (preg_match('/^(CREATE DATABASE|USE)\s+/i', $trimmed) === 1) {
                continue;
            }

            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($line), ';')) {
                $stmt = trim($buffer);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $buffer = '';
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    private static function executeMigrationStatement(PDO $pdo, string $statement): void
    {
        $stmt = $pdo->prepare($statement);
        $stmt->execute();

        do {
            try {
                $stmt->fetchAll(PDO::FETCH_NUM);
            } catch (\Throwable) {
            }
        } while ($stmt->nextRowset());

        $stmt->closeCursor();
    }
}
