<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class PingMonitorsController
{
    public function index(Request $request): Response
    {
        require_login();
        $canManageMonitors = has_role('admin');

        if ($request->isPost()) {
            require_role('admin');
            // CSRF single-guard: enforced by csrf middleware.
            $monitorId = (int) ($request->input('monitor_id') ?? 0);
            $action = (string) ($request->input('action') ?? '');
            if ($monitorId <= 0) {
                flash_set('danger', 'Invalid ping monitor.');
                redirect('ping');
            }

            if ($action === 'toggle') {
                db_exec('UPDATE ping_monitors SET active = IF(active = 1, 0, 1) WHERE id = :id', [':id' => $monitorId]);
                invalidate_ping_cache($monitorId);
                audit_log('ping_monitor_toggle', 'Toggled ping monitor active status', 'ping_monitor', $monitorId);
                flash_set('success', 'Ping monitor status updated.');
            } elseif ($action === 'delete') {
                db_exec('DELETE FROM ping_monitors WHERE id = :id', [':id' => $monitorId]);
                invalidate_ping_cache($monitorId);
                audit_log('ping_monitor_delete', 'Deleted ping monitor and check history', 'ping_monitor', $monitorId);
                flash_set('success', 'Ping monitor deleted successfully.');
            }

            redirect('ping');
        }

        $q = trim((string) ($request->query('q') ?? ''));
        $statusFilter = strtolower(trim((string) ($request->query('status') ?? 'all')));
        $statusFilter = in_array($statusFilter, ['all', 'up', 'down', 'pending', 'paused'], true) ? $statusFilter : 'all';
        $typeFilter = strtolower(trim((string) ($request->query('type') ?? 'all')));
        $typeFilter = in_array($typeFilter, ['all', 'ip', 'domain', 'url'], true) ? $typeFilter : 'all';
        $methodFilter = strtolower(trim((string) ($request->query('method') ?? 'all')));
        $methodFilter = in_array($methodFilter, ['all', 'icmp', 'http'], true) ? $methodFilter : 'all';
        $uptimePoints = 30;

        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(pm.name LIKE :q OR pm.target LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        if ($typeFilter !== 'all') {
            $where[] = 'pm.target_type = :target_type';
            $params[':target_type'] = $typeFilter;
        }
        if ($methodFilter !== 'all') {
            $where[] = 'pm.check_method = :check_method';
            $params[':check_method'] = $methodFilter;
        }
        if ($statusFilter === 'up') {
            $where[] = 'pm.active = 1 AND COALESCE(ps.last_status, "unknown") = "up"';
        } elseif ($statusFilter === 'down') {
            $where[] = 'pm.active = 1 AND COALESCE(ps.last_status, "unknown") = "down"';
        } elseif ($statusFilter === 'pending') {
            $where[] = 'pm.active = 1 AND COALESCE(ps.last_status, "unknown") = "unknown"';
        } elseif ($statusFilter === 'paused') {
            $where[] = 'pm.active = 0';
        }

        $sql = 'SELECT pm.id, pm.name, pm.target, pm.target_type, pm.check_method, pm.check_interval_seconds, pm.timeout_seconds, pm.failure_threshold, pm.active,
                       ps.last_status, ps.last_latency_ms, ps.last_error, ps.last_checked_at, ps.last_change_at, ps.consecutive_failures
                FROM ping_monitors pm
                LEFT JOIN ping_monitor_states ps ON ps.monitor_id = pm.id';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY pm.created_at DESC, pm.id DESC';
        $listCacheKey = 'ping:list:' . md5((string) json_encode([
            'q' => $q,
            'status' => $statusFilter,
            'type' => $typeFilter,
            'method' => $methodFilter,
            'uptime_points' => $uptimePoints,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cachedListPayload = cache_get($listCacheKey);
        if (is_array($cachedListPayload) && isset($cachedListPayload['rows']) && isset($cachedListPayload['summary'])) {
            $rows = is_array($cachedListPayload['rows']) ? $cachedListPayload['rows'] : [];
            $summary = is_array($cachedListPayload['summary']) ? $cachedListPayload['summary'] : [
                'total' => 0,
                'up' => 0,
                'down' => 0,
                'pending' => 0,
                'paused' => 0,
            ];
            $uptimeBarsByMonitor = is_array($cachedListPayload['uptime_bars_by_monitor'] ?? null) ? $cachedListPayload['uptime_bars_by_monitor'] : [];
            $uptimeStatsByMonitor = is_array($cachedListPayload['uptime_stats_by_monitor'] ?? null) ? $cachedListPayload['uptime_stats_by_monitor'] : [];
        } else {
            $rows = db_all($sql, $params);
            $summary = [
                'total' => count($rows),
                'up' => 0,
                'down' => 0,
                'pending' => 0,
                'paused' => 0,
            ];
            foreach ($rows as $row) {
                $status = ping_display_status((string) ($row['last_status'] ?? 'unknown'), (int) ($row['active'] ?? 0));
                if (isset($summary[$status])) {
                    $summary[$status]++;
                }
            }

            $uptimeBarsByMonitor = [];
            $monitorIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0));

            // Bar-derived stats first, then the 30-day aggregate overrides.
            // Both live INSIDE the cached payload so repeat page loads in the
            // cache TTL window do not rescan ping_checks twice per request.
            foreach ($monitorIds as $monitorId) {
                $uptimeBarsByMonitor[$monitorId] = array_fill(0, $uptimePoints, ['status' => 'pending', 'checked_at' => null]);
            }
            $uptimeStatsByMonitor = [];
            foreach ($uptimeBarsByMonitor as $monitorId => $segments) {
                $upChecks = 0;
                $knownChecks = 0;
                foreach ((array) $segments as $segment) {
                    $segmentStatus = (string) ($segment['status'] ?? 'pending');
                    if (in_array($segmentStatus, ['up', 'down'], true)) {
                        $knownChecks++;
                        if ($segmentStatus === 'up') {
                            $upChecks++;
                        }
                    }
                }
                $uptimeStatsByMonitor[$monitorId] = [
                    'up' => $upChecks,
                    'total' => $knownChecks,
                    'percent' => $knownChecks > 0 ? ($upChecks / $knownChecks) * 100 : null,
                ];
            }

            if (!empty($monitorIds)) {
                $placeholders = implode(',', array_fill(0, count($monitorIds), '?'));
                $stmt = db()->prepare(
                    "SELECT ranked.monitor_id, ranked.status, ranked.checked_at
                     FROM (
                        SELECT pc.monitor_id, pc.status, pc.checked_at,
                               ROW_NUMBER() OVER (PARTITION BY pc.monitor_id ORDER BY pc.checked_at DESC, pc.id DESC) AS rn
                        FROM ping_checks pc
                        WHERE pc.monitor_id IN ({$placeholders})
                     ) ranked
                     WHERE ranked.rn <= {$uptimePoints}
                     ORDER BY ranked.monitor_id ASC, ranked.checked_at ASC"
                );
                $stmt->execute($monitorIds);
                $barRows = $stmt->fetchAll();

                $grouped = [];
                foreach ($barRows as $barRow) {
                    $mid = (int) ($barRow['monitor_id'] ?? 0);
                    if ($mid <= 0) {
                        continue;
                    }
                    $grouped[$mid][] = [
                        'status' => (string) ($barRow['status'] ?? 'down'),
                        'checked_at' => (string) ($barRow['checked_at'] ?? ''),
                    ];
                }

                foreach ($grouped as $mid => $items) {
                    $slice = array_slice($items, -$uptimePoints);
                    $pad = $uptimePoints - count($slice);
                    if ($pad > 0) {
                        $slice = array_merge(array_fill(0, $pad, ['status' => 'pending', 'checked_at' => null]), $slice);
                    }
                    $uptimeBarsByMonitor[$mid] = $slice;

                    $upChecks = 0;
                    $knownChecks = 0;
                    foreach ($slice as $segment) {
                        $segmentStatus = (string) ($segment['status'] ?? 'pending');
                        if (in_array($segmentStatus, ['up', 'down'], true)) {
                            $knownChecks++;
                            if ($segmentStatus === 'up') {
                                $upChecks++;
                            }
                        }
                    }
                    $uptimeStatsByMonitor[$mid] = [
                        'up' => $upChecks,
                        'total' => $knownChecks,
                        'percent' => $knownChecks > 0 ? ($upChecks / $knownChecks) * 100 : null,
                    ];
                }

                $placeholders = implode(',', array_fill(0, count($monitorIds), '?'));
                $uptimeStmt = db()->prepare(
                    "SELECT monitor_id,
                            COUNT(*) AS total_checks,
                            COALESCE(SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END), 0) AS up_checks
                     FROM ping_checks
                     WHERE monitor_id IN ({$placeholders})
                       AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY monitor_id"
                );
                $uptimeStmt->execute($monitorIds);
                foreach ($uptimeStmt->fetchAll() as $uptimeRow) {
                    $monitorId = (int) ($uptimeRow['monitor_id'] ?? 0);
                    $totalChecks = (int) ($uptimeRow['total_checks'] ?? 0);
                    $upChecks = (int) ($uptimeRow['up_checks'] ?? 0);
                    $uptimeStatsByMonitor[$monitorId] = [
                        'up' => $upChecks,
                        'total' => $totalChecks,
                        'percent' => $totalChecks > 0 ? ($upChecks / $totalChecks) * 100 : null,
                    ];
                }
            }

            cache_set(
                $listCacheKey,
                [
                    'rows' => $rows,
                    'summary' => $summary,
                    'uptime_bars_by_monitor' => $uptimeBarsByMonitor,
                    'uptime_stats_by_monitor' => $uptimeStatsByMonitor,
                ],
                cache_ttl('cache_ttl_status_list', 15)
            );
        }

        $pingStatusBadgeClass = static function (string $status): string {
            return match ($status) {
                'up' => 'badge-online',
                'down' => 'badge-down',
                'paused' => 'text-bg-secondary',
                default => 'badge-pending',
            };
        };

        $data = [
            'rows' => $rows,
            'canManageMonitors' => $canManageMonitors,
            'q' => $q,
            'statusFilter' => $statusFilter,
            'typeFilter' => $typeFilter,
            'methodFilter' => $methodFilter,
            'uptimePoints' => $uptimePoints,
            'summary' => $summary,
            'uptimeBarsByMonitor' => $uptimeBarsByMonitor,
            'uptimeStatsByMonitor' => $uptimeStatsByMonitor,
            'pingStatusBadgeClass' => $pingStatusBadgeClass,
            'title' => APP_NAME . ' - Ping Monitor',
            'activeNav' => 'ping',
        ];

        return Response::html(View::render('admin/ping_monitors', $data, 'admin'));
    }
}
