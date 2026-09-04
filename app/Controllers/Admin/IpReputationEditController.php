<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class IpReputationEditController
{
    public function index(Request $request): Response
    {
        require_login();
        require_role('admin');

        $targetId = (int) ($request->params['id'] ?? $request->input('target_id') ?? 0);
        if ($targetId <= 0) {
            flash_set('danger', 'Invalid target ID.');
            redirect('ip-reputation');
        }

        $target = db_one('SELECT * FROM ip_reputation_targets WHERE id = :id', [':id' => $targetId]);
        if ($target === null) {
            flash_set('danger', 'Target not found.');
            redirect('ip-reputation');
        }

        $servers = db_all('SELECT id, name FROM servers ORDER BY name ASC');

        if ($request->isPost()) {
            if (!csrf_validate($request->input('_csrf_token'))) {
                flash_set('danger', 'Invalid CSRF token.');
                redirect('ip-reputation/' . $targetId . '/edit');
            }

            $ipAddress = trim((string) ($request->input('ip_address') ?? ''));
            $label     = trim((string) ($request->input('label') ?? ''));
            $serverId  = (int) ($request->input('server_id') ?? 0);
            $interval  = max(1, (int) ($request->input('check_interval_hours') ?? 6));

            if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
                flash_set('danger', 'Invalid IP address format.');
                redirect('ip-reputation/' . $targetId . '/edit');
            }

            $existing = db_one('SELECT id FROM ip_reputation_targets WHERE ip_address = :ip AND id != :id', [':ip' => $ipAddress, ':id' => $targetId]);
            if ($existing !== null) {
                flash_set('danger', 'IP address ' . htmlspecialchars($ipAddress) . ' is already monitored by another target.');
                redirect('ip-reputation/' . $targetId . '/edit');
            }

            db_exec(
                'UPDATE ip_reputation_targets SET ip_address = :ip, label = :label, server_id = :server_id, check_interval_hours = :interval WHERE id = :id',
                [
                    ':ip'        => $ipAddress,
                    ':label'     => $label !== '' ? $label : null,
                    ':server_id' => $serverId > 0 ? $serverId : null,
                    ':interval'  => $interval,
                    ':id'        => $targetId,
                ]
            );

            invalidate_ip_rep_cache($targetId);
            audit_log('ip_rep_edit', 'Updated IP reputation target: ' . $ipAddress, 'ip_rep_target', $targetId);
            flash_set('success', 'IP reputation target updated.');
            redirect('ip-reputation');
        }

        $data = [
            'target'    => $target,
            'targetId'  => $targetId,
            'servers'   => $servers,
            'title'     => APP_NAME . ' - Edit IP Reputation Target',
            'activeNav' => 'ip_reputation',
        ];

        return Response::html(View::render('admin/ip_reputation_edit', $data, 'admin'));
    }
}
