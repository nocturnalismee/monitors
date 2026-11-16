<?php
declare(strict_types=1);

namespace App\Controllers\Admin {
    use App\Http\Request;
    use App\Http\Response;
    use App\Services\ServerListService;
    use App\Services\Settings\CronWorkerService;
    use App\Support\View;

    final class DashboardController
    {
        public function index(Request $request): Response
        {
            require_login();

            // One query for the fleet totals; the KPI cards no longer reuse
            // the LIMIT-window rows, which under-reported past 50 servers.
            $summary = db_one(
                'SELECT
                    COUNT(*) AS total_servers,
                    SUM(active = 1) AS active_servers
                 FROM servers'
            );
            $totalServers = (int) ($summary['total_servers'] ?? 0);

            $latestMetricJoin = latest_metric_join_sql('s', 'm');
            $dashboardLimit = 50;
            $rows = db_all(
                'SELECT s.id, s.name, s.location, s.host, s.type, s.provider, s.label, s.active,
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

            // Worker health in one round trip (was 8 queries per page load).
            $health = worker_health_statuses([
                'alert_check' => CronWorkerService::TTL['alert_check'],
                'ping_check' => CronWorkerService::TTL['ping_check'],
                'disk_history_rollup' => CronWorkerService::TTL['disk_history_rollup'],
                'retention_cleanup' => CronWorkerService::TTL['retention_cleanup'],
                'ip_reputation_check' => CronWorkerService::TTL['ip_reputation_check'],
                'rollup_metrics' => CronWorkerService::TTL['rollup_metrics'],
                'disk_retention_cleanup' => CronWorkerService::TTL['disk_retention_cleanup'],
                'partition_maintain' => CronWorkerService::TTL['partition_maintain'],
            ]);
            $alertWorkerHealth = $health['alert_check'];
            $pingWorkerHealth = $health['ping_check'];
            $diskRollupWorkerHealth = $health['disk_history_rollup'];
            $retentionWorkerHealth = $health['retention_cleanup'];
            $ipRepWorkerHealth = $health['ip_reputation_check'];
            $rollupWorkerHealth = $health['rollup_metrics'];
            $diskCleanupWorkerHealth = $health['disk_retention_cleanup'];
            $partitionMaintainWorkerHealth = $health['partition_maintain'];

            // Quote the same schedule the Settings → Ops page recommends
            // instead of keeping a second, drifting copy of every crontab line.
            $projectRoot = realpath(MONITORS_BASE_DIR);
            if (!is_string($projectRoot) || $projectRoot === '') {
                $projectRoot = MONITORS_BASE_DIR;
            }
            $workersRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
            $cron = (new CronWorkerService())->cronCommands($workersRoot);
            $alertCronCmd = $cron['alert-check'] ?? '';
            $pingCronCmd = $cron['ping-check'] ?? '';
            $diskRollupCronCmd = $cron['disk-rollup'] ?? '';
            $retentionCronCmd = $cron['cleanup'] ?? '';
            $ipRepCronCmd = $cron['ip-reputation-check'] ?? '';
            $rollupCronCmd = $cron['rollup'] ?? '';
            $diskCleanupCronCmd = $cron['disk-cleanup'] ?? '';
            $partitionMaintainCronCmd = $cron['partition-maintain'] ?? '';

            $counts = ServerListService::countByStatusFromDb($statusOnlineMinutes);
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
