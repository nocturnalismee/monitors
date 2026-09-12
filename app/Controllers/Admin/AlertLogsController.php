<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class AlertLogsController
{
    public function index(Request $request): Response
    {
        require_login();

        if ($request->isPost()) {
            // CSRF single-guard: enforced by csrf middleware.
            // Alert mutations are admin-only; viewers are read-only.
            require_role('admin');
            $action = (string) ($request->input('action') ?? '');
            if ($action === 'acknowledge_all') {
                $user = current_user();
                $stmt = db()->prepare(
                    "UPDATE alert_logs SET status = 'acknowledged', acknowledged_by = :uid, acknowledged_at = NOW()
                     WHERE status = 'active'"
                );
                $stmt->execute([':uid' => (int) ($user['id'] ?? 0)]);
                $updated = $stmt->rowCount();
                audit_log('alert_acknowledge_all', 'Acknowledged all active alerts', 'alert', null, ['updated' => $updated]);
                invalidate_alert_cache();
                flash_set('success', $updated > 0 ? $updated . ' active alerts marked as read.' : 'No active alerts to mark as read.');
                redirect('alerts?' . http_build_query($_GET));
            }
            $alertId = (int) ($request->input('alert_id') ?? 0);
            if ($action === 'batch_delete') {
                $ids = [];
                $rawIds = $request->input('alert_ids');
                if (is_array($rawIds)) {
                    foreach ($rawIds as $value) {
                        $id = (int) $value;
                        if ($id > 0) {
                            $ids[$id] = $id;
                        }
                    }
                }
                if (count($ids) === 0) {
                    flash_set('danger', 'No alerts selected.');
                    redirect('alerts?' . http_build_query($_GET));
                }
                $params = [];
                foreach ($ids as $i => $id) {
                    $params[':id' . $i] = $id;
                }
                $placeholders = implode(',', array_keys($params));
                // Queued delivery rows follow via ON DELETE CASCADE.
                db_exec("DELETE FROM alert_logs WHERE id IN ({$placeholders})", $params);
                $count = count($ids);
                audit_log(
                    'alerts_batch_delete',
                    "Bulk deleted {$count} alert(s)",
                    'alert',
                    null,
                    ['alert_ids' => array_values($ids)]
                );
                invalidate_alert_cache();
                flash_set('success', "{$count} alert(s) deleted successfully.");
                redirect('alerts?' . http_build_query($_GET));
            }
            if ($alertId <= 0 || !in_array($action, ['acknowledge', 'resolve', 'silence'], true)) {
                flash_set('danger', 'Invalid alert action.');
                redirect('alerts');
            }
            $user = current_user();
            if ($action === 'acknowledge') {
                db_exec(
                    "UPDATE alert_logs SET status = 'acknowledged', acknowledged_by = :uid, acknowledged_at = NOW()
                     WHERE id = :id AND status IN ('active','silenced')",
                    [':uid' => (int) ($user['id'] ?? 0), ':id' => $alertId]
                );
                audit_log('alert_acknowledge', 'Acknowledged alert', 'alert', $alertId);
                flash_set('success', 'Alert acknowledged.');
            } elseif ($action === 'resolve') {
                db_exec(
                    "UPDATE alert_logs SET status = 'resolved', resolved_at = NOW() WHERE id = :id AND status <> 'resolved'",
                    [':id' => $alertId]
                );
                audit_log('alert_resolve', 'Resolved alert', 'alert', $alertId);
                flash_set('success', 'Alert resolved.');
            } else {
                $snoozeMinutes = match ((string) ($request->input('snooze_minutes') ?? '60')) {
                    '240' => 240,
                    '1440' => 1440,
                    default => 60,
                };
                db_exec(
                    "UPDATE alert_logs SET status = 'silenced', silenced_until = DATE_ADD(NOW(), INTERVAL {$snoozeMinutes} MINUTE)
                     WHERE id = :id AND status IN ('active','acknowledged')",
                    [':id' => $alertId]
                );
                audit_log('alert_silence', 'Snoozed alert', 'alert', $alertId, ['minutes' => $snoozeMinutes]);
                flash_set('success', 'Alert snoozed for ' . ($snoozeMinutes >= 1440 ? '24 hours' : ($snoozeMinutes >= 240 ? '4 hours' : '1 hour')) . '.');
            }
            invalidate_alert_cache();
            redirect('alerts?' . http_build_query($_GET));
        }

        $page = max(1, (int) ($request->query('page') ?? 1));
        $perPage = 20;

        $filterType = trim((string) ($request->query('type') ?? ''));
        $filterSeverity = trim((string) ($request->query('severity') ?? ''));
        $filterStatus = trim((string) ($request->query('status') ?? ''));
        $filterServerId = (int) ($request->query('server_id') ?? 0);
        $filterSearch = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));

        $where = [];
        $params = [];

        if ($filterType !== '') {
            $where[] = 'a.alert_type = :alert_type';
            $params[':alert_type'] = $filterType;
        }
        if ($filterSeverity !== '') {
            $where[] = 'a.severity = :severity';
            $params[':severity'] = $filterSeverity;
        }
        if (in_array($filterStatus, ['active', 'acknowledged', 'resolved', 'silenced'], true)) {
            $where[] = 'a.status = :status';
            $params[':status'] = $filterStatus;
        }
        if ($filterServerId > 0) {
            $where[] = 'a.server_id = :server_id';
            $params[':server_id'] = $filterServerId;
        }
        if ($filterSearch !== '') {
            $where[] = '(a.title LIKE :search OR a.message LIKE :search OR a.alert_type LIKE :search OR s.name LIKE :search)';
            $params[':search'] = '%' . $filterSearch . '%';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Count depends only on filters (not page). Cached under the alert:*
        // prefix so invalidate_alert_cache() clears it together with rows.
        $countCacheKey = 'alert:logs:count:' . md5(json_encode([
            'type' => $filterType,
            'severity' => $filterSeverity,
            'status' => $filterStatus,
            'server_id' => $filterServerId,
            'q' => $filterSearch,
        ], JSON_UNESCAPED_SLASHES));
        $cachedCount = cache_get($countCacheKey);
        if (is_int($cachedCount)) {
            $total = $cachedCount;
        } else {
            $countRow = db_one(
                'SELECT COUNT(*) AS total
                 FROM alert_logs a
                 LEFT JOIN servers s ON s.id = a.server_id
                 ' . $whereSql,
                $params
            );
            $total = (int) ($countRow['total'] ?? 0);
            cache_set($countCacheKey, $total, cache_ttl('cache_ttl_alert_logs', 20));
        }

        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $cacheTtl = cache_ttl('cache_ttl_alert_logs', 20);
        $cacheKey = 'alert:logs:' . md5(json_encode([
            'page' => $page,
            'per' => $perPage,
            'type' => $filterType,
            'severity' => $filterSeverity,
            'status' => $filterStatus,
            'server_id' => $filterServerId,
            'q' => $filterSearch,
        ], JSON_UNESCAPED_SLASHES));
        $cached = cache_get($cacheKey);

        if (is_array($cached) && isset($cached['rows'])) {
            $rows = is_array($cached['rows']) ? $cached['rows'] : [];
        } else {
            $sql = 'SELECT
                        a.id, a.server_id, a.alert_type, a.severity, a.title, a.message, a.context_json,
                        a.status, a.acknowledged_at, a.resolved_at, a.silenced_until,
                        a.sent_email, a.sent_telegram, a.created_at,
                        s.name AS server_name
                    FROM alert_logs a
                    LEFT JOIN servers s ON s.id = a.server_id
                    ' . $whereSql . '
                    ORDER BY a.id DESC
                    LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
            $rows = db_all($sql, $params);
            cache_set($cacheKey, ['rows' => $rows], $cacheTtl);
        }

        $servers = db_all('SELECT id, name FROM servers ORDER BY name ASC');
        $types = db_all('SELECT DISTINCT alert_type FROM alert_logs ORDER BY alert_type ASC');
        $severities = ['info', 'warning', 'danger', 'success'];

        $data = [
            'rows' => $rows,
            'total' => $total,
            'totalPages' => $totalPages,
            'page' => $page,
            'perPage' => $perPage,
            'filterType' => $filterType,
            'filterSeverity' => $filterSeverity,
            'filterStatus' => $filterStatus,
            'filterServerId' => $filterServerId,
            'filterSearch' => $filterSearch,
            'servers' => $servers,
            'types' => $types,
            'severities' => $severities,
            'title' => APP_NAME . ' - Alert Logs',
            'activeNav' => 'alerts',
        ];

        return Response::html(View::render('admin/alert_logs', $data, 'admin'));
    }
}
