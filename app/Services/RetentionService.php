<?php
declare(strict_types=1);

namespace App\Services;

use Throwable;

final class RetentionService
{
    public static function retention_allowed_targets(): array
    {
        return [
            'metrics' => 'recorded_at',
            'metrics_history' => 'recorded_at',
            'disk_health_metrics' => 'recorded_at',
            'disk_health_metrics_history' => 'recorded_date',
            'service_metrics' => 'recorded_at',
            'ping_checks' => 'checked_at',
            'alert_logs' => 'created_at',
            'login_attempts' => 'attempted_at',
            'admin_audit_logs' => 'created_at',
        ];
    }

    public static function retention_table_exists(string $table): bool
    {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if ($safe === '') {
            return false;
        }
        $stmt = db()->query("SHOW TABLES LIKE '{$safe}'");
        return $stmt !== false && $stmt->fetch() !== false;
    }

    public static function retention_cutoff(int $days): string
    {
        $days = max(1, $days);
        $row = db_one("SELECT DATE_FORMAT(DATE_SUB(NOW(), INTERVAL {$days} DAY), '%Y-%m-%d %H:%i:%s') AS cutoff_at");
        return (string) ($row['cutoff_at'] ?? date('Y-m-d H:i:s', strtotime("-{$days} days")));
    }

    public static function retention_batch_delete(string $table, string $column, string $cutoff, int $batchSize = 5000): int
    {
        $allowed = self::retention_allowed_targets();
        if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
            throw new InvalidArgumentException(
                "retention_batch_delete: disallowed table/column pair: {$table}.{$column}"
            );
        }

        $batchSize = max(1, min(5000, $batchSize));
        $totalDeleted = 0;

        $excludeLatestMetric = $table === 'metrics';

        do {
            $sql = "DELETE FROM `{$table}` WHERE `{$column}` < :cutoff";
            if ($excludeLatestMetric) {
                $sql .= ' AND id NOT IN (SELECT latest_metric_id FROM servers WHERE latest_metric_id IS NOT NULL)';
            }
            $sql .= " LIMIT {$batchSize}";
            $stmt = db()->prepare($sql);
            $stmt->bindValue(':cutoff', $cutoff, \PDO::PARAM_STR);
            $stmt->execute();
            $rowsDeleted = $stmt->rowCount();
            $totalDeleted += $rowsDeleted;

            if ($rowsDeleted > 0) {
                usleep(50000);
            }
        } while ($rowsDeleted >= $batchSize);

