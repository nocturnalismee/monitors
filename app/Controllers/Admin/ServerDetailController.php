<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class ServerDetailController
{
    public function index(Request $request): Response
    {
        require_login();

        $id = (int) ($request->params['id'] ?? 0);
        if ($id <= 0) {
            flash_set('danger', 'Invalid server.');
            redirect('servers');
        }

        $server = db_one(
            'SELECT s.id, s.name, s.location, s.provider, s.label, s.host, s.type, s.agent_mode, s.active, s.maintenance_mode, s.maintenance_until,
                    COALESCE(s.last_seen_at, m.recorded_at) AS last_seen, m.uptime, m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.cpu_load, m.mail_mta, m.mail_queue_total,
                    m.network_in_bps, m.network_out_bps, m.panel_profile
             FROM servers s' . latest_metric_join_sql('s', 'm') . '
             WHERE s.id = :id LIMIT 1',
            [':id' => $id]
        );
        if ($server === null) {
            flash_set('danger', 'Server not found.');
            redirect('servers');
        }

        $statusOnlineMinutes = max(1, (int) setting_get('alert_down_minutes'));
        $mailQueueWarnThreshold = max(0, (int) setting_get('threshold_mail_queue'));
        $mailQueueCriticalThreshold = max(
            $mailQueueWarnThreshold,
            (int) setting_get('threshold_mail_queue_critical')
        );
        $status = serverStatusFromLastSeen($server['last_seen'] ?? null, (int) ($server['active'] ?? 0) === 1, $statusOnlineMinutes);
        $ramPct = calculateUsagePercent((int) ($server['ram_used'] ?? 0), (int) ($server['ram_total'] ?? 0));
        $hddPct = calculateUsagePercent((int) ($server['hdd_used'] ?? 0), (int) ($server['hdd_total'] ?? 0));
        $serverMetaItems = [
            ['label' => 'Host', 'value' => (string) ($server['host'] ?? '-')],
            ['label' => 'Location', 'value' => (string) ($server['location'] ?? '-')],
            ['label' => 'Provider', 'value' => (string) ($server['provider'] ?? '-')],
            ['label' => 'Type', 'value' => (string) ($server['type'] ?? '-')],
            ['label' => 'Label', 'value' => (string) ($server['label'] ?? '-')],
            ['label' => 'Panel', 'value' => (string) ($server['panel_profile'] ?? 'generic')],
        ];
        $services = db_all(
            'SELECT service_group, service_key, unit_name, last_status, updated_at
             FROM server_service_states
             WHERE server_id = :id
             ORDER BY service_group ASC, service_key ASC',
            [':id' => $id]
        );
        $historyEndpoint = app_url('api/status?id=' . $id . '&history=30m&points=1200');
        $historyBootstrap = db_all(
            'SELECT
                DATE_FORMAT(recorded_at, "%Y-%m-%d %H:%i:%s") AS recorded_at,
                ram_used, hdd_used, cpu_load, network_in_bps, network_out_bps
             FROM metrics
             WHERE server_id = :id AND recorded_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
             ORDER BY recorded_at ASC
             LIMIT 1200',
            [':id' => $id]
        );

        $cpuWarnThreshold = max(0.0, (float) setting_get('threshold_cpu_load'));
        $cpuCriticalThreshold = max($cpuWarnThreshold, (float) setting_get('threshold_cpu_load_critical'));

        $data = [
            'id' => $id,
            'server' => $server,
            'statusOnlineMinutes' => $statusOnlineMinutes,
            'cpuWarnThreshold' => $cpuWarnThreshold,
            'cpuCriticalThreshold' => $cpuCriticalThreshold,
            'mailQueueWarnThreshold' => $mailQueueWarnThreshold,
            'mailQueueCriticalThreshold' => $mailQueueCriticalThreshold,
            'status' => $status,
            'ramPct' => $ramPct,
            'hddPct' => $hddPct,
            'serverMetaItems' => $serverMetaItems,
            'services' => $services,
            'historyEndpoint' => $historyEndpoint,
            'historyBootstrap' => $historyBootstrap,
            'title' => APP_NAME . ' - Server Details',
            'activeNav' => 'servers',
        ];
        return Response::html(View::render('admin/server_detail', $data, 'admin'));
    }
}
