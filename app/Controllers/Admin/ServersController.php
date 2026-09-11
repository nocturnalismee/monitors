<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class ServersController
{
    public function index(Request $request): Response
    {
        require_login();
        $canManageServers = has_role('admin');
        $statusOnlineMinutes = max(1, (int) setting_get('alert_down_minutes'));

        if (is_post()) {
            require_role('admin');
            // CSRF single-guard: enforced by csrf middleware.
            $action = (string) ($request->input('action') ?? '');
            $serverIds = [];
            $serverIdsInput = $request->input('server_ids');
            if (is_array($serverIdsInput)) {
                foreach ($serverIdsInput as $value) {
                    $id = (int) $value;
                    if ($id > 0) {
                        $serverIds[$id] = $id;
                    }
                }
            } else {
                $id = (int) ($request->input('server_id') ?? 0);
                if ($id > 0) {
                    $serverIds[$id] = $id;
                }
            }
            if (count($serverIds) === 0) {
                flash_set('danger', 'Invalid server.');
                redirect('servers');
            }
            $serverId = array_key_first($serverIds);

            if ($action === 'toggle') {
                $before = db_one('SELECT id, name, active FROM servers WHERE id = :id LIMIT 1', [':id' => $serverId]);
                db_exec('UPDATE servers SET active = IF(active = 1, 0, 1) WHERE id = :id', [':id' => $serverId]);
                $after = db_one('SELECT id, name, active FROM servers WHERE id = :id LIMIT 1', [':id' => $serverId]);
                invalidate_status_cache($serverId);
                audit_log('server_toggle_active', 'Toggled server active status', 'server', $serverId);
                if ($after !== null) {
                    $actor = current_user();
                    $actorName = (string) ($actor['username'] ?? 'system');
                    $serverName = (string) ($after['name'] ?? ('Server #' . $serverId));
                    $isActive = (int) ($after['active'] ?? 0) === 1;
                    $alertType = $isActive ? 'server_enabled' : 'server_disabled';
                    $severity = $isActive ? 'success' : 'warning';
                    $title = '[' . $serverName . '] Server ' . ($isActive ? 'Enabled' : 'Disabled');
                    $message = 'Server monitoring was ' . ($isActive ? 'enabled' : 'disabled') . ' from Server Management by ' . $actorName . '.';
                    db_exec(
                        'INSERT INTO alert_logs
                        (server_id, alert_type, severity, title, message, context_json, sent_email, sent_telegram, created_at)
                        VALUES
                        (:server_id, :alert_type, :severity, :title, :message, :context_json, 0, 0, NOW())',
                        [
                            ':server_id' => $serverId,
                            ':alert_type' => $alertType,
                            ':severity' => $severity,
                            ':title' => $title,
                            ':message' => $message,
                            ':context_json' => json_encode(
                                [
                                    'source' => 'admin_server_toggle',
                                    'previous_active' => (int) ($before['active'] ?? 0),
                                    'current_active' => (int) ($after['active'] ?? 0),
                                    'actor' => $actorName,
                                ],
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            ),
                        ]
                    );
                    invalidate_alert_cache();
                }
                flash_set('success', 'Server active status updated.');
            } elseif ($action === 'delete') {
                db_exec('DELETE FROM metrics WHERE server_id = :id', [':id' => $serverId]);
                db_exec('DELETE FROM servers WHERE id = :id', [':id' => $serverId]);
                invalidate_status_cache($serverId);
                audit_log('server_delete', 'Deleted server and related metrics', 'server', $serverId);
                flash_set('success', 'Server and related metrics deleted successfully.');
            } elseif ($action === 'batch_enable' || $action === 'batch_disable') {
                $setActive = $action === 'batch_enable' ? 1 : 0;
                $params = [];
                foreach ($serverIds as $i => $id) {
                    $params[':id' . $i] = $id;
                }
                $placeholders = implode(',', array_keys($params));
                db_exec("UPDATE servers SET active = {$setActive} WHERE id IN ({$placeholders})", $params);
                foreach ($serverIds as $id) {
                    invalidate_status_cache($id);
                }
                $count = count($serverIds);
                $verb = $setActive === 1 ? 'enabled' : 'disabled';
                audit_log(
                    $setActive === 1 ? 'servers_batch_enable' : 'servers_batch_disable',
                    "Bulk {$verb} {$count} server(s)",
                    'server',
                    null,
                    ['server_ids' => array_values($serverIds)]
                );
                flash_set('success', "{$count} server(s) {$verb}.");
            } elseif ($action === 'batch_delete') {
                $params = [];
                foreach ($serverIds as $i => $id) {
                    $params[':id' . $i] = $id;
                }
                $placeholders = implode(',', array_keys($params));
                db_exec("DELETE FROM metrics WHERE server_id IN ({$placeholders})", $params);
                db_exec("DELETE FROM servers WHERE id IN ({$placeholders})", $params);
                foreach ($serverIds as $id) {
                    invalidate_status_cache($id);
                }
                $count = count($serverIds);
                audit_log(
                    'servers_batch_delete',
                    "Bulk deleted {$count} server(s) and related metrics",
                    'server',
                    null,
                    ['server_ids' => array_values($serverIds)]
                );
                flash_set('success', "{$count} server(s) deleted successfully.");
            }

            redirect('servers');
        }

        $page = max(1, (int) ($request->query('page') ?? 1));
        $perPage = max(10, min(200, (int) ($request->query('per_page') ?? 50)));
        $offset = ($page - 1) * $perPage;
        $totalRow = db_one('SELECT COUNT(*) AS cnt FROM servers');
        $totalServers = (int) ($totalRow['cnt'] ?? 0);
        $totalPages = (int) ceil($totalServers / $perPage);

        $rows = db_all(
            'SELECT s.id, s.name, s.location, s.provider, s.label, s.host, s.type, s.agent_mode, s.active, s.maintenance_mode, s.maintenance_until,
                    COALESCE(s.last_seen_at, m.recorded_at) AS last_seen, m.cpu_load, m.panel_profile,
                    COALESCE(ss.up_count, 0) AS service_up_count,
                    COALESCE(ss.down_count, 0) AS service_down_count,
                    COALESCE(ss.unknown_count, 0) AS service_unknown_count
             FROM servers s' . latest_metric_join_sql('s', 'm') . '
             LEFT JOIN (
                 SELECT server_id, SUM(last_status = "up") AS up_count, SUM(last_status = "down") AS down_count, SUM(last_status = "unknown") AS unknown_count
                 FROM server_service_states
                 GROUP BY server_id
             ) ss ON ss.server_id = s.id
             ORDER BY s.created_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );

        $data = [
            'rows' => $rows,
            'canManageServers' => $canManageServers,
            'statusOnlineMinutes' => $statusOnlineMinutes,
            'page' => $page,
            'perPage' => $perPage,
            'totalServers' => $totalServers,
            'totalPages' => $totalPages,
            'title' => APP_NAME . ' - Server Management',
            'activeNav' => 'servers',
        ];
        return Response::html(View::render('admin/servers', $data, 'admin'));
    }
}
