<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Services\Validation\ServerValidator;
use App\Support\View;

final class ServerAddController
{
    public function index(Request $request): Response
    {
        require_role('admin');

        if (is_post()) {
            // CSRF single-guard: enforced by csrf middleware (routes/web_servers.php).
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
            ], 'servers/add');
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