        return $totalDeleted;
    }

    public static function metrics_is_partitioned(): bool
    {
        try {
            $row = db_one(
                "SELECT PARTITION_METHOD AS pmethod
                 FROM information_schema.PARTITIONS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metrics'
                 LIMIT 1"
            );
            $method = $row['pmethod'] ?? $row['PMETHOD'] ?? '';
            return $method !== '';
        } catch (Throwable) {
            return false;
        }
    }

    public static function metrics_partitions(): array
    {
        $rows = db_all(
            "SELECT PARTITION_NAME AS pname, PARTITION_DESCRIPTION AS pdesc
             FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metrics'
               AND PARTITION_NAME IS NOT NULL
               AND PARTITION_NAME NOT IN ('pmin', 'pmax')"
        );
        $out = [];
        foreach ($rows as $row) {
            $name = (string) ($row['pname'] ?? $row['PNAME'] ?? '');
            if ($name === '') {
                continue;
            }
            $desc = trim((string) ($row['pdesc'] ?? $row['PDESC'] ?? ''));
            $desc = trim($desc, "'");
            if (preg_match('/^\d{8}$/', $desc)) {
                $desc = date('Y-m-d', strtotime($desc));
            }
            $out[$name] = $desc;
        }
        return $out;
    }

    public static function retention_drop_metrics_partitions(string $cutoff): int
    {
        $cutoffDate = substr($cutoff, 0, 10);
        $keepRow = db_one(
            'SELECT DATE(MAX(recorded_at)) AS d
             FROM metrics
             WHERE id IN (SELECT latest_metric_id FROM servers WHERE latest_metric_id IS NOT NULL)'
        );
        $keepUntil = $keepRow['d'] ?? null;

        $dropped = 0;
        foreach (self::metrics_partitions() as $name => $bound) {
            $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
            if ($safeName === '' || $bound === '') {
                continue;
            }
            if ($keepUntil !== null && $bound > $keepUntil) {
                continue;
            }
            if ($bound > $cutoffDate) {
                continue;
            }
            db_exec("ALTER TABLE metrics DROP PARTITION {$safeName}");
            $dropped++;
        }
        return $dropped;
    }

    public static function metrics_history_bucket_delete(int $bucketSeconds, string $cutoff, int $batchSize = 5000): int
    {
        if (!in_array($bucketSeconds, [300, 3600, 86400], true)) {
            throw new InvalidArgumentException(
                "metrics_history_bucket_delete: invalid bucket_seconds: {$bucketSeconds}"
            );
        }
        $batchSize = max(1, min(5000, $batchSize));
        $totalDeleted = 0;

        do {
            $stmt = db()->prepare(
                "DELETE FROM `metrics_history`
                 WHERE `bucket_seconds` = :bs AND `recorded_at` < :cutoff
                 LIMIT {$batchSize}"
            );
            $stmt->bindValue(':bs', $bucketSeconds, \PDO::PARAM_INT);
            $stmt->bindValue(':cutoff', $cutoff, \PDO::PARAM_STR);
            $stmt->execute();
            $rowsDeleted = $stmt->rowCount();
            $totalDeleted += $rowsDeleted;

            if ($rowsDeleted > 0) {
                usleep(50000);
            }
        } while ($rowsDeleted >= $batchSize);

        return $totalDeleted;
    }

    public static function purge_ip_reputation_checks(string $cutoff, int $batchSize = 5000): int
    {
        if (!self::retention_table_exists('ip_reputation_checks')) {
            return 0;
        }
        $batchSize = max(1, min(5000, $batchSize));
        $totalDeleted = 0;

        do {
            $stmt = db()->prepare(
                "DELETE FROM `ip_reputation_checks`
                  WHERE `checked_at` < :cutoff
                  LIMIT {$batchSize}"
            );
            $stmt->bindValue(':cutoff', $cutoff, \PDO::PARAM_STR);
            $stmt->execute();
            $rowsDeleted = $stmt->rowCount();
            $totalDeleted += $rowsDeleted;

            if ($rowsDeleted > 0) {
                usleep(50000);
            }
        } while ($rowsDeleted >= $batchSize);

        return $totalDeleted;
    }

    public static function purge_dead_delivery_queue(string $cutoff, int $batchSize = 5000): int
    {
        if (!self::retention_table_exists('alert_delivery_queue')) {
            return 0;
        }
        $batchSize = max(1, min(5000, $batchSize));
        $totalDeleted = 0;

        do {
            $stmt = db()->prepare(
                "DELETE FROM `alert_delivery_queue`
                  WHERE `delivered_at` IS NULL AND `attempts` >= 5 AND `available_at` < :cutoff
                  LIMIT {$batchSize}"
            );
            $stmt->bindValue(':cutoff', $cutoff, \PDO::PARAM_STR);
            $stmt->execute();
            $rowsDeleted = $stmt->rowCount();
            $totalDeleted += $rowsDeleted;

            if ($rowsDeleted > 0) {
                usleep(50000);
            }
        } while ($rowsDeleted >= $batchSize);

        return $totalDeleted;
    }

    /**
     * @return array{rows:int,files:int}
     */
    public static function purge_export_jobs(string $cutoff, int $batchSize = 500): array
    {
        $purged = ['rows' => 0, 'files' => 0];
        if (!self::retention_table_exists('export_jobs')) {
            return $purged;
        }
        $batchSize = max(1, min(500, $batchSize));
        $exportsDir = defined('SERVMON_BASE_DIR') ? realpath(SERVMON_BASE_DIR . '/storage/exports') : false;

        do {
            $rows = db_all(
                "SELECT `id`, `file_path` FROM `export_jobs`
                  WHERE `status` IN ('completed', 'failed', 'expired') AND `created_at` < :cutoff
                  ORDER BY `id` ASC
                  LIMIT {$batchSize}",
                [':cutoff' => $cutoff]
            );
            if ($rows === []) {
                break;
            }
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int) ($row['id'] ?? 0);
                $path = (string) ($row['file_path'] ?? '');
                if ($path !== '' && $exportsDir !== false) {
                    $real = realpath($path);
                    if (is_string($real) && str_starts_with($real, $exportsDir . DIRECTORY_SEPARATOR) && is_file($real)) {
                        if (@unlink($real)) {
                            $purged['files']++;
                        }
                    }
                }
            }
            $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
            if ($ids === []) {
                break;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("DELETE FROM `export_jobs` WHERE `id` IN ({$placeholders})");
            foreach ($ids as $i => $id) {
                $stmt->bindValue($i + 1, $id, \PDO::PARAM_INT);
            }
            $stmt->execute();
            $purged['rows'] += $stmt->rowCount();
            usleep(50000);
        } while (count($rows) >= $batchSize);

        return $purged;
    }

    public static function run_core_retention_cleanup(int $days): array
    {
        $days = max(1, $days);
        $cutoff = self::retention_cutoff($days);

        $metricsRawHours = max(1, (int) setting_get('metrics_raw_hours'));
        $metricsCutoff = date('Y-m-d H:i:s', time() - $metricsRawHours * 3600);

        servmon_log_info(
            "Starting core retention cleanup: {$days} days, cutoff={$cutoff}, metrics_raw_cutoff={$metricsCutoff}",
            'retention'
        );

        $serviceMetrics = self::retention_batch_delete('service_metrics', 'recorded_at', $cutoff);

        $metrics = 0;
        if (self::metrics_is_partitioned()) {
            $metrics = self::retention_drop_metrics_partitions($metricsCutoff);
        } else {
            $metrics = self::retention_batch_delete('metrics', 'recorded_at', $metricsCutoff);
        }

        $metricsHistory = 0;
        if (self::retention_table_exists('metrics_history')) {
            $historyDays300 = max(1, (int) setting_get('metrics_5m_days'));
            $historyDays3600 = max(1, (int) setting_get('metrics_1h_days'));
            $historyDays86400 = max(1, (int) setting_get('metrics_1d_days'));
            $historyCutoff = self::retention_cutoff($historyDays86400);
            $metricsHistory += self::metrics_history_bucket_delete(300, self::retention_cutoff($historyDays300));
            $metricsHistory += self::metrics_history_bucket_delete(3600, self::retention_cutoff($historyDays3600));
            $metricsHistory += self::metrics_history_bucket_delete(86400, $historyCutoff);
        } else {
            $historyCutoff = self::retention_cutoff($days * 3);
        }

        $pingChecks = 0;
        if (self::retention_table_exists('ping_checks')) {
            $pingChecks = self::retention_batch_delete('ping_checks', 'checked_at', $cutoff);
        }

        $alerts = self::retention_batch_delete('alert_logs', 'created_at', $cutoff);
        $attempts = self::retention_batch_delete('login_attempts', 'attempted_at', $cutoff);
        $audits = self::retention_batch_delete('admin_audit_logs', 'created_at', $cutoff);

        // Unbounded auxiliary tables: export jobs (terminal states + files),
        // per-check IP reputation history, and exhausted delivery-queue rows.
        $exportPurge = self::purge_export_jobs($cutoff);
        $ipRepChecks = self::purge_ip_reputation_checks($cutoff);
        $deadQueue = self::purge_dead_delivery_queue($cutoff);

        $result = [
            'cutoff_at' => $cutoff,
            'metrics_cutoff_at' => $metricsCutoff,
            'history_cutoff_at' => $historyCutoff,
            'service_metrics_deleted' => $serviceMetrics,
            'metrics_deleted' => $metrics,
            'metrics_history_deleted' => $metricsHistory,
            'ping_checks_deleted' => $pingChecks,
            'alerts_deleted' => $alerts,
            'attempts_deleted' => $attempts,
            'audits_deleted' => $audits,
            'export_jobs_deleted' => $exportPurge['rows'],
            'export_files_deleted' => $exportPurge['files'],
            'ip_rep_checks_deleted' => $ipRepChecks,
            'dead_queue_deleted' => $deadQueue,
        ];

        servmon_log_info('Core retention cleanup complete', 'retention', $result);

        return $result;
    }

    public static function run_disk_retention_cleanup(int $days): array
    {
        $days = max(1, $days);
        $cutoff = self::retention_cutoff($days);
        $historyCutoffDate = substr(self::retention_cutoff($days * 3), 0, 10);

        servmon_log_info("Starting disk retention cleanup: {$days} days, cutoff={$cutoff}", 'retention');

        $diskMetrics = 0;
        $diskHistory = 0;

        if (self::retention_table_exists('disk_health_metrics')) {
            $diskMetrics = self::retention_batch_delete('disk_health_metrics', 'recorded_at', $cutoff);
        }
        if (self::retention_table_exists('disk_health_metrics_history')) {
            $diskHistory = self::retention_batch_delete('disk_health_metrics_history', 'recorded_date', $historyCutoffDate);
        }

        $result = [
            'cutoff_at' => $cutoff,
            'history_cutoff_at' => $historyCutoffDate,
            'disk_metrics_deleted' => $diskMetrics,
            'disk_history_deleted' => $diskHistory,
        ];

        servmon_log_info('Disk retention cleanup complete', 'retention', $result);

        return $result;
    }

    public static function run_retention_cleanup(int $days): array
    {
        return self::run_core_retention_cleanup($days);
    }
}
