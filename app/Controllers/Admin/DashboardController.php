<?php
declare(strict_types=1);

namespace App\Controllers\Admin {
    use App\Http\Request;
    use App\Http\Response;
    use App\Services\ServerListService;
    use App\Support\View;

    final class DashboardController
    {
        public function index(Request $request): Response
        {
            require_login();

            $summary = db_one(
                'SELECT
                    (SELECT COUNT(*) FROM servers) AS total_servers,
                    (SELECT COUNT(*) FROM servers WHERE active = 1) AS active_servers'
            );

            $latestMetricJoin = latest_metric_join_sql('s', 'm');
            $totalRow = db_one('SELECT COUNT(*) AS cnt FROM servers');
            $totalServers = (int) ($totalRow['cnt'] ?? 0);
            $dashboardLimit = 50;
            $rows = db_all(
                'SELECT s.id, s.name, s.location, s.type, s.active,
                        COALESCE(s.last_seen_at, m.recorded_at) AS last_seen, m.uptime, m.cpu_load, m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.network_in_bps, m.network_out_bps, m.mail_mta, m.mail_queue_total, m.panel_profile
                 FROM servers s' . $latestMetricJoin . '
                 ORDER BY s.name ASC
                  LIMIT ' . $dashboardLimit
            );
            $showAllLink = $totalServers > $dashboardLimit;
            $thresholds = ServerListService::thresholds();
            $statusOnlineMinutes = $thresholds['onlineMinutes'];
            $cpuWarnThreshold = $thresholds['cpuWarn'];
            $cpuCriticalThreshold = $thresholds['cpuCritical'];
            $rows = ServerListService::sortBySeverity($rows, $cpuWarnThreshold, $cpuCriticalThreshold);
            $serverIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0));
            $serviceSummaryByServer = ServerListService::serviceSummaryMap($serverIds);
            // Reliability overview (queue/SLO) lives on Settings → Ops; /api/health keeps the JSON contract.
            $alertWorkerHealth = worker_health_status('alert_check', \App\Services\Settings\CronWorkerService::TTL['alert_check']);
            $pingWorkerHealth = worker_health_status('ping_check', \App\Services\Settings\CronWorkerService::TTL['ping_check']);
            $diskRollupWorkerHealth = worker_health_status('disk_history_rollup', \App\Services\Settings\CronWorkerService::TTL['disk_history_rollup']);
            $retentionWorkerHealth = worker_health_status('retention_cleanup', \App\Services\Settings\CronWorkerService::TTL['retention_cleanup']);
            $ipRepWorkerHealth = worker_health_status('ip_reputation_check', \App\Services\Settings\CronWorkerService::TTL['ip_reputation_check']);
            $rollupWorkerHealth = worker_health_status('rollup_metrics', \App\Services\Settings\CronWorkerService::TTL['rollup_metrics']);
            $diskCleanupWorkerHealth = worker_health_status('disk_retention_cleanup', \App\Services\Settings\CronWorkerService::TTL['disk_retention_cleanup']);
            $partitionMaintainWorkerHealth = worker_health_status('partition_maintain', \App\Services\Settings\CronWorkerService::TTL['partition_maintain']);
            $projectRoot = realpath(MONITORS_BASE_DIR);
            if (!is_string($projectRoot) || $projectRoot === '') {
                $projectRoot = dirname(MONITORS_BASE_DIR);
            }
            $workersRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
            $alertCronCmd = '* * * * * /usr/bin/php ' . $workersRoot . '/workers/alert-check.php >/dev/null 2>&1';
            $pingCronCmd = '* * * * * /usr/bin/php ' . $workersRoot . '/workers/ping-check.php >/dev/null 2>&1';
            $diskRollupCronCmd = '0 2 * * * /usr/bin/php ' . $workersRoot . '/workers/disk-rollup.php >/dev/null 2>&1';
            $retentionCronCmd = '0 3 * * * /usr/bin/php ' . $workersRoot . '/workers/cleanup.php >/dev/null 2>&1';
            $ipRepCronCmd = '*/5 * * * * /usr/bin/php ' . $workersRoot . '/workers/ip-reputation-check.php >/dev/null 2>&1';
            $rollupCronCmd = '0 2 * * * /usr/bin/php ' . $workersRoot . '/workers/rollup.php >/dev/null 2>&1';
            $diskCleanupCronCmd = '30 2 * * * /usr/bin/php ' . $workersRoot . '/workers/disk-cleanup.php >/dev/null 2>&1';
            $partitionMaintainCronCmd = '30 0 * * * /usr/bin/php ' . $workersRoot . '/workers/partition-maintain.php >/dev/null 2>&1';

            $counts = ServerListService::countByStatus($rows, $statusOnlineMinutes);
            $online = $counts['online'];
            $down = $counts['down'];
            $pending = $counts['pending'];

            return Response::html(View::render('admin/dashboard', [
                'title' => APP_NAME . ' - Admin Dashboard',
                'activeNav' => 'dashboard',
                'summary' => $summary,
                'rows' => $rows,
                'statusOnlineMinutes' => $statusOnlineMinutes,
                'cpuWarnThreshold' => $cpuWarnThreshold,
                'cpuCriticalThreshold' => $cpuCriticalThreshold,
                'serviceSummaryByServer' => $serviceSummaryByServer,
                'online' => $online,
                'down' => $down,
                'pending' => $pending,
                'showAllLink' => $showAllLink,
                'alertWorkerHealth' => $alertWorkerHealth,
                'pingWorkerHealth' => $pingWorkerHealth,
                'diskRollupWorkerHealth' => $diskRollupWorkerHealth,
                'retentionWorkerHealth' => $retentionWorkerHealth,
                'ipRepWorkerHealth' => $ipRepWorkerHealth,
                'rollupWorkerHealth' => $rollupWorkerHealth,
                'diskCleanupWorkerHealth' => $diskCleanupWorkerHealth,
                'partitionMaintainWorkerHealth' => $partitionMaintainWorkerHealth,
                'alertCronCmd' => $alertCronCmd,
                'pingCronCmd' => $pingCronCmd,
                'diskRollupCronCmd' => $diskRollupCronCmd,
                'retentionCronCmd' => $retentionCronCmd,
                'ipRepCronCmd' => $ipRepCronCmd,
                'rollupCronCmd' => $rollupCronCmd,
                'diskCleanupCronCmd' => $diskCleanupCronCmd,
                'partitionMaintainCronCmd' => $partitionMaintainCronCmd,
                'installerPresent' => installer_still_present(),
                'installerLocked' => installer_locked(),
            ], 'admin'));
        }

    }
}
