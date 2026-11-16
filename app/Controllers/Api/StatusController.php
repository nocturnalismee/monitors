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
        $fleetRange = trim((string) $request->query('fleet_history', ''));
        if ($fleetRange !== '') {
            return $this->fleetHistory($fleetRange);
        }
        $rawHistory = $request->query('history');
        $history = $rawHistory !== null ? (string) $rawHistory : null;
        $diskSummary = (string) $request->query('disk_summary', '') === '1';

        if ($diskSummary) {
            if (!$this->diskHealthTablesExist()) {
                return Response::json([]);
            }

            $includeInactive = (string) $request->query('include_inactive', '') === '1';
            // Version-suffixed so a disk push can orphan it without knowing
            // which include_inactive variant the viewer used.
            $cacheKey = ($includeInactive ? 'status:disk_summary:all' : 'status:disk_summary:active') . status_cache_version();
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
                'SELECT s.id, s.name, s.location, s.host, s.type, s.provider, s.label, s.active, s.maintenance_mode, s.maintenance_until,
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
            'SELECT s.id, s.name, s.location, s.host, s.type, s.provider, s.label, s.active, s.maintenance_mode, s.maintenance_until,
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
                'host' => $row['host'] ?? null,
                'type' => $row['type'],
                'provider' => $row['provider'] ?? null,
                'label' => $row['label'] ?? null,
                'active' => (int) ($row['active'] ?? 0),
                'maintenance_mode' => (int) ($row['maintenance_mode'] ?? 0),
                'maintenance_until' => $row['maintenance_until'] ?? null,
                'status' => serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes),
                'last_seen' => $row['last_seen'],
                // Epoch so browsers in another time zone can render a correct
                // "12s ago" freshness label without parsing a server-local string.
                'last_seen_ts' => !empty($row['last_seen']) ? (int) strtotime((string) $row['last_seen']) : null,
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

    /**
     * Fleet-wide average utilisation series for the dashboard hero chart.
     *
     * The dashboard needs one aggregate series, not N per-server series, so
     * this averages every active server into fixed buckets. Same coverage
     * model as historyQuery(): a raw slice for the newest hours plus the
     * 5-minute rollup for everything older, so the chart keeps a full window
     * even when the raw partitions have already been dropped.
     */
    private function fleetHistory(string $rangeKey): Response
    {
        $range = in_array($rangeKey, ['30m', '6h', '24h', '7d'], true) ? $rangeKey : '24h';
        $cacheKey = 'status:fleet:' . $range . status_cache_version();
        $cached = cache_get($cacheKey);
        if (is_array($cached)) {
            return Response::json($cached);
        }

        $ttl = match ($range) {
            '30m' => 15,
            '6h' => 15,
            '24h' => 30,
            default => 60,
        };

        ['sql' => $sql, 'params' => $params, 'points' => $points] = self::fleetHistoryQuery($range);
        $sql = str_replace('{$points}', (string) $points, $sql);

        try {
            $stmt = db()->prepare($sql);
            foreach ($params as $paramName => [$paramValue, $paramType]) {
                $stmt->bindValue($paramName, $paramValue, $paramType);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('fleet history query failed: ' . $e->getMessage());
            return Response::json([]);
        }

        cache_set($cacheKey, $rows, $ttl);
        return Response::json($rows);
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
     * Aggregate fleet series: one bucket = average over all active servers.
     *
     * @return array{sql: string, params: array<string, array{0: int, 1: int}>, points: int}
     */
    public static function fleetHistoryQuery(string $rangeKey): array
    {
        try {
            $rawHoursSetting = (int) setting_get('metrics_raw_hours');
        } catch (Throwable) {
            $rawHoursSetting = 0;
        }
        $rawHours = max(1, $rawHoursSetting ?: 24);

        $spec = match ($rangeKey) {
            '30m' => ['minutes' => 30, 'bucket' => 60, 'points' => 60],
            '6h' => ['minutes' => 360, 'bucket' => 300, 'points' => 96],
            '7d' => ['minutes' => 10080, 'bucket' => 3600, 'points' => 200],
            default => ['minutes' => 1440, 'bucket' => 300, 'points' => 320],
        };
        $bucket = $spec['bucket'];
        $rangeStart = strtotime('-' . $spec['minutes'] . ' minutes');
        // Raw data only exists back to `metrics_raw_hours`; above it the
        // 5-minute rollup takes over. Hour-aligned so both slices meet on the
        // same boundary the rollup worker writes.
        $seam = max(
            strtotime(substr(date('Y-m-d H:i:s', strtotime("-{$rawHours} hours")), 0, 13) . ':00:00'),
            $rangeStart
        );

        $activeFilter = 'server_id IN (SELECT id FROM servers WHERE active = 1)';
        $tsExpr = 'UNIX_TIMESTAMP(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / ' . $bucket . ') * ' . $bucket . '))';

        $raw = 'SELECT
                    ' . $tsExpr . ' AS ts,
                    ROUND(AVG(cpu_load), 2) AS cpu_load,
                    ROUND(AVG(CASE WHEN ram_total > 0 THEN ram_used / ram_total * 100 END), 2) AS ram_pct,
                    ROUND(AVG(CASE WHEN hdd_total > 0 THEN hdd_used / hdd_total * 100 END), 2) AS hdd_pct,
                    COUNT(DISTINCT server_id) AS servers
                FROM metrics
                WHERE recorded_at >= FROM_UNIXTIME(:seam_raw)
                  AND ' . $activeFilter . '
                GROUP BY ts';

        $params = [
            ':seam_raw' => [$seam, PDO::PARAM_INT],
        ];

        $inner = $raw;
        if ($seam > $rangeStart) {
            $hist = 'SELECT
                        ' . $tsExpr . ' AS ts,
                        ROUND(AVG(cpu_load), 2) AS cpu_load,
                        ROUND(AVG(CASE WHEN ram_total > 0 THEN ram_used / ram_total * 100 END), 2) AS ram_pct,
                        ROUND(AVG(CASE WHEN hdd_total > 0 THEN hdd_used / hdd_total * 100 END), 2) AS hdd_pct,
                        COUNT(DISTINCT server_id) AS servers
                    FROM metrics_history
                    WHERE bucket_seconds = 300
                      AND recorded_at >= FROM_UNIXTIME(:range_hist)
                      AND recorded_at < FROM_UNIXTIME(:seam_hist)
                      AND ' . $activeFilter . '
                    GROUP BY ts';
            $inner = $hist . ' UNION ALL ' . $raw;
            $params[':range_hist'] = [$rangeStart, PDO::PARAM_INT];
            $params[':seam_hist'] = [$seam, PDO::PARAM_INT];
        }

        return [
            'sql' => self::wrapFleetPoints($inner),
            'params' => $params,
            'points' => (int) $spec['points'],
        ];
    }

    /** Newest N buckets, re-ordered ascending for chart consumers. */
    private static function wrapFleetPoints(string $inner): string
    {
        return 'SELECT * FROM (
                    SELECT
                        ts,
                        DATE_FORMAT(FROM_UNIXTIME(ts), "%Y-%m-%d %H:%i:%s") AS recorded_at,
                        cpu_load,
                        ram_pct,
                        hdd_pct,
                        servers
                    FROM (' . $inner . ') AS fleet_buckets
                    ORDER BY ts DESC
                    LIMIT {$points}
                ) AS fleet_latest
                ORDER BY ts ASC';
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
        // Defensive default: callers should whitelist the range, but an
        // unknown key must never fall through to the 30-day scan.
        if (!in_array($historyKey, ['5m', '30m', '24h', '7d', '30d'], true)) {
            $historyKey = '24h';
        }
        // Boundaries travel as epoch integers: PDO would otherwise bind the
        // string while MySQL re-parses it under its own session time zone,
        // and a two-hour skew silently empties the rollup slices.
        $boundaryRawEpoch = strtotime("-{$rawHours} hours");

        if ($historyKey === '5m' || $historyKey === '30m') {
            // Fully inside the raw retention window: one bounded scan,
            // tie-collapsed to one point per second by the wrapper.
            $minutes = $historyKey === '5m' ? 5 : 30;
            $inner = 'SELECT
                        UNIX_TIMESTAMP(recorded_at) AS ts,
                        uptime, ram_total, ram_used, hdd_total, hdd_used,
                        cpu_load, network_in_bps, network_out_bps, mail_mta, mail_queue_total
                    FROM metrics
                    WHERE server_id = :server_id
                      AND recorded_at >= DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE)';

            return [
                'sql' => self::wrapHistoryPoints($inner),
                'params' => [':server_id' => [$serverId, PDO::PARAM_INT]],
            ];
        }

        if ($historyKey === '24h') {
            // Raw tail UNION the 5-minute rollup for data older than
            // `metrics_raw_hours`, so a sparse, rolled-up or
            // partition-dropped raw tail no longer leaves the left half of
            // the day blank.
            //
            // Each UNION slice carries its own placeholder names: with
            // native prepares (EMULATE_PREPARES = false) MySQL rejects a
            // statement where one bound name is reused across sibling
            // UNION branches with "Invalid parameter number".
            $rollup = 'SELECT
                            ' . self::bucketExpression(300) . ' AS ts,
                            MAX(uptime) AS uptime, MAX(ram_total) AS ram_total, ROUND(AVG(ram_used)) AS ram_used,
                            MAX(hdd_total) AS hdd_total, ROUND(AVG(hdd_used)) AS hdd_used,
                            ROUND(AVG(cpu_load), 4) AS cpu_load,
                            ROUND(AVG(network_in_bps)) AS network_in_bps, ROUND(AVG(network_out_bps)) AS network_out_bps,
                            MAX(mail_mta) AS mail_mta, ROUND(AVG(mail_queue_total)) AS mail_queue_total
                        FROM metrics_history
                        WHERE server_id = :server_id AND bucket_seconds = 300
                          AND recorded_at >= DATE_SUB(NOW(), INTERVAL 1440 MINUTE)
                          AND recorded_at < FROM_UNIXTIME(:boundary_24h_hist)
                        GROUP BY ' . self::bucketExpression(300) . ', recorded_at';
            // The seam must be the FIRST instant the raw slice can cover, i.e.
            // the start of the hour-aligned raw window. Using now-rawHours
            // instead would drop the hours between the last rollup and that
            // instant (and leave them out of the rollup slice too).
            // Hour-aligned, but never earlier than the window itself: the
            // rollup slice starts at now-24h, so a seam before that would
            // discard every rollup row.
            $rawStartEpoch = max(
                strtotime(substr(date('Y-m-d H:i:s', strtotime("-{$rawHours} hours")), 0, 13) . ':00:00'),
                strtotime('-24 hours')
            );
            $raw = 'SELECT
                        UNIX_TIMESTAMP(recorded_at) AS ts,
                        uptime, ram_total, ram_used, hdd_total, hdd_used,
                        cpu_load, network_in_bps, network_out_bps, mail_mta, mail_queue_total
                    FROM metrics
                    WHERE server_id = :server_id2
                      AND recorded_at >= DATE_SUB(NOW(), INTERVAL 1440 MINUTE)
                      AND recorded_at >= FROM_UNIXTIME(:boundary_24h_raw)';
            $inner = $rollup . ' UNION ALL ' . $raw;

            return [
                'sql' => self::wrapHistoryPoints($inner),
                'params' => [
                    ':server_id' => [$serverId, PDO::PARAM_INT],
                    ':server_id2' => [$serverId, PDO::PARAM_INT],
                    ':boundary_24h_hist' => [$rawStartEpoch, PDO::PARAM_INT],
                    ':boundary_24h_raw' => [$rawStartEpoch, PDO::PARAM_INT],
                ],
            ];
        }

        if ($historyKey === '7d') {
            $hist = 'SELECT
                        UNIX_TIMESTAMP(recorded_at) AS ts,
                        uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load,
                        network_in_bps, network_out_bps, mail_mta, mail_queue_total
                    FROM metrics_history
                    WHERE server_id = :server_id AND bucket_seconds = 300
                      AND recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                      AND recorded_at < FROM_UNIXTIME(:boundary_7d_hist)';
            $raw = 'SELECT
                        ' . self::bucketExpression(300) . ' AS ts,
                        MAX(uptime) AS uptime, MAX(ram_total) AS ram_total, ROUND(AVG(ram_used)) AS ram_used,
                        MAX(hdd_total) AS hdd_total, ROUND(AVG(hdd_used)) AS hdd_used, ROUND(AVG(cpu_load), 4) AS cpu_load,
                        ROUND(AVG(network_in_bps)) AS network_in_bps, ROUND(AVG(network_out_bps)) AS network_out_bps,
                        MAX(mail_mta) AS mail_mta, ROUND(AVG(mail_queue_total)) AS mail_queue_total
                    FROM metrics
                    WHERE server_id = :server_id2
                      AND recorded_at >= FROM_UNIXTIME(:boundary_7d_raw)
                    GROUP BY ' . self::bucketExpression(300) . ', recorded_at';

            return [
                'sql' => self::wrapRollupHistoryPoints($hist . ' UNION ALL ' . $raw),
                'params' => [
                    ':server_id' => [$serverId, PDO::PARAM_INT],
                    ':server_id2' => [$serverId, PDO::PARAM_INT],
                    ':boundary_7d_hist' => [$boundaryRawEpoch, PDO::PARAM_INT],
                    ':boundary_7d_raw' => [$boundaryRawEpoch, PDO::PARAM_INT],
                ],
            ];
        }

        // 30d: hourly rollup, re-bucketed 5-minute rollup, and the raw tail.
        $boundary5mEpoch = strtotime("-{$days5m} days");
        $hourly = 'SELECT
                        UNIX_TIMESTAMP(recorded_at) AS ts,
                        uptime, ram_total, ram_used, hdd_total, hdd_used, cpu_load,
                        network_in_bps, network_out_bps, mail_mta, mail_queue_total
                    FROM metrics_history
                    WHERE server_id = :server_id AND bucket_seconds = 3600
                      AND recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND recorded_at < FROM_UNIXTIME(:boundary_5m_30d_hist)';
        $from5m = 'SELECT
                        ' . self::bucketExpression(3600) . ' AS ts,
                        MAX(uptime) AS uptime, MAX(ram_total) AS ram_total, ROUND(AVG(ram_used)) AS ram_used,
                        MAX(hdd_total) AS hdd_total, ROUND(AVG(hdd_used)) AS hdd_used, ROUND(AVG(cpu_load), 4) AS cpu_load,
                        ROUND(AVG(network_in_bps)) AS network_in_bps, ROUND(AVG(network_out_bps)) AS network_out_bps,
                        MAX(mail_mta) AS mail_mta, ROUND(AVG(mail_queue_total)) AS mail_queue_total
                    FROM metrics_history
                    WHERE server_id = :server_id2 AND bucket_seconds = 300
                      AND recorded_at >= FROM_UNIXTIME(:boundary_5m_30d_raw)
                      AND recorded_at < FROM_UNIXTIME(:boundary_raw_30d_hist)
                    GROUP BY ' . self::bucketExpression(3600) . ', recorded_at';
        $fromRaw = 'SELECT
                        ' . self::bucketExpression(3600) . ' AS ts,
                        MAX(uptime) AS uptime, MAX(ram_total) AS ram_total, ROUND(AVG(ram_used)) AS ram_used,
                        MAX(hdd_total) AS hdd_total, ROUND(AVG(hdd_used)) AS hdd_used, ROUND(AVG(cpu_load), 4) AS cpu_load,
                        ROUND(AVG(network_in_bps)) AS network_in_bps, ROUND(AVG(network_out_bps)) AS network_out_bps,
                        MAX(mail_mta) AS mail_mta, ROUND(AVG(mail_queue_total)) AS mail_queue_total
                    FROM metrics
                    WHERE server_id = :server_id3
                      AND recorded_at >= FROM_UNIXTIME(:boundary_raw_30d_next)
                    GROUP BY ' . self::bucketExpression(3600) . ', recorded_at';

        return [
            'sql' => self::wrapRollupHistoryPoints($hourly . ' UNION ALL ' . $from5m . ' UNION ALL ' . $fromRaw),
            'params' => [
                ':server_id' => [$serverId, PDO::PARAM_INT],
                ':server_id2' => [$serverId, PDO::PARAM_INT],
                ':server_id3' => [$serverId, PDO::PARAM_INT],
                ':boundary_5m_30d_hist' => [$boundary5mEpoch, PDO::PARAM_INT],
                ':boundary_5m_30d_raw' => [$boundary5mEpoch, PDO::PARAM_INT],
                ':boundary_raw_30d_hist' => [$boundaryRawEpoch, PDO::PARAM_INT],
                ':boundary_raw_30d_next' => [$boundaryRawEpoch, PDO::PARAM_INT],
            ],
        ];
    }

    /** Epoch second of the bucket that contains `recorded_at`. */
    private static function bucketExpression(int $bucketSeconds): string
    {
        return 'UNIX_TIMESTAMP(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(recorded_at) / '
            . $bucketSeconds . ') * ' . $bucketSeconds . '))';
    }

    /**
     * Newest-first selection that is tie-safe. `metrics` can hold several
     * rows per second (fast or overlapping agents) while the chart axis is
     * second-precision, so a plain `ORDER BY recorded_at DESC LIMIT n` cuts a
     * second in half and silently drops rows. This keeps every second of the
     * window and averages same-second rows into one point; the result is
     * re-ordered ascending for chart consumers.
     */
    private static function wrapHistoryPoints(string $inner): string
    {
        return 'SELECT * FROM (
                    SELECT
                        ts,
                        DATE_FORMAT(FROM_UNIXTIME(ts), "%Y-%m-%d %H:%i:%s") AS recorded_at,
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
                    ) AS second_points
                    GROUP BY ts
                    ORDER BY ts DESC
                    LIMIT {$points}
                ) AS latest_points
                ORDER BY recorded_at ASC';
    }

    /**
     * Aggregate wrapper for the multi-slice ranges (7d / 30d). Each slice
     * already yields one row per bucket, so grouping on `ts` only merges the
     * seam bucket when two slices meet inside the same hour.
     */
    private static function wrapRollupHistoryPoints(string $inner): string
    {
        return 'SELECT * FROM (
                    SELECT
                        ts,
                        DATE_FORMAT(FROM_UNIXTIME(ts), "%Y-%m-%d %H:%i:%s") AS recorded_at,
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
                    ) AS combined_metrics
                    GROUP BY ts
                    ORDER BY ts DESC
                    LIMIT {$points}
                 ) AS latest_points
                 ORDER BY recorded_at ASC';
    }
}
