<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class ServerSetupController
{
    public function index(Request $request): Response
    {
        require_role('admin');

        $id = (int) ($request->params['id'] ?? 0);
        $server = db_one('SELECT id, name, token, token_hash FROM servers WHERE id = :id LIMIT 1', [':id' => $id]);
        if ($server === null) {
            flash_set('danger', 'Server not found.');
            redirect('servers');
        }
        $sessionTokenKey = 'servmon_new_server_token_' . $id;
        $displayToken = trim((string) ($server['token'] ?? ''));
        if ($displayToken === '') {
            $displayToken = trim((string) ($_SESSION[$sessionTokenKey] ?? ''));
        }

        $pushEndpoint = app_url('api/push');
        $agentUrl = app_url('agents/monitoring-agent.sh');
        $agentEmailUrl = app_url('agents/monitoring-agent-cpanel-mail.sh');
        $systemdServiceUrl = app_url('agents/systemd/monitoring-agent.service');
        $systemdTimerUrl = app_url('agents/systemd/monitoring-agent.timer');
        $confExampleUrl = app_url('agents/systemd/monitoring-agent.conf.example');
        $systemdEmailServiceUrl = app_url('agents/systemd/monitoring-agent-cpanel-email.service');
        $systemdEmailTimerUrl = app_url('agents/systemd/monitoring-agent-cpanel-email.timer');
        $confEmailExampleUrl = app_url('agents/systemd/monitoring-agent-cpanel-mail.conf.example');

        $data = [
            'id' => $id,
            'server' => $server,
            'displayToken' => $displayToken,
            'pushEndpoint' => $pushEndpoint,
            'agentUrl' => $agentUrl,
            'agentEmailUrl' => $agentEmailUrl,
            'systemdServiceUrl' => $systemdServiceUrl,
            'systemdTimerUrl' => $systemdTimerUrl,
            'confExampleUrl' => $confExampleUrl,
            'systemdEmailServiceUrl' => $systemdEmailServiceUrl,
            'systemdEmailTimerUrl' => $systemdEmailTimerUrl,
            'confEmailExampleUrl' => $confEmailExampleUrl,
            'title' => APP_NAME . ' - Agent Instructions',
            'activeNav' => 'servers',
        ];
        return Response::html(View::render('admin/server_setup', $data, 'admin'));
    }
}
