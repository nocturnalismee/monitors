<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Services\ServerListService;
use PDO;
use Throwable;

final class StatusController
{
    public function index(Request $request): Response
    {
        if ($request->method !== 'GET') {
            return Response::json(['error' => 'Method not allowed'], 405)->withHeader('Allow', 'GET');
        }

        $ip = $request->ip();
        if (!api_rate_check('status_api', $ip, 60)) {
            api_rate_limit_exceeded();
        }
        $statusOnlineMinutes = max(1, (int) setting_get('alert_down_minutes'));

        $rawId = $request->query('id');
        $serverId = $rawId !== null ? (int) $rawId : null;
        $rawHistory = $request->query('history');
        $history = $rawHistory !== null ? (string) $rawHistory : null;
        $diskSummary = (string) $request->query('disk_summary', '') === '1';

        if ($diskSummary) {
            if (!$this->diskHealthTablesExist()) {
                return Response::json([]);
            }

            $includeInactive = (string) $request->query('include_inactive', '') === '1';
            $cacheKey = $includeInactive ? 'status:disk_summary:all' : 'status:disk_summary:active';
            $cached = cache_get($cacheKey);
            if (is_array($cached)) {
                return Response::json($cached);
            }

            try {
                $rows = (new \App\Services\DiskHealthSummaryService())->summaryRows($includeInactive);
            } catch (Throwable) {
                $rows = [];
            }

            $result = [];
            foreach ($rows as $row) {
                $diskCount = max(0, (int) ($row['disk_count'] ?? 0));
                $extraDiskCount = max(0, $diskCount - 1);
                $model = trim((string) ($row['primary_disk_model'] ?? ''));
                $device = trim((string) ($row['primary_disk_device'] ?? ''));
                if ($model !== '' && $device !== '') {
                    $primaryLabel = $model . ' (' . $device . ')';
                } elseif ($model !== '') {
                    $primaryLabel = $model;
                } elseif ($device !== '') {
                    $primaryLabel = $device;
                } else {
                    $primaryLabel = '-';
                }

                $result[] = [
                    'server_id' => (int) ($row['server_id'] ?? 0),
                    'server_name' => (string) ($row['server_name'] ?? ''),
                    'disk_count' => $diskCount,
                    'avg_health_score' => isset($row['avg_health_score']) ? (float) $row['avg_health_score'] : null,
                    'avg_power_on_time' => isset($row['avg_power_on_time']) ? (float) $row['avg_power_on_time'] : null,
                    // Backward-compatible alias for older consumers.
                    'avg_power_on_hours' => isset($row['avg_power_on_time']) ? (float) $row['avg_power_on_time'] : null,
                    'avg_tbw_bytes' => isset($row['avg_tbw_bytes']) ? (float) $row['avg_tbw_bytes'] : null,
                    'last_update' => (string) ($row['last_update'] ?? '-'),
                    'primary_disk_label' => $primaryLabel,
                    'extra_disk_count' => $extraDiskCount,
                ];
            }

            cache_set($cacheKey, $result, cache_ttl('cache_ttl_disk_health_list', 60));
            return Response::json($result);
        }

        if ($serverId !== null && $serverId > 0 && $history !== null) {
            $historyKey = in_array($history, ['5m', '30m', '24h', '7d', '30d'], true) ? $history : '24h';
            // Snap to a fixed point set: bounds cache-key cardinality to 6
            // variants per (server, range) instead of every arbitrary value,
            // and keeps the interpolated LIMIT predictable.
            $requestedPoints = max(100, min(5000, (int) $request->query('points', 2000)));
            $points = 100;
            foreach ([100, 200, 500, 1000, 2000, 5000] as $candidatePoint) {
                if (abs($candidatePoint - $requestedPoints) < abs($points - $requestedPoints)) {
                    $points = $candidatePoint;
                }
            }
            $cacheKey = 'status:history:' . $serverId . ':' . $historyKey . ':p' . $points . status_cache_version($serverId);
            $historyTtl = match ($historyKey) {
                '5m' => cache_ttl('cache_ttl_history_5m', 5),
                '30m' => cache_ttl('cache_ttl_history_30m', 10),
                '7d' => cache_ttl('cache_ttl_history_7d', 60),
                '30d' => cache_ttl('cache_ttl_history_30d', 120),
                default => cache_ttl('cache_ttl_history_24h', 10),
            };
            $cached = cache_get($cacheKey);
            if (is_array($cached)) {
                return Response::json($cached);
            }

            ['sql' => $sql, 'params' => $historyParams] = self::historyQuery($historyKey, $serverId);
            // $points is clamped above to a numeric range before being inserted into
            // the SQL. MariaDB native prepares do not support LIMIT placeholders.
            $sql = str_replace('{$points}', (string) $points, $sql);
            $stmt = db()->prepare($sql);
            foreach ($historyParams as $paramName => [$paramValue, $paramType]) {
                $stmt->bindValue($paramName, $paramValue, $paramType);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll();
            cache_set($cacheKey, $rows, $historyTtl);
            return Response::json($rows);
        }

        if ($serverId !== null && $serverId > 0) {
            $singleCacheKey = 'status:single:' . $serverId . status_cache_version($serverId);
            $singleCached = cache_get($singleCacheKey);
            if (is_array($singleCached) && isset($singleCached['id'])) {
                return Response::json($singleCached);
            }

            $row = db_one(
                'SELECT s.id, s.name, s.location, s.type, s.active, s.maintenance_mode, s.maintenance_until,
                        COALESCE(s.last_seen_at, m.recorded_at) AS last_seen, m.uptime, m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.cpu_load, m.network_in_bps, m.network_out_bps, m.mail_mta, m.mail_queue_total, m.panel_profile
                 FROM servers s' . latest_metric_join_sql('s', 'm') . '
                 WHERE s.id = :id
                 LIMIT 1',
                [':id' => $serverId]
            );

            if ($row === null) {
                return Response::json(['error' => 'Server not found'], 404);
            }

            $row['status'] = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes);
            $row['panel_profile'] = (string) ($row['panel_profile'] ?? 'generic');
            $row['maintenance_mode'] = (int) ($row['maintenance_mode'] ?? 0);
            $row['maintenance_until'] = $row['maintenance_until'] ?? null;
            $row['ram_used_pct'] = calculateUsagePercent((int) ($row['ram_used'] ?? 0), (int) ($row['ram_total'] ?? 0));
            $row['hdd_used_pct'] = calculateUsagePercent((int) ($row['hdd_used'] ?? 0), (int) ($row['hdd_total'] ?? 0));
            $services = db_all(
                'SELECT service_group, service_key, unit_name, last_status AS status, updated_at
                 FROM server_service_states
                 WHERE server_id = :id
                 ORDER BY service_group ASC, service_key ASC',
                [':id' => $serverId]
            );
            $row['services'] = array_map(static function (array $service): array {
                return [
                    'group' => (string) ($service['service_group'] ?? ''),
                    'service_key' => (string) ($service['service_key'] ?? ''),
                    'unit_name' => (string) ($service['unit_name'] ?? ''),
                    'status' => (string) ($service['status'] ?? 'unknown'),
                    'updated_at' => (string) ($service['updated_at'] ?? ''),
                ];
            }, $services);
            $summaryMap = ServerListService::serviceSummaryMap([$serverId]);
            $row['services_summary'] = $summaryMap[$serverId] ?? ['up' => 0, 'down' => 0, 'unknown' => 0];
            cache_set($singleCacheKey, $row, cache_ttl('cache_ttl_status_single', 15));
            return Response::json($row);
        }

        $includeInactive = (string) $request->query('include_inactive', '') === '1';
        $listCacheKey = $includeInactive ? 'status:list:all' : 'status:list:active';
        $listCached = cache_get($listCacheKey);
        if (is_array($listCached)) {
            return Response::json($listCached);
        }

        $rows = db_all(
            'SELECT s.id, s.name, s.location, s.type, s.active, s.maintenance_mode, s.maintenance_until,
                    COALESCE(s.last_seen_at, m.recorded_at) AS last_seen, m.uptime, m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.cpu_load, m.network_in_bps, m.network_out_bps, m.mail_mta, m.mail_queue_total, m.panel_profile
             FROM servers s' . latest_metric_join_sql('s', 'm') . '
             ' . ($includeInactive ? '' : 'WHERE s.active = 1') . '
             ORDER BY s.name ASC'
        );
        $summaryMap = ServerListService::serviceSummaryMap(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        $result = [];
        foreach ($rows as $row) {
            $sid = (int) $row['id'];
            $result[] = [
                'id' => $sid,
                'name' => $row['name'],
                'location' => $row['location'],
                'type' => $row['type'],
                'active' => (int) ($row['active'] ?? 0),
                'maintenance_mode' => (int) ($row['maintenance_mode'] ?? 0),
                'maintenance_until' => $row['maintenance_until'] ?? null,
                'status' => serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes),
                'last_seen' => $row['last_seen'],
                'uptime' => (int) ($row['uptime'] ?? 0),
                'ram_total' => (int) ($row['ram_total'] ?? 0),
                'ram_used' => (int) ($row['ram_used'] ?? 0),
                'hdd_total' => (int) ($row['hdd_total'] ?? 0),
                'hdd_used' => (int) ($row['hdd_used'] ?? 0),
                'ram_used_pct' => calculateUsagePercent((int) ($row['ram_used'] ?? 0), (int) ($row['ram_total'] ?? 0)),
                'hdd_used_pct' => calculateUsagePercent((int) ($row['hdd_used'] ?? 0), (int) ($row['hdd_total'] ?? 0)),
                'cpu_load' => (float) ($row['cpu_load'] ?? 0),
                'network_in_bps' => (int) ($row['network_in_bps'] ?? 0),
                'network_out_bps' => (int) ($row['network_out_bps'] ?? 0),
                'mail_mta' => $row['mail_mta'] ?? 'none',
                'mail_queue_total' => (int) ($row['mail_queue_total'] ?? 0),
                'panel_profile' => (string) ($row['panel_profile'] ?? 'generic'),
                'services_summary' => $summaryMap[$sid] ?? ['up' => 0, 'down' => 0, 'unknown' => 0],
            ];
        }

        cache_set($listCacheKey, $result, cache_ttl('cache_ttl_status_list', 15));
        return Response::json($result);
    }

