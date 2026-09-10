<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Services\Validation\IpRepValidator;
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
            // CSRF single-guard: enforced by csrf middleware.
            [
                'ip_address' => $ipAddress,
                'label' => $label,
                'server_id' => $serverId,
                'check_interval_hours' => $interval,
            ] = IpRepValidator::validateTarget([
                'ip_address' => $request->input('ip_address'),
                'label' => $request->input('label'),
                'server_id' => $request->input('server_id'),
                'check_interval_hours' => $request->input('check_interval_hours'),
            ], $targetId, 'ip-reputation/' . $targetId . '/edit');

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
