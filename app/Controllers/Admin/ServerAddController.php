<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class ServerAddController
{
    public function index(Request $request): Response
    {
        require_role('admin');

        if (is_post()) {
            // CSRF single-guard: enforced by csrf middleware (routes/web_servers.php).
            $name = trim((string) ($request->input('name') ?? ''));
            $location = trim((string) ($request->input('location') ?? ''));
            $host = trim((string) ($request->input('host') ?? ''));
            $type = trim((string) ($request->input('type') ?? ''));
            $provider = trim((string) ($request->input('provider') ?? ''));
            $label = trim((string) ($request->input('label') ?? ''));

            if ($name === '') {
                flash_set('danger', 'Server name is required.');
                redirect('servers/add');
            }
            if (mb_strlen($name) > 100) {
                flash_set('danger', 'Server name must not exceed 100 characters.');
                redirect('servers/add');
            }
            if (mb_strlen($location) > 100) {
                flash_set('danger', 'Location must not exceed 100 characters.');
                redirect('servers/add');
            }
            if (mb_strlen($host) > 100) {
                flash_set('danger', 'Host must not exceed 100 characters.');
                redirect('servers/add');
            }
            if (mb_strlen($type) > 50) {
                flash_set('danger', 'Type must not exceed 50 characters.');
                redirect('servers/add');
            }
            if (mb_strlen($provider) > 100) {
                flash_set('danger', 'Provider must not exceed 100 characters.');
                redirect('servers/add');
            }
            if (mb_strlen($label) > 100) {
                flash_set('danger', 'Label must not exceed 100 characters.');
                redirect('servers/add');
            }
            $token = bin2hex(random_bytes(32));
            $params = [
                ':name' => $name, ':url' => null,
                ':location' => $location !== '' ? $location : null,
                ':host' => $host !== '' ? $host : null,
                ':type' => $type !== '' ? $type : null,
                ':provider' => $provider !== '' ? $provider : null,
                ':label' => $label !== '' ? $label : null,
                ':agent_mode' => 'push',
                ':token_hash' => hash('sha256', $token),
            ];
            db_exec('INSERT INTO servers (name, url, location, host, type, provider, label, agent_mode, token_hash, active, created_at) VALUES (:name, :url, :location, :host, :type, :provider, :label, :agent_mode, :token_hash, 1, NOW())', $params);

            $id = (int) db()->lastInsertId();
            $_SESSION['servmon_new_server_token_' . $id] = $token;
            invalidate_status_cache();
            audit_log('server_add', 'Created new server', 'server', $id, [
                'name' => $name,
                'agent_mode' => 'push',
                'location' => $location,
                'type' => $type,
                'label' => $label,
                'provider' => $provider,
            ]);
            flash_set('success', 'Server added successfully.');
            redirect('servers/' . $id . '/setup');
        }

        $data = [
            'title' => APP_NAME . ' - Add Server',
            'activeNav' => 'servers',
        ];
        return Response::html(View::render('admin/server_add', $data, 'admin'));
    }
}
