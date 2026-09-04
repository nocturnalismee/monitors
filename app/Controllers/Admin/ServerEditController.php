<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
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
            if (!csrf_validate($request->input('_csrf_token'))) {
                flash_set('danger', 'Invalid CSRF token.');
                redirect('servers/' . $id . '/edit');
            }

            $action = (string) ($request->input('action') ?? 'save');
            if ($action === 'regen_token') {
                $newToken = bin2hex(random_bytes(32));
                if (db_column_exists('servers', 'token_hash')) {
                    db_exec('UPDATE servers SET token = NULL, token_hash = :token_hash WHERE id = :id', [':token_hash' => hash('sha256', $newToken), ':id' => $id]);
                } else {
                    db_exec('UPDATE servers SET token = :token WHERE id = :id', [':token' => $newToken, ':id' => $id]);
                }
                $_SESSION['servmon_new_server_token_' . $id] = $newToken;
                invalidate_status_cache($id);
                audit_log('server_regen_token', 'Regenerated server token', 'server', $id);
                flash_set('success', 'Token regenerated successfully.');
                redirect('servers/' . $id . '/edit');
            }

            $name = trim((string) ($request->input('name') ?? ''));
            $location = trim((string) ($request->input('location') ?? ''));
            $host = trim((string) ($request->input('host') ?? ''));
            $type = trim((string) ($request->input('type') ?? ''));
            $provider = trim((string) ($request->input('provider') ?? ''));
            $label = trim((string) ($request->input('label') ?? ''));
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

            if ($name === '') {
                flash_set('danger', 'Server name is required.');
                redirect('servers/' . $id . '/edit');
            }
            if (mb_strlen($name) > 100) {
                flash_set('danger', 'Server name must not exceed 100 characters.');
                redirect('servers/' . $id . '/edit');
            }
            if (mb_strlen($location) > 100) {
                flash_set('danger', 'Location must not exceed 100 characters.');
                redirect('servers/' . $id . '/edit');
            }
            if (mb_strlen($host) > 100) {
                flash_set('danger', 'Host must not exceed 100 characters.');
                redirect('servers/' . $id . '/edit');
            }
            if (mb_strlen($type) > 50) {
                flash_set('danger', 'Type must not exceed 50 characters.');
                redirect('servers/' . $id . '/edit');
            }
            if (mb_strlen($provider) > 100) {
                flash_set('danger', 'Provider must not exceed 100 characters.');
                redirect('servers/' . $id . '/edit');
            }
            if (mb_strlen($label) > 100) {
                flash_set('danger', 'Label must not exceed 100 characters.');
                redirect('servers/' . $id . '/edit');
            }
            if ($notifyEmail !== '') {
                if (mb_strlen($notifyEmail) > 255) {
                    flash_set('danger', 'Notify email must not exceed 255 characters.');
                    redirect('servers/' . $id . '/edit');
                }
                if (filter_var($notifyEmail, FILTER_VALIDATE_EMAIL) === false) {
                    flash_set('danger', 'Notify email is not a valid email address.');
                    redirect('servers/' . $id . '/edit');
                }
            }
            $v = \App\Services\Security\PushAllowlistValidator::validate((string)($pushAllowedIps ?? ''));
            if (!$v['valid']) { flash_set('danger', $v['error']); redirect('servers/'.$id.'/edit'); }
            $pushAllowedIps = $v['normalized'] ?? '';

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

        $sessionTokenKey = 'servmon_new_server_token_' . $id;
        $displayToken = trim((string) ($server['token'] ?? ''));
        if ($displayToken === '') {
            $displayToken = trim((string) ($_SESSION[$sessionTokenKey] ?? ''));
        }

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
