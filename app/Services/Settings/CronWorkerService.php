<?php
declare(strict_types=1);

namespace App\Services\Settings;

final class CronWorkerService
{
    public function recommendedCron(string $baseDir): array
    {
        $php = '/usr/bin/php';
        $base = $baseDir;
        if (!is_string($base) || $base === '') {
            $base = dirname($baseDir);
        }
        $base = rtrim(str_replace('\\', '/', $base), '/');
        return [
            "* * * * * {$php} {$base}/workers/alert-check.php >/dev/null 2>&1",
            "* * * * * {$php} {$base}/workers/alert-delivery.php >/dev/null 2>&1",
            "* * * * * {$php} {$base}/workers/export-worker.php >/dev/null 2>&1",
            "* * * * * {$php} {$base}/workers/ping-check.php >/dev/null 2>&1",
            "*/5 * * * * {$php} {$base}/workers/ip-reputation-check.php >/dev/null 2>&1",
            "30 2 * * * {$php} {$base}/workers/disk-cleanup.php >/dev/null 2>&1",
            "0 2 * * * {$php} {$base}/workers/disk-rollup.php >/dev/null 2>&1",
            "0 2 * * * {$php} {$base}/workers/rollup.php >/dev/null 2>&1",
            "0 3 * * * {$php} {$base}/workers/cleanup.php >/dev/null 2>&1",
            "30 0 * * * {$php} {$base}/workers/partition-maintain.php >/dev/null 2>&1",
        ];
    }

    public function workerStatuses(): array
    {
        return [
            'alert_check' => worker_health_status('alert_check', 180),
            'alert_delivery' => worker_health_status('alert_delivery', 300),
            'export_worker' => worker_health_status('export_worker', 300),
            'ping_check' => worker_health_status('ping_check', 300),
            'ip_reputation_check' => worker_health_status('ip_reputation_check', 21600),
            'disk_retention_cleanup' => worker_health_status('disk_retention_cleanup', 129600),
            'retention_cleanup' => worker_health_status('retention_cleanup', 129600),
            'rollup_metrics' => worker_health_status('rollup_metrics', 129600),
            'disk_history_rollup' => worker_health_status('disk_history_rollup', 129600),
            'partition_maintain' => worker_health_status('partition_maintain', 129600),
        ];
    }
}
