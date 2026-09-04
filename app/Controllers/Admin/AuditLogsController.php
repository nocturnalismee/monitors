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

        $countRow = db_one(
            'SELECT COUNT(*) AS total FROM admin_audit_logs a ' . $whereSql,
            $params
        );
        $total = (int) ($countRow['total'] ?? 0);

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
