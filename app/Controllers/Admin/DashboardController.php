<?php
declare(strict_types=1);

namespace App\Controllers\Admin {
    use App\Http\Request;
    use App\Http\Response;
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
            $statusOnlineMinutes = max(1, (int) setting_get('alert_down_minutes'));
            $cpuWarnThreshold = max(0.0, (float) setting_get('threshold_cpu_load'));
            $cpuCriticalThreshold = max($cpuWarnThreshold, (float) setting_get('threshold_cpu_load_critical'));
            usort(
                $rows,
                static function (array $a, array $b) use ($cpuWarnThreshold, $cpuCriticalThreshold): int {
                    $ra = self::cpuSeverityRank((float) ($a['cpu_load'] ?? 0), $cpuWarnThreshold, $cpuCriticalThreshold);
                    $rb = self::cpuSeverityRank((float) ($b['cpu_load'] ?? 0), $cpuWarnThreshold, $cpuCriticalThreshold);
                    if ($ra !== $rb) {
                        return $rb <=> $ra;
                    }
                    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                }
            );
            $serviceSummaryByServer = [];
            $serverIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0));
            if (!empty($serverIds)) {
                $placeholders = implode(',', array_fill(0, count($serverIds), '?'));
                $stmt = db()->prepare(
                    "SELECT server_id,
                            SUM(last_status = 'up') AS up_count,
                            SUM(last_status = 'down') AS down_count,
                            SUM(last_status = 'unknown') AS unknown_count
                     FROM server_service_states
                     WHERE server_id IN ({$placeholders})
                     GROUP BY server_id"
                );
                $stmt->execute($serverIds);
                foreach ($stmt->fetchAll() as $row) {
                    $sid = (int) ($row['server_id'] ?? 0);
                    if ($sid <= 0) {
                        continue;
                    }
                    $serviceSummaryByServer[$sid] = [
                        'up' => (int) ($row['up_count'] ?? 0),
                        'down' => (int) ($row['down_count'] ?? 0),
                        'unknown' => (int) ($row['unknown_count'] ?? 0),
                    ];
                }
            }
            $alertWorkerHealth = worker_health_status('alert_check', 180);
            $pingWorkerHealth = worker_health_status('ping_check', 300);
            $diskRollupWorkerHealth = worker_health_status('disk_history_rollup', 129600);
            $retentionWorkerHealth = worker_health_status('retention_cleanup', 129600);
            $ipRepWorkerHealth = worker_health_status('ip_reputation_check', 21600);
            $rollupWorkerHealth = worker_health_status('rollup_metrics', 129600);
            $diskCleanupWorkerHealth = worker_health_status('disk_retention_cleanup', 129600);
            $partitionMaintainWorkerHealth = worker_health_status('partition_maintain', 129600);
            $projectRoot = realpath(SERVMON_BASE_DIR);
            if (!is_string($projectRoot) || $projectRoot === '') {
                $projectRoot = dirname(SERVMON_BASE_DIR);
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

            $online = 0;
            $down = 0;
            $pending = 0;
            foreach ($rows as $row) {
                $st = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes);
                if ($st === 'online') {
                    $online++;
                } elseif ($st === 'down') {
                    $down++;
                } else {
                    $pending++;
                }
            }

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
            ], 'admin'));
        }

        private static function cpuSeverityRank(float $cpuLoad, float $warn, float $critical): int
        {
            if ($cpuLoad > $critical) {
                return 2;
            }
            if ($cpuLoad > $warn) {
                return 1;
            }
            return 0;
        }
    }
}