    private function diskHealthTablesExist(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        try {
            $rows = db_all(
                "SELECT table_name
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name IN ('disk_health_states')"
            );
            $map = [];
            foreach ($rows as $row) {
                $name = (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
                if ($name !== '') {
                    $map[$name] = true;
                }
            }
            $ready = isset($map['disk_health_states']);
        } catch (Throwable) {
            $ready = false;
        }

        return $ready;
    }

    /**
     * Build the history SQL for a range together with its bind map.
     *
     * Data-coverage model (mirrors RollupWorker):
     *   metrics (raw)        -> newest data, kept `metrics_raw_hours` (default 24h)
     *   metrics_history 300  -> aggregates raw older than rawHours, kept `metrics_5m_days` (default 14d)
     *   metrics_history 3600 -> aggregates bucket-300 older than 5m-days, kept `metrics_1h_days` (default 90d)
     *
     * Ranges are assembled from DISJOINT slices (each slice filtered on the
     * boundary the next one starts at), so overlapping windows are never
     * double-counted and the raw table is only scanned for the fresh tail:
     *   7d  -> bucket-300 (7d .. rawHours) UNION raw (rawHours .. now), re-bucketed to 300s
     *   30d -> bucket-3600 (30d .. 5m-days) UNION bucket-300 (5m-days .. rawHours)
     *          UNION raw (rawHours .. now), re-bucketed to 3600s
     * On a fresh install with no rollup rows yet only the raw slice yields
     * rows, degrading gracefully to the recent window.
     *
     * @return array{sql: string, params: array<string, array{0: int|string, 1: int}>}
     */
    public static function historyQuery(string $historyKey, int $serverId): array
    {
        $params = [':server_id' => [$serverId, PDO::PARAM_INT]];

        if ($historyKey !== '7d' && $historyKey !== '30d') {
            // 5m / 30m / 24h always live inside the raw retention window.
            $minutes = match ($historyKey) {
                '5m' => 5,
                '30m' => 30,
                default => 24 * 60,
            };
            $sql = 'SELECT * FROM (
                        SELECT
                            DATE_FORMAT(recorded_at, "%Y-%m-%d %H:%i:%s") AS recorded_at,
                            uptime, ram_total, ram_used, hdd_total, hdd_used,
                            ROUND(cpu_load, 4) AS cpu_load, network_in_bps, network_out_bps,
                            mail_mta, mail_queue_total
                        FROM metrics
                        WHERE server_id = :server_id AND recorded_at >= DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE)
                        ORDER BY recorded_at DESC
                        LIMIT {$points}
                    ) AS latest_points
                    ORDER BY recorded_at ASC';
            return ['sql' => $sql, 'params' => $params];
        }

