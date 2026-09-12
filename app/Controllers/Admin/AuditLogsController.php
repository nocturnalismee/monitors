<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class AuditLogsController
{
    public function index(Request $request): Response
    {
        require_role('admin');

        if (is_post()) {
            // CSRF single-guard: enforced by csrf middleware.
            $action = (string) ($request->input('action') ?? '');
            if ($action !== 'batch_delete') {
                flash_set('danger', 'Invalid audit action.');
                redirect('audit-logs');
            }
            $ids = [];
            $rawIds = $request->input('audit_ids');
            if (is_array($rawIds)) {
                foreach ($rawIds as $value) {
                    $id = (int) $value;
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
            }
            if (count($ids) === 0) {
                flash_set('danger', 'No audit logs selected.');
                redirect('audit-logs?' . http_build_query($_GET));
            }
            $params = [];
            foreach ($ids as $i => $id) {
                $params[':id' . $i] = $id;
            }
            $placeholders = implode(',', array_keys($params));
            db_exec("DELETE FROM admin_audit_logs WHERE id IN ({$placeholders})", $params);
            $count = count($ids);
            audit_log(
                'audit_logs_batch_delete',
                "Bulk deleted {$count} audit log(s)",
                'audit',
                null,
                ['audit_ids' => array_values($ids)]
            );
            cache_delete_pattern('audit:logs:*');
            flash_set('success', "{$count} audit log(s) deleted successfully.");
            redirect('audit-logs?' . http_build_query($_GET));
        }

        $page = max(1, (int) ($request->query('page') ?? 1));
        $perPage = 25;

        $filterAction = trim((string) ($request->query('action_type') ?? ''));
        $filterUserId = (int) ($request->query('user_id') ?? 0);
        $filterDateFrom = trim((string) ($request->query('date_from') ?? ''));
        $filterDateTo = trim((string) ($request->query('date_to') ?? ''));

        $where = [];
        $params = [];
        if ($filterAction !== '') {
            $where[] = 'a.action_type = :action_type';
            $params[':action_type'] = $filterAction;
        }
        if ($filterUserId > 0) {
            $where[] = 'a.user_id = :user_id';
            $params[':user_id'] = $filterUserId;
        }
        if ($filterDateFrom !== '') {
            $where[] = 'a.created_at >= :date_from';
            $params[':date_from'] = $filterDateFrom . ' 00:00:00';
        }
        if ($filterDateTo !== '') {
            $where[] = 'a.created_at <= :date_to';
            $params[':date_to'] = $filterDateTo . ' 23:59:59';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Count depends only on filters (not page). Nested under audit:logs:*
        // so the existing invalidation pattern clears it together with rows.
        $countCacheKey = 'audit:logs:count:' . md5(json_encode([
            'action_type' => $filterAction,
            'user_id' => $filterUserId,
            'date_from' => $filterDateFrom,
            'date_to' => $filterDateTo,
        ], JSON_UNESCAPED_SLASHES));
        $cachedCount = cache_get($countCacheKey);
        if (is_int($cachedCount)) {
            $total = $cachedCount;
        } else {
            $countRow = db_one(
                'SELECT COUNT(*) AS total FROM admin_audit_logs a ' . $whereSql,
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

        $cacheKey = 'audit:logs:' . md5(json_encode([
            'page' => $page,
            'per' => $perPage,
            'action_type' => $filterAction,
            'user_id' => $filterUserId,
            'date_from' => $filterDateFrom,
            'date_to' => $filterDateTo,
        ], JSON_UNESCAPED_SLASHES));
        $cached = cache_get($cacheKey);

        if (is_array($cached) && isset($cached['rows'])) {
            $rows = is_array($cached['rows']) ? $cached['rows'] : [];
        } else {
            $rows = db_all(
                'SELECT
                    a.id, a.user_id, COALESCE(a.username, "system") AS username,
                    a.action_type, a.action_detail, a.target_type, a.target_id,
                    a.context_json, a.ip_address, a.created_at
                 FROM admin_audit_logs a
                 ' . $whereSql . '
                 ORDER BY a.id DESC
                 LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
                $params
            );
            cache_set($cacheKey, ['rows' => $rows], cache_ttl('cache_ttl_alert_logs', 20));
        }

        $actionTypes = db_all('SELECT DISTINCT action_type FROM admin_audit_logs ORDER BY action_type ASC');
        $users = db_all('SELECT id, username FROM users ORDER BY username ASC');

        $data = [
            'rows' => $rows,
            'total' => $total,
            'totalPages' => $totalPages,
            'page' => $page,
            'perPage' => $perPage,
            'filterAction' => $filterAction,
            'filterUserId' => $filterUserId,
            'filterDateFrom' => $filterDateFrom,
            'filterDateTo' => $filterDateTo,
            'actionTypes' => $actionTypes,
            'users' => $users,
            'title' => APP_NAME . ' - Audit Logs',
            'activeNav' => 'audit',
        ];

        return Response::html(View::render('admin/audit_logs', $data, 'admin'));
    }
}
