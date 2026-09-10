<?php
declare(strict_types=1);

namespace App\Controllers\Public;

use App\Http\Request;
use App\Http\Response;
use App\Services\ServerListService;
use App\Support\View;

final class PublicStatusController
{
    public function index(Request $request): Response
    {
        $latestMetricJoin = latest_metric_join_sql('s', 'm');
        $rows = db_all(
            'SELECT s.id, s.name, s.location, s.type, s.active,
                    COALESCE(s.last_seen_at, m.recorded_at) AS last_seen, m.uptime, m.ram_total, m.ram_used, m.hdd_total, m.hdd_used, m.cpu_load, m.network_in_bps, m.network_out_bps, m.mail_mta, m.mail_queue_total, m.panel_profile
             FROM servers s' . $latestMetricJoin . '
             WHERE s.active = 1
             ORDER BY s.name ASC'
        );
        $thresholds = ServerListService::thresholds();
        $statusOnlineMinutes = $thresholds['onlineMinutes'];
        $cpuWarnThreshold = $thresholds['cpuWarn'];
        $cpuCriticalThreshold = $thresholds['cpuCritical'];
        $rows = ServerListService::sortBySeverity($rows, $cpuWarnThreshold, $cpuCriticalThreshold);

        $serverIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0));
        $serviceSummaryByServer = ServerListService::serviceSummaryMap($serverIds);

        $total = count($rows);
        $counts = ServerListService::countByStatus($rows, $statusOnlineMinutes);
        $online = $counts['online'];
        $down = $counts['down'];
        $pending = $counts['pending'];

        $uiSettings = settings_get_all();
        $brandingLogoRaw = trim((string) ($uiSettings['branding_logo_url'] ?? ''));
        $brandingLogoUrl = '';
        if ($brandingLogoRaw !== '') {
            if (preg_match('/^(https?:)?\/\//i', $brandingLogoRaw) === 1 || str_starts_with($brandingLogoRaw, 'data:')) {
                $brandingLogoUrl = $brandingLogoRaw;
            } else {
                $brandingLogoUrl = app_url(ltrim($brandingLogoRaw, '/'));
            }
        }

        $data = [
            'title' => APP_NAME . ' - Public Status',
            'rows' => $rows,
            'statusOnlineMinutes' => $statusOnlineMinutes,
            'cpuWarnThreshold' => $cpuWarnThreshold,
            'cpuCriticalThreshold' => $cpuCriticalThreshold,
            'serviceSummaryByServer' => $serviceSummaryByServer,
            'total' => $total,
            'online' => $online,
            'down' => $down,
            'pending' => $pending,
            'brandingLogoUrl' => $brandingLogoUrl,
        ];
        return Response::html(View::render('public/status', $data, 'public'));
    }
}
