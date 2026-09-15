<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class ExportController
{
    /**
     * Synchronous downloads are capped: building a 100k-row payload in
     * memory per request is an OOM vector reachable from a plain URL.
     * Full datasets go through the queued export below (streams keyset 1000).
     */
    private const SYNC_EXPORT_MAX_ROWS = 5000;

    public function index(Request $request): Response
    {
        require_role('admin');

        if ($request->isPost()) {
            $action = (string) ($request->input('action') ?? '');
            if ($action === 'queue_export') {
                // CSRF single-guard: enforced by csrf middleware.
                $jobType = (string) ($request->input('type') ?? '');
                $jobFormat = (string) ($request->input('format') ?? 'csv');
                if (!in_array($jobType, ['alerts', 'metrics', 'services', 'audits'], true)) {
                    flash_set('danger', 'Invalid export type.');
                    redirect('export');
                }
                if (!in_array($jobFormat, ['csv', 'json'], true)) {
                    $jobFormat = 'csv';
                }
                $user = current_user();
                $jobId = export_job_create((int) $user['id'], $jobType, $jobFormat);
                audit_log('export_queued', 'Large export queued', 'export_job', $jobId, ['type' => $jobType, 'format' => $jobFormat]);
                flash_set('success', 'Export queued. Run the export-worker via cron to process it.');
                redirect('export');
            }
        }

        $type = (string) ($request->query('type') ?? '');
        $format = (string) ($request->query('format') ?? 'csv');
        $exportLimit = self::SYNC_EXPORT_MAX_ROWS;

        if (in_array($type, ['alerts', 'metrics', 'services', 'audits'], true)) {
            $format = in_array($format, ['csv', 'json'], true) ? $format : 'csv';
            audit_log('export_download', 'Export requested', 'export', null, ['type' => $type, 'format' => $format]);

            if ($type === 'alerts') {
                $filterType = trim((string) ($request->query('alert_type') ?? ''));
                $filterSeverity = trim((string) ($request->query('severity') ?? ''));
                $filterStatus = trim((string) ($request->query('status') ?? ''));
                $filterServerId = (int) ($request->query('server_id') ?? 0);

                $where = [];
                $params = [];
                if ($filterType !== '') {
                    $where[] = 'a.alert_type = :alert_type';
                    $params[':alert_type'] = $filterType;
                }
                if ($filterSeverity !== '') {
                    $where[] = 'a.severity = :severity';
                    $params[':severity'] = $filterSeverity;
                }
                if (in_array($filterStatus, ['active', 'acknowledged', 'resolved', 'silenced'], true)) {
                    $where[] = 'a.status = :status';
                    $params[':status'] = $filterStatus;
                }
                if ($filterServerId > 0) {
                    $where[] = 'a.server_id = :server_id';
                    $params[':server_id'] = $filterServerId;
                }
                $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

                $rows = db_all(
                    'SELECT
                        a.id, a.server_id, COALESCE(s.name, "global") AS server_name,
                        a.alert_type, a.severity, a.title, a.message, a.context_json,
                        a.status, a.acknowledged_at, a.resolved_at, a.silenced_until,
                        a.sent_email, a.sent_telegram, a.created_at
                     FROM alert_logs a
                     LEFT JOIN servers s ON s.id = a.server_id
                     ' . $whereSql . '
                     ORDER BY a.id DESC
                     LIMIT ' . $exportLimit,
                    $params
                );

                return $this->downloadResponse($rows, $format, 'monitors_alerts_');
            }

            if ($type === 'metrics') {
                $serverId = (int) ($request->query('server_id') ?? 0);
                $history = (string) ($request->query('history') ?? '24h');

                $historySql = match ($history) {
                    '7d' => 'm.recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
                    '30d' => 'm.recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
                    default => 'm.recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
                };

                $where = [$historySql];
                $params = [];
                if ($serverId > 0) {
                    $where[] = 'm.server_id = :server_id';
                    $params[':server_id'] = $serverId;
                }
                $whereSql = 'WHERE ' . implode(' AND ', $where);

                $rows = db_all(
                    'SELECT
                        m.id, m.server_id, s.name AS server_name, m.recorded_at, m.uptime,
                        m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.cpu_load,
                        m.network_in_bps, m.network_out_bps, m.mail_mta, m.mail_queue_total, m.panel_profile
                     FROM metrics m
                     LEFT JOIN servers s ON s.id = m.server_id
                     ' . $whereSql . '
                     ORDER BY m.recorded_at DESC
                     LIMIT ' . $exportLimit,
                    $params
                );

                return $this->downloadResponse($rows, $format, 'monitors_metrics_');
            }

            if ($type === 'services') {
                $serverId = (int) ($request->query('server_id') ?? 0);
                $history = (string) ($request->query('history') ?? '24h');

                $historySql = match ($history) {
                    '7d' => 'sm.recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
                    '30d' => 'sm.recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
                    default => 'sm.recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
                };

                $where = [$historySql];
                $params = [];
                if ($serverId > 0) {
                    $where[] = 'sm.server_id = :server_id';
                    $params[':server_id'] = $serverId;
                }
                $whereSql = 'WHERE ' . implode(' AND ', $where);

                $rows = db_all(
                    'SELECT
                        sm.id, sm.metric_id, sm.server_id, s.name AS server_name, sm.recorded_at,
                        sm.service_group, sm.service_key, sm.unit_name, sm.status, sm.source
                     FROM service_metrics sm
                     LEFT JOIN servers s ON s.id = sm.server_id
                     ' . $whereSql . '
                     ORDER BY sm.recorded_at DESC
                     LIMIT ' . $exportLimit,
                    $params
                );

                return $this->downloadResponse($rows, $format, 'monitors_services_');
            }

            if ($type === 'audits') {
                $filterAction = trim((string) ($request->query('action_type') ?? ''));
                $filterUserId = (int) ($request->query('user_id') ?? 0);
                $filterDateFrom = trim((string) ($request->query('date_from') ?? ''));
                $filterDateTo = trim((string) ($request->query('date_to') ?? ''));

                $where = [];
                $params = [];
                if ($filterAction !== '') {
                    $where[] = 'a.action_type = :action_type';
                    $params[':action_type'] = $filterAction;
                }
                if ($filterUserId > 0) {
                    $where[] = 'a.user_id = :user_id';
                    $params[':user_id'] = $filterUserId;
                }
                if ($filterDateFrom !== '') {
                    $where[] = 'a.created_at >= :date_from';
                    $params[':date_from'] = $filterDateFrom . ' 00:00:00';
                }
                if ($filterDateTo !== '') {
                    $where[] = 'a.created_at <= :date_to';
                    $params[':date_to'] = $filterDateTo . ' 23:59:59';
                }
                $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

                $rows = db_all(
                    'SELECT
                        a.id, a.user_id, COALESCE(a.username, "system") AS username,
                        a.action_type, a.action_detail, a.target_type, a.target_id,
                        a.context_json, a.ip_address, a.created_at
                     FROM admin_audit_logs a
                     ' . $whereSql . '
                     ORDER BY a.id DESC
                     LIMIT ' . $exportLimit,
                    $params
                );

                return $this->downloadResponse($rows, $format, 'monitors_audits_');
            }
        }

        $servers = db_all('SELECT id, name FROM servers ORDER BY name ASC');
        $users = db_all('SELECT id, username FROM users ORDER BY username ASC');
        $currentUser = current_user();
        $exportJobs = db_all(
            'SELECT id, export_type, export_format, status, file_name, error_message, created_at, completed_at
             FROM export_jobs WHERE user_id = :user_id ORDER BY id DESC LIMIT 20',
            [':user_id' => (int) $currentUser['id']]
        );

        $data = [
            'servers' => $servers,
            'users' => $users,
            'exportJobs' => $exportJobs,
            'title' => APP_NAME . ' - Export Data',
            'activeNav' => 'export',
        ];

        return Response::html(View::render('admin/export', $data, 'admin'));
    }

    private function downloadResponse(array $rows, string $format, string $prefix): Response
    {
        $filename = $prefix . date('Ymd_His') . '.' . $format;
        if ($format === 'json') {
            $response = Response::text((string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
        } else {
            $response = Response::text($this->buildCsv($rows))
                ->withHeader('Content-Type', 'text/csv; charset=utf-8');
        }
        $response = $response->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        if (count($rows) >= self::SYNC_EXPORT_MAX_ROWS) {
            // Signal UIs/consumers that this is a capped preview; the queued
            // export on this page produces the complete dataset.
            $response = $response->withHeader('X-Monitors-Export-Truncated', '1');
        }
        return $response;
    }

    private function buildCsv(array $rows): string
    {
        $out = fopen('php://temp', 'wb');
        if ($out === false) {
            return '';
        }
        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                // Prevent formula injection when CSV is opened in spreadsheet apps.
                $safeRow = array_map(static function (mixed $value): mixed {
                    if (!is_string($value) || $value === '') {
                        return $value;
                    }
                    return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
                }, $row);
                fputcsv($out, $safeRow);
            }
        }
        rewind($out);
        $content = (string) stream_get_contents($out);
        fclose($out);
        return $content;
    }
}
