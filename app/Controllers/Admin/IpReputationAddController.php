<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Services\Validation\IpRepValidator;
use App\Support\View;
use Throwable;

final class IpReputationAddController
{
    public function index(Request $request): Response
    {
        require_login();
        require_role('admin');

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
            ], null, 'ip-reputation/add');
            $checkNow  = $request->input('check_now') !== null;

            db_exec(
                'INSERT INTO ip_reputation_targets (ip_address, label, server_id, check_interval_hours) VALUES (:ip, :label, :server_id, :interval)',
                [
                    ':ip'        => $ipAddress,
                    ':label'     => $label !== '' ? $label : null,
                    ':server_id' => $serverId > 0 ? $serverId : null,
                    ':interval'  => $interval,
                ]
            );

            $newId = (int) db()->lastInsertId();
            audit_log('ip_rep_add', 'Added IP reputation target: ' . $ipAddress, 'ip_rep_target', $newId);

            if ($checkNow && $newId > 0) {
                try {
                    $result = ip_rep_check_single($ipAddress, []);
                    ip_rep_record_check($newId, $result);
                    invalidate_ip_rep_cache($newId);
                    flash_set('success', 'IP reputation target added and initial check completed. Status: ' . strtoupper($result['overall_status']));
                } catch (Throwable $e) {
                    flash_set('warning', 'IP reputation target added, but initial check failed: ' . $e->getMessage());
                }
            } else {
                flash_set('success', 'IP reputation target added successfully.');
            }

            redirect('ip-reputation');
        }

        $data = [
            'servers'   => $servers,
            'title'     => APP_NAME . ' - Add IP Reputation Target',
            'activeNav' => 'ip_reputation',
        ];

        return Response::html(View::render('admin/ip_reputation_add', $data, 'admin'));
    }
}
