<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Services\Validation\ServerValidator;
use App\Support\View;

final class ServerEditController
{
    public function index(Request $request): Response
    {
        require_role('admin');

        $id = (int) ($request->params['id'] ?? 0);
        $server = db_one('SELECT * FROM servers WHERE id = :id LIMIT 1', [':id' => $id]);
        if ($server === null) {
            flash_set('danger', 'Server not found.');
            redirect('servers');
        }

        if (is_post()) {
            // CSRF single-guard: enforced by csrf middleware (routes/web_servers.php).
            $action = (string) ($request->input('action') ?? 'save');
            if ($action === 'regen_token') {
                $newToken = bin2hex(random_bytes(32));
                db_exec('UPDATE servers SET token_hash = :token_hash WHERE id = :id', [':token_hash' => hash('sha256', $newToken), ':id' => $id]);
                $_SESSION['monitors_new_server_token_' . $id] = $newToken;
                invalidate_status_cache($id);
                audit_log('server_regen_token', 'Regenerated server token', 'server', $id);
                flash_set('success', 'Token regenerated successfully.');
                redirect('servers/' . $id . '/edit');
            }

            [
                'name' => $name,
                'location' => $location,
                'host' => $host,
                'type' => $type,
                'provider' => $provider,
                'label' => $label,
            ] = ServerValidator::validateIdentity([
                'name' => $request->input('name'),
                'location' => $request->input('location'),
                'host' => $request->input('host'),
                'type' => $request->input('type'),
                'provider' => $request->input('provider'),
                'label' => $request->input('label'),
            ], 'servers/' . $id . '/edit');
            $notifyEmail = trim((string) ($request->input('notify_email') ?? ''));
            $pushAllowedIps = trim((string) ($request->input('push_allowed_ips') ?? ''));
            $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
            $maintenanceUntilInput = trim((string) ($request->input('maintenance_until') ?? ''));
            $maintenanceUntil = null;
            if ($maintenanceMode === 1 && $maintenanceUntilInput !== '') {
                $ts = strtotime($maintenanceUntilInput);
                if ($ts !== false) {
                    $maintenanceUntil = date('Y-m-d H:i:s', $ts);
                }
            }

            [
                'notify_email' => $notifyEmail,
                'push_allowed_ips' => $pushAllowedIps,
            ] = ServerValidator::validateContact($notifyEmail, $pushAllowedIps, 'servers/' . $id . '/edit');

            db_exec(
                'UPDATE servers
                 SET name = :name, location = :location, host = :host, type = :type, provider = :provider, label = :label, notify_email = :notify_email,
                     maintenance_mode = :maintenance_mode, maintenance_until = :maintenance_until, push_allowed_ips = :push_allowed_ips
                 WHERE id = :id',
                [
                    ':name' => $name,
                    ':location' => $location !== '' ? $location : null,
                    ':host' => $host !== '' ? $host : null,
                    ':type' => $type !== '' ? $type : null,
                    ':provider' => $provider !== '' ? $provider : null,
                    ':label' => $label !== '' ? $label : null,
                    ':notify_email' => $notifyEmail !== '' ? $notifyEmail : null,
                    ':maintenance_mode' => $maintenanceMode,
                    ':maintenance_until' => $maintenanceMode === 1 ? $maintenanceUntil : null,
                    ':push_allowed_ips' => $pushAllowedIps !== '' ? $pushAllowedIps : null,
                    ':id' => $id,
                ]
            );
            invalidate_status_cache($id);
            audit_log(
                'server_update',
                'Updated server settings',
                'server',
                $id,
                [
                    'provider' => $provider,
                    'label' => $label,
                    'maintenance_mode' => $maintenanceMode,
                    'maintenance_until' => $maintenanceUntil,
                    'push_allowed_ips' => $pushAllowedIps,
                ]
            );
            flash_set('success', 'Server changes saved successfully.');
            redirect('servers');
        }

        $sessionTokenKey = 'monitors_new_server_token_' . $id;
        $displayToken = trim((string) ($_SESSION[$sessionTokenKey] ?? ''));

        $data = [
            'id' => $id,
            'server' => $server,
            'displayToken' => $displayToken,
            'title' => APP_NAME . ' - Edit Server',
            'activeNav' => 'servers',
        ];
        return Response::html(View::render('admin/server_edit', $data, 'admin'));
    }
}
