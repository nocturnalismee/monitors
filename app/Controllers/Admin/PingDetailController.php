<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class PingDetailController
{
    public function index(Request $request): Response
    {
        require_login();

        $id = (int) ($request->params['id'] ?? 0);
        $singleCacheKey = 'ping:single:' . $id;
        $cachedMonitor = cache_get($singleCacheKey);
        if (is_array($cachedMonitor) && (int) ($cachedMonitor['id'] ?? 0) === $id) {
            $monitor = $cachedMonitor;
        } else {
            $monitor = db_one(
                'SELECT pm.id, pm.name, pm.target, pm.target_type, pm.check_method, pm.check_interval_seconds, pm.timeout_seconds, pm.failure_threshold, pm.active, pm.created_at, pm.updated_at,
                        ps.last_status, ps.consecutive_failures, ps.last_latency_ms, ps.last_error, ps.last_checked_at, ps.last_change_at
                 FROM ping_monitors pm
                 LEFT JOIN ping_monitor_states ps ON ps.monitor_id = pm.id
                 WHERE pm.id = :id
                 LIMIT 1',
                [':id' => $id]
            );
            if (is_array($monitor)) {
                cache_set($singleCacheKey, $monitor, cache_ttl('cache_ttl_status_single', 15));
            }
        }
        if ($monitor === null) {
            flash_set('danger', 'Ping monitor not found.');
            redirect('ping');
        }

        $canManageMonitors = has_role('admin');

        if ($request->isPost()) {
            require_role('admin');
            if (!csrf_validate($request->input('_csrf_token'))) {
                flash_set('danger', 'Invalid CSRF token.');
                redirect('ping/' . $id);
            }

            $action = (string) ($request->input('action') ?? '');
            if ($action === 'delete') {
                db_exec('DELETE FROM ping_monitors WHERE id = :id', [':id' => $id]);
                invalidate_ping_cache($id);
                audit_log('ping_monitor_delete', 'Deleted ping monitor and check history', 'ping_monitor', $id);
                flash_set('success', 'Ping monitor deleted successfully.');
                redirect('ping');
            }

            if ($action === 'toggle') {
                db_exec('UPDATE ping_monitors SET active = IF(active = 1, 0, 1), updated_at = NOW() WHERE id = :id', [':id' => $id]);
                invalidate_ping_cache($id);
                audit_log('ping_monitor_toggle', 'Toggled ping monitor active status', 'ping_monitor', $id);
                flash_set('success', 'Ping monitor status updated.');
                redirect('ping/' . $id);
            }

            if ($action === 'run_now') {
                $probe = ping_probe_target(
                    (string) ($monitor['target'] ?? ''),
                    max(1, (int) ($monitor['timeout_seconds'] ?? 2)),
                    (string) ($monitor['check_method'] ?? 'icmp')
                );
                $transition = ping_record_check(
                    $id,
                    $probe,
                    max(1, (int) ($monitor['failure_threshold'] ?? 2))
                );
                if (($transition['changed'] ?? false) === true) {
                    evaluate_ping_monitor_transition_alert($monitor, $transition, $probe);
                }
                invalidate_ping_cache($id);
                audit_log('ping_monitor_run_now', 'Executed manual ping check', 'ping_monitor', $id, [
                    'probe_status' => $probe['status'] ?? 'down',
                    'latency_ms' => $probe['latency_ms'] ?? null,
                ]);
                flash_set('success', 'Manual ping check executed.');
                redirect('ping/' . $id);
            }
        }

        $range = strtolower(trim((string) ($request->query('range') ?? '24h')));
        $range = in_array($range, ['5m', '30m', '24h', '7d', '30d'], true) ? $range : '24h';
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

        $perPage = 15;
        $requestedPage = (int) ($request->query('page') ?? 1);
        $page = max(1, $requestedPage);

        $historyCountRow = db_one(
            "SELECT COUNT(*) AS total
             FROM ping_checks
             WHERE monitor_id = :id
             AND checked_at >= DATE_SUB(NOW(), INTERVAL {$rangeSql})",
            [':id' => $id]
        );
        $totalHistoryRows = (int) ($historyCountRow['total'] ?? 0);
        $totalPages = max(1, (int) ceil($totalHistoryRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $historyPageRows = db_all(
            "SELECT id, status, latency_ms, error_message, checked_at
             FROM ping_checks
             WHERE monitor_id = :id
             AND checked_at >= DATE_SUB(NOW(), INTERVAL {$rangeSql})
             ORDER BY checked_at DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            [':id' => $id]
        );

        $chartRows = array_reverse($history);
        $chartPayload = array_map(static function (array $row): array {
            return [
                'checked_at' => (string) ($row['checked_at'] ?? ''),
                'status' => (string) ($row['status'] ?? 'down'),
                'latency_ms' => isset($row['latency_ms']) ? (float) $row['latency_ms'] : null,
            ];
        }, $chartRows);

        $uptimeAggregate = db_one(
            "SELECT COUNT(*) AS total_checks,
                    COALESCE(SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END), 0) AS up_checks,
                    COALESCE(SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END), 0) AS down_checks
             FROM ping_checks
             WHERE monitor_id = :id AND checked_at >= DATE_SUB(NOW(), INTERVAL {$rangeSql})",
            [':id' => $id]
        );
        $uptimeTotalChecks = (int) ($uptimeAggregate['total_checks'] ?? 0);
        $uptimeUpChecks = (int) ($uptimeAggregate['up_checks'] ?? 0);
        $failureAggregate30d = db_one(
            "SELECT COUNT(*) AS down_checks
             FROM ping_checks
             WHERE monitor_id = :id
               AND status = 'down'
               AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [':id' => $id]
        );
        $failureCount30d = (int) ($failureAggregate30d['down_checks'] ?? 0);
        $uptimePercent = $uptimeTotalChecks > 0 ? ($uptimeUpChecks / $uptimeTotalChecks) * 100 : null;

        $displayStatus = ping_display_status((string) ($monitor['last_status'] ?? 'unknown'), (int) ($monitor['active'] ?? 0));
        $statusBadgeClass = match ($displayStatus) {
            'up' => 'badge-online',
            'down' => 'badge-down',
            'paused' => 'text-bg-secondary',
            default => 'badge-pending',
        };

        $data = [
            'id' => $id,
            'monitor' => $monitor,
            'canManageMonitors' => $canManageMonitors,
            'range' => $range,
            'historyPageRows' => $historyPageRows,
            'chartPayload' => $chartPayload,
            'uptimePercent' => $uptimePercent,
            'uptimeUpChecks' => $uptimeUpChecks,
            'uptimeTotalChecks' => $uptimeTotalChecks,
            'failureCount30d' => $failureCount30d,
            'displayStatus' => $displayStatus,
            'statusBadgeClass' => $statusBadgeClass,
            'page' => $page,
            'perPage' => $perPage,
            'totalHistoryRows' => $totalHistoryRows,
            'totalPages' => $totalPages,
            'offset' => $offset,
            'title' => APP_NAME . ' - Ping Monitor Detail',
            'activeNav' => 'ping',
        ];

        return Response::html(View::render('admin/ping_detail', $data, 'admin'));
    }
}
