<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    /** @var array<string, bool> */
    private static array $columnCache = [];

    private static ?bool $hasLatestMetricColumn = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                self::setSessionTimeZone(self::$pdo);
                return self::$pdo;
            } catch (PDOException $e) {
                if ($attempt === $maxRetries) {
                    throw $e;
                }
                usleep(200000);
            }
        }

        throw new RuntimeException('Database connection failed after retries.');
    }

    private static function setSessionTimeZone(PDO $pdo): void
    {
        if (!defined('APP_TZ') || APP_TZ === '') {
            return;
        }
        try {
            $tz = new \DateTimeZone(APP_TZ);
            $offset = $tz->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));
            $sign = $offset >= 0 ? '+' : '-';
            $offset = abs($offset);
            $offsetStr = sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
            $pdo->exec("SET time_zone = '" . $offsetStr . "'");
        } catch (Throwable $e) {
            error_log('Database timezone set failed: ' . $e->getMessage());
        }
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function exec(string $sql, array $params = []): bool
    {
        $stmt = self::connection()->prepare($sql);
        return $stmt->execute($params);
    }

    public static function execCount(string $sql, array $params = []): int
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset(self::$columnCache[$key])) {
            return self::$columnCache[$key];
        }
        try {
            $row = self::one(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table
                   AND column_name = :column
                 LIMIT 1',
                [':table' => $table, ':column' => $column]
            );
            self::$columnCache[$key] = $row !== null;
        } catch (Throwable) {
            self::$columnCache[$key] = false;
        }
        return self::$columnCache[$key];
    }

    public static function latestMetricJoinSql(string $serverAlias = 's', string $metricAlias = 'm'): string
    {
        $s = preg_replace('/[^a-zA-Z0-9_]/', '', $serverAlias) ?: 's';
        $m = preg_replace('/[^a-zA-Z0-9_]/', '', $metricAlias) ?: 'm';

        if (self::$hasLatestMetricColumn === null) {
            try {
                $col = self::one(
                    "SELECT 1 FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = 'servers'
                       AND column_name = 'latest_metric_id'
                     LIMIT 1"
                );
                self::$hasLatestMetricColumn = ($col !== null);
            } catch (Throwable) {
                self::$hasLatestMetricColumn = false;
            }
        }

        if (self::$hasLatestMetricColumn) {
            return " LEFT JOIN metrics {$m} ON {$m}.id = {$s}.latest_metric_id";
        }

        return "
         LEFT JOIN (
             SELECT ranked.server_id, ranked.id
             FROM (
                 SELECT id, server_id,
                        ROW_NUMBER() OVER (PARTITION BY server_id ORDER BY recorded_at DESC, id DESC) AS rn
                 FROM metrics
             ) ranked
             WHERE ranked.rn = 1
         ) lm ON lm.server_id = {$s}.id
         LEFT JOIN metrics {$m} ON {$m}.id = lm.id";
    }
}
