<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class IpReputationAddController
{
    public function index(Request $request): Response
    {
        require_login();
        require_role('admin');

        $servers = db_all('SELECT id, name FROM servers ORDER BY name ASC');

        if ($request->isPost()) {
            // CSRF single-guard: enforced by csrf middleware.
            $ipAddress = trim((string) ($request->input('ip_address') ?? ''));
            $label     = trim((string) ($request->input('label') ?? ''));
            $serverId  = (int) ($request->input('server_id') ?? 0);
            $interval  = max(1, (int) ($request->input('check_interval_hours') ?? 6));
            $checkNow  = $request->input('check_now') !== null;

            if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
                flash_set('danger', 'Invalid IP address format.');
                redirect('ip-reputation/add');
            }

            $existing = db_one('SELECT id FROM ip_reputation_targets WHERE ip_address = :ip', [':ip' => $ipAddress]);
            if ($existing !== null) {
                flash_set('danger', 'IP address ' . htmlspecialchars($ipAddress) . ' is already monitored.');
                redirect('ip-reputation/add');
            }

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
