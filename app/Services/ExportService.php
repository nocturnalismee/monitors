<?php
declare(strict_types=1);

namespace App\Services;

use Throwable;

final class ExportService
{
    public static function export_job_create(int $userId, string $type, string $format): int
    {
        if ($userId <= 0 || !in_array($type, ['alerts', 'metrics', 'services', 'audits'], true)) {
            throw new \InvalidArgumentException('Invalid export job parameters.');
        }
        $format = in_array($format, ['csv', 'json'], true) ? $format : 'csv';
        db_exec(
            'INSERT INTO export_jobs (user_id, export_type, export_format, filters_json, status, created_at, expires_at)
             VALUES (:user_id, :type, :format, :filters, :status, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))',
            [
                ':user_id' => $userId,
                ':type' => $type,
                ':format' => $format,
                ':filters' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':status' => 'queued',
            ]
        );
        return (int) db()->lastInsertId();
    }

    public static function export_job_dir(): string
    {
        $dir = SERVMON_BASE_DIR . '/storage/exports';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function export_job_safe_csv_value(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }

    public static function export_job_run(array $job, ?callable $heartbeat = null): void
    {
        $jobId = (int) ($job['id'] ?? 0);
        $type = (string) ($job['export_type'] ?? '');
        $format = (string) ($job['export_format'] ?? 'csv');
        if ($jobId <= 0 || !in_array($type, ['alerts', 'metrics', 'services', 'audits'], true)) {
            throw new RuntimeException('Invalid export job.');
        }

        $extension = $format === 'json' ? 'json' : 'csv';
        $fileName = 'servmon_' . $type . '_' . date('Ymd_His') . '_' . $jobId . '.' . $extension;
        $path = self::export_job_dir() . DIRECTORY_SEPARATOR . $fileName;
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create export file.');
        }

        $tableMap = [
            'alerts' => ['table' => 'alert_logs', 'time' => 'created_at', 'columns' => 'id, server_id, alert_type, severity, title, message, status, created_at'],
            'metrics' => ['table' => 'metrics', 'time' => 'recorded_at', 'columns' => 'id, server_id, uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load, network_in_bps, network_out_bps, mail_mta, mail_queue_total, recorded_at'],
            'services' => ['table' => 'service_metrics', 'time' => 'recorded_at', 'columns' => 'id, metric_id, server_id, service_group, service_key, unit_name, status, source, recorded_at'],
            'audits' => ['table' => 'admin_audit_logs', 'time' => 'created_at', 'columns' => 'id, user_id, username, action_type, action_detail, target_type, target_id, context_json, ip_address, created_at'],
        ];
        $meta = $tableMap[$type];
        $lastId = 0;
        $first = true;
        if ($format === 'json') {
            fwrite($handle, '[');
        }

        try {
            do {
                if ($heartbeat !== null) {
                    $heartbeat();
                }
                $rows = db_all(
                    'SELECT ' . $meta['columns'] . ' FROM ' . $meta['table'] .
                    ' WHERE id > :last_id ORDER BY id ASC LIMIT 1000',
                    [':last_id' => $lastId]
                );
                foreach ($rows as $row) {
                    $lastId = max($lastId, (int) ($row['id'] ?? 0));
                    if ($format === 'json') {
                        if (!$first) fwrite($handle, ',');
                        fwrite($handle, (string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    } else {
                        if ($first) {
                            fputcsv($handle, array_keys($row));
                        }
                        fputcsv($handle, array_map(self::export_job_safe_csv_value(...), $row));
                    }
                    $first = false;
                }
            } while (count($rows) === 1000);
            if ($format === 'json') fwrite($handle, ']');
            fclose($handle);
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($path);
            throw $e;
        }

        db_exec(
            'UPDATE export_jobs SET status = :status, file_path = :path, file_name = :name, completed_at = NOW() WHERE id = :id',
            [':status' => 'completed', ':path' => $path, ':name' => $fileName, ':id' => $jobId]
        );
    }
}
