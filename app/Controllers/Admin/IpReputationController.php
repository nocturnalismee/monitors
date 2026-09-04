<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class IpReputationController
{
    public function index(Request $request): Response
    {
        require_login();
        $canManage = has_role('admin');

        if ($request->isPost()) {
            require_role('admin');
            // CSRF single-guard: enforced by csrf middleware.
            $targetId = (int) ($request->input('target_id') ?? 0);
            $action   = (string) ($request->input('action') ?? '');
            if ($targetId <= 0) {
                flash_set('danger', 'Invalid IP reputation target.');
                redirect('ip-reputation');
            }

            if ($action === 'toggle') {
                db_exec('UPDATE ip_reputation_targets SET active = IF(active = 1, 0, 1) WHERE id = :id', [':id' => $targetId]);
                invalidate_ip_rep_cache($targetId);
                audit_log('ip_rep_toggle', 'Toggled IP reputation target active status', 'ip_rep_target', $targetId);
                flash_set('success', 'IP reputation target status updated.');
            } elseif ($action === 'delete') {
                db_exec('DELETE FROM ip_reputation_targets WHERE id = :id', [':id' => $targetId]);
                invalidate_ip_rep_cache($targetId);
                audit_log('ip_rep_delete', 'Deleted IP reputation target and history', 'ip_rep_target', $targetId);
                flash_set('success', 'IP reputation target deleted.');
            }

            redirect('ip-reputation');
        }

        $q = trim((string) ($request->query('q') ?? ''));
        $statusFilter = strtolower(trim((string) ($request->query('status') ?? 'all')));
        $statusFilter = in_array($statusFilter, ['all', 'clean', 'listed', 'unknown', 'paused'], true) ? $statusFilter : 'all';

        $where  = [];
        $params = [];

        if ($q !== '') {
            $where[] = '(t.ip_address LIKE :q OR t.label LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        if ($statusFilter === 'clean') {
            $where[] = 't.active = 1 AND COALESCE(s.overall_status, "unknown") = "clean"';
        } elseif ($statusFilter === 'listed') {
            $where[] = 't.active = 1 AND s.overall_status = "listed"';
        } elseif ($statusFilter === 'unknown') {
            $where[] = 't.active = 1 AND COALESCE(s.overall_status, "unknown") = "unknown"';
        } elseif ($statusFilter === 'paused') {
            $where[] = 't.active = 0';
        }

        $sql = 'SELECT t.id, t.ip_address, t.label, t.server_id, t.active, t.check_interval_hours,
                       s.overall_status, s.listed_count, s.total_checked, s.listed_on, s.provider_results,
                       s.last_checked_at, s.last_change_at,
                       sv.name AS server_name
                FROM ip_reputation_targets t
                LEFT JOIN ip_reputation_states s ON s.target_id = t.id
                LEFT JOIN servers sv ON sv.id = t.server_id';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.created_at DESC, t.id DESC';

        $rows = db_all($sql, $params);

        $summary = ['total' => 0, 'clean' => 0, 'listed' => 0, 'unknown' => 0, 'paused' => 0];
        foreach ($rows as &$row) {
            $status = ip_rep_display_status((string) ($row['overall_status'] ?? 'unknown'), (int) ($row['active'] ?? 0));
            $row['display_status'] = $status;
            $row['listed_on_arr'] = json_decode((string) ($row['listed_on'] ?? '[]'), true) ?: [];
            $row['provider_results_arr'] = json_decode((string) ($row['provider_results'] ?? '{}'), true) ?: [];
            $summary['total']++;
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }
        unset($row);

        $data = [
            'rows'         => $rows,
            'summary'      => $summary,
            'q'            => $q,
            'statusFilter' => $statusFilter,
            'canManage'    => $canManage,
            'title'        => APP_NAME . ' - IP Reputation',
            'activeNav'    => 'ip_reputation',
        ];

        return Response::html(View::render('admin/ip_reputation', $data, 'admin'));
    }
}
