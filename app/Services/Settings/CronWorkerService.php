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
            // Hourly so a single missed run never leaves raw data that is
            // about to be pruned without a rollup substitute.
            "15 * * * * {$php} {$base}/workers/rollup.php >/dev/null 2>&1",
            "0 3 * * * {$php} {$base}/workers/cleanup.php >/dev/null 2>&1",
            "15 1 * * * {$php} {$base}/workers/backup.php >/dev/null 2>&1",
            "30 0 * * * {$php} {$base}/workers/partition-maintain.php >/dev/null 2>&1",
        ];
    }

    public const TTL = [
        'alert_check' => 180,
        'alert_delivery' => 300,
        'export_worker' => 300,
        'ping_check' => 300,
        'ip_reputation_check' => 21600,
        'disk_retention_cleanup' => 129600,
        'retention_cleanup' => 129600,
        'rollup_metrics' => 5400,
        'disk_history_rollup' => 129600,
        'partition_maintain' => 129600,
        'db_backup' => 129600,
    ];

    public function workerStatuses(): array
    {
        return worker_health_statuses([
            'alert_check' => self::TTL['alert_check'],
            'alert_delivery' => self::TTL['alert_delivery'],
            'export_worker' => self::TTL['export_worker'],
            'ping_check' => self::TTL['ping_check'],
            'ip_reputation_check' => self::TTL['ip_reputation_check'],
            'disk_retention_cleanup' => self::TTL['disk_retention_cleanup'],
            'retention_cleanup' => self::TTL['retention_cleanup'],
            'rollup_metrics' => self::TTL['rollup_metrics'],
            'disk_history_rollup' => self::TTL['disk_history_rollup'],
            'partition_maintain' => self::TTL['partition_maintain'],
            'db_backup' => self::TTL['db_backup'],
        ]);
    }

    /**
     * Cron command lines indexed by worker script name (without .php), so
     * views can quote the same schedule the install docs recommend instead of
     * hardcoding a second, drifting copy.
     *
     * @return array<string,string>
     */
    public function cronCommands(string $baseDir): array
    {
        $map = [];
        foreach ($this->recommendedCron($baseDir) as $line) {
            if (preg_match('~/workers/([a-z0-9-]+)\.php~i', $line, $m) === 1) {
                $map[$m[1]] = $line;
            }
        }
        return $map;
    }
}