        try {
            $rawHoursSetting = (int) setting_get('metrics_raw_hours');
            $days5mSetting = (int) setting_get('metrics_5m_days');
        } catch (Throwable) {
            // Settings live in the DB too; fall back to the documented
            // defaults when they are unreachable (fresh/broken install).
            $rawHoursSetting = 0;
            $days5mSetting = 0;
        }
        $rawHours = max(1, $rawHoursSetting ?: 24);
        $days5m = max(1, $days5mSetting ?: 14);
        $boundaryRaw = date('Y-m-d H:i:s', strtotime("-{$rawHours} hours"));

        if ($historyKey === '7d') {
            $params[':boundary_7d_hist'] = [$boundaryRaw, PDO::PARAM_STR];
            $params[':boundary_7d_raw'] = [$boundaryRaw, PDO::PARAM_STR];
            $params[':server_id2'] = [$serverId, PDO::PARAM_INT];
            $inner = 'SELECT
                        DATE_FORMAT(recorded_at, "%Y-%m-%d %H:%i:00") AS recorded_at,
                        uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load,
                        network_in_bps, network_out_bps, mail_mta, mail_queue_total
                    FROM metrics_history
                    WHERE server_id = :server_id AND bucket_seconds = 300
                      AND recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                      AND recorded_at < :boundary_7d_hist
                    UNION ALL
                    SELECT
                        DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 300) * 300), "%Y-%m-%d %H:%i:00"),
                        MAX(uptime), MAX(ram_total), ROUND(AVG(ram_used)),
                        MAX(hdd_total), ROUND(AVG(hdd_used)), ROUND(AVG(cpu_load), 4),
                        ROUND(AVG(network_in_bps)), ROUND(AVG(network_out_bps)),
                        MAX(mail_mta), ROUND(AVG(mail_queue_total))
                    FROM metrics
                    WHERE server_id = :server_id2
                      AND recorded_at >= :boundary_7d_raw
                    GROUP BY DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 300) * 300), "%Y-%m-%d %H:%i:00")';
        } else {
            $boundary5m = date('Y-m-d H:i:s', strtotime("-{$days5m} days"));
            $params[':boundary_5m_30d_hist'] = [$boundary5m, PDO::PARAM_STR];
            $params[':boundary_5m_30d_raw'] = [$boundary5m, PDO::PARAM_STR];
            $params[':boundary_raw_30d_hist'] = [$boundaryRaw, PDO::PARAM_STR];
            $params[':boundary_raw_30d_next'] = [$boundaryRaw, PDO::PARAM_STR];
            $params[':server_id2'] = [$serverId, PDO::PARAM_INT];
            $params[':server_id3'] = [$serverId, PDO::PARAM_INT];
            $inner = 'SELECT
                        DATE_FORMAT(recorded_at, "%Y-%m-%d %H:%i:00") AS recorded_at,
                        uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load,
                        network_in_bps, network_out_bps, mail_mta, mail_queue_total
                    FROM metrics_history
                    WHERE server_id = :server_id AND bucket_seconds = 3600
                      AND recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND recorded_at < :boundary_5m_30d_hist
                    UNION ALL
                    SELECT
                        DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 3600) * 3600), "%Y-%m-%d %H:%i:00"),
                        MAX(uptime), MAX(ram_total), ROUND(AVG(ram_used)),
                        MAX(hdd_total), ROUND(AVG(hdd_used)), ROUND(AVG(cpu_load), 4),
                        ROUND(AVG(network_in_bps)), ROUND(AVG(network_out_bps)),
                        MAX(mail_mta), ROUND(AVG(mail_queue_total))
                    FROM metrics_history
                    WHERE server_id = :server_id2 AND bucket_seconds = 300
                      AND recorded_at >= :boundary_5m_30d_raw
                      AND recorded_at < :boundary_raw_30d_hist
                    GROUP BY DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 3600) * 3600), "%Y-%m-%d %H:%i:00")
                    UNION ALL
                    SELECT
                        DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 3600) * 3600), "%Y-%m-%d %H:%i:00"),
                        MAX(uptime), MAX(ram_total), ROUND(AVG(ram_used)),
                        MAX(hdd_total), ROUND(AVG(hdd_used)), ROUND(AVG(cpu_load), 4),
                        ROUND(AVG(network_in_bps)), ROUND(AVG(network_out_bps)),
                        MAX(mail_mta), ROUND(AVG(mail_queue_total))
                    FROM metrics
                    WHERE server_id = :server_id3
                      AND recorded_at >= :boundary_raw_30d_next
                    GROUP BY DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / 3600) * 3600), "%Y-%m-%d %H:%i:00")';
        }

        $sql = 'SELECT * FROM (
                    SELECT
                        recorded_at,
                        MAX(uptime) AS uptime,
                        MAX(ram_total) AS ram_total,
                        ROUND(AVG(ram_used)) AS ram_used,
                        MAX(hdd_total) AS hdd_total,
                        ROUND(AVG(hdd_used)) AS hdd_used,
                        ROUND(AVG(cpu_load), 4) AS cpu_load,
                        ROUND(AVG(network_in_bps)) AS network_in_bps,
                        ROUND(AVG(network_out_bps)) AS network_out_bps,
                        MAX(mail_mta) AS mail_mta,
                        ROUND(AVG(mail_queue_total)) AS mail_queue_total
                    FROM (
                        ' . $inner . '
                    ) combined_metrics
                    GROUP BY recorded_at
                    ORDER BY recorded_at DESC
                    LIMIT {$points}
                 ) AS latest_points
                 ORDER BY recorded_at ASC';

        return ['sql' => $sql, 'params' => $params];
    }
}
