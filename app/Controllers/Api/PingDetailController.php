<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;

/**
 * Partial-refresh endpoint for the ping monitor detail page.
 *
 * Returns the same range-scoped dataset the HTML page renders
 * (summary cards, chart payload, latest recent checks) as JSON so the
 * page can poll without a full browser reload.
 */
final class PingDetailController
{
    /** @var array<int, string> */
    private const ALLOWED_RANGES = ['5m', '30m', '24h', '7d', '30d'];

    public function index(Request $request): Response
    {
        if ($request->method !== 'GET') {
            return Response::json(['error' => 'Method not allowed'], 405)->withHeader('Allow', 'GET');
        }

        require_login();

        $ip = $request->ip();
        if (!api_rate_check('ping_detail_api', $ip, 60)) {
            api_rate_limit_exceeded();
        }

        $id = (int) ($request->query('id') ?? 0);
        if ($id <= 0) {
            return Response::json(['error' => 'Invalid monitor ID'], 422);
        }

        $range = strtolower(trim((string) ($request->query('range') ?? '24h')));
        $range = in_array($range, self::ALLOWED_RANGES, true) ? $range : '24h';
        $rangeSql = match ($range) {
            '5m' => '5 MINUTE',
            '30m' => '30 MINUTE',
            '7d' => '7 DAY',
            '30d' => '30 DAY',
            default => '24 HOUR',
        };
        $historyLimit = match ($range) {
            '5m' => 400,
            '30m' => 1200,
            '7d' => 2500,
            '30d' => 5000,
            default => 1500,
        };
        $historyTtl = match ($range) {
            '5m' => cache_ttl('cache_ttl_history_5m', 5),
            '30m' => cache_ttl('cache_ttl_history_30m', 10),
            '7d' => cache_ttl('cache_ttl_history_7d', 120),
            '30d' => cache_ttl('cache_ttl_history_30d', 180),
            default => cache_ttl('cache_ttl_history_24h', 30),
        };

        $monitor = db_one(
            'SELECT pm.id, pm.name, pm.target, pm.active, pm.failure_threshold,
                    ps.last_status, ps.last_latency_ms, ps.last_error, ps.last_checked_at, ps.last_change_at
             FROM ping_monitors pm
             LEFT JOIN ping_monitor_states ps ON ps.monitor_id = pm.id
             WHERE pm.id = :id
             LIMIT 1',
            [':id' => $id]
        );
        if ($monitor === null) {
            return Response::json(['error' => 'Ping monitor not found'], 404);
        }

        // Shared cache key with the HTML page so polling reuses the same history snapshot.
        $historyCacheKey = 'ping:history:' . $id . ':' . $range;
        $cachedHistory = cache_get($historyCacheKey);
        if (is_array($cachedHistory)) {
            $history = $cachedHistory;
        } else {
            $history = db_all(
                "SELECT id, status, latency_ms, error_message, checked_at
                 FROM ping_checks
                 WHERE monitor_id = :id
                 AND checked_at >= DATE_SUB(NOW(), INTERVAL {$rangeSql})
                 ORDER BY checked_at DESC
                 LIMIT {$historyLimit}",
                [':id' => $id]
            );
            cache_set($historyCacheKey, $history, $historyTtl);
        }

        $recent = array_slice($history, 0, 15);

        $historyCountRow = db_one(
            "SELECT COUNT(*) AS total
             FROM ping_checks
             WHERE monitor_id = :id
             AND checked_at >= DATE_SUB(NOW(), INTERVAL {$rangeSql})",
            [':id' => $id]
        );
        $uptimeAggregate = db_one(
            "SELECT COUNT(*) AS total_checks,
                    COALESCE(SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END), 0) AS up_checks
             FROM ping_checks
             WHERE monitor_id = :id AND checked_at >= DATE_SUB(NOW(), INTERVAL {$rangeSql})",
            [':id' => $id]
        );
        $failureAggregate30d = db_one(
            "SELECT COUNT(*) AS down_checks
             FROM ping_checks
             WHERE monitor_id = :id
               AND status = 'down'
               AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [':id' => $id]
        );

        $totalHistoryRows = (int) ($historyCountRow['total'] ?? 0);
        $uptimeTotalChecks = (int) ($uptimeAggregate['total_checks'] ?? 0);
        $uptimeUpChecks = (int) ($uptimeAggregate['up_checks'] ?? 0);

        $displayStatus = ping_display_status((string) ($monitor['last_status'] ?? 'unknown'), (int) ($monitor['active'] ?? 0));
        $statusBadgeClass = match ($displayStatus) {
            'up' => 'badge-online',
            'down' => 'badge-down',
            'paused' => 'text-bg-secondary',
            default => 'badge-pending',
        };

        $chart = array_map(static function (array $row): array {
            return [
                'checked_at' => (string) ($row['checked_at'] ?? ''),
                'status' => (string) ($row['status'] ?? 'down'),
                'latency_ms' => isset($row['latency_ms']) ? (float) $row['latency_ms'] : null,
            ];
        }, array_reverse($history));

        $recentPayload = array_map(static function (array $row): array {
            return [
                'checked_at' => (string) ($row['checked_at'] ?? ''),
                'status' => (string) ($row['status'] ?? 'down'),
                'latency_ms' => isset($row['latency_ms']) ? (float) $row['latency_ms'] : null,
                'error_message' => (string) ($row['error_message'] ?? ''),
            ];
        }, $recent);

        return Response::json([
            'id' => $id,
            'range' => $range,
            'monitor' => [
                'name' => (string) ($monitor['name'] ?? ''),
                'target' => (string) ($monitor['target'] ?? ''),
                'active' => (int) ($monitor['active'] ?? 0),
                'failure_threshold' => (int) ($monitor['failure_threshold'] ?? 2),
                'last_latency_ms' => isset($monitor['last_latency_ms']) ? (float) $monitor['last_latency_ms'] : null,
                'last_checked_at' => (string) ($monitor['last_checked_at'] ?? ''),
                'last_change_at' => (string) ($monitor['last_change_at'] ?? ''),
                'last_error' => (string) ($monitor['last_error'] ?? ''),
            ],
            'display_status' => $displayStatus,
            'status_badge_class' => $statusBadgeClass,
            'uptime_percent' => $uptimeTotalChecks > 0 ? ($uptimeUpChecks / $uptimeTotalChecks) * 100 : null,
            'uptime_up_checks' => $uptimeUpChecks,
            'uptime_total_checks' => $uptimeTotalChecks,
            'failure_count_30d' => (int) ($failureAggregate30d['down_checks'] ?? 0),
            'total_history_rows' => $totalHistoryRows,
            'chart' => $chart,
            'recent' => $recentPayload,
        ]);
    }
}
