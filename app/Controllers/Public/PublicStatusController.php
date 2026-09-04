<?php
declare(strict_types=1);

namespace App\Controllers\Public;

use App\Http\Request;
use App\Http\Response;
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
        $statusOnlineMinutes = max(1, (int) setting_get('alert_down_minutes'));
        $cpuWarnThreshold = max(0.0, (float) setting_get('threshold_cpu_load'));
        $cpuCriticalThreshold = max($cpuWarnThreshold, (float) setting_get('threshold_cpu_load_critical'));
        usort(
            $rows,
            static function (array $a, array $b) use ($cpuWarnThreshold, $cpuCriticalThreshold): int {
                $ra = self::cpuSeverityRank((float) ($a['cpu_load'] ?? 0), $cpuWarnThreshold, $cpuCriticalThreshold);
                $rb = self::cpuSeverityRank((float) ($b['cpu_load'] ?? 0), $cpuWarnThreshold, $cpuCriticalThreshold);
                if ($ra !== $rb) {
                    return $rb <=> $ra;
                }
                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            }
        );

        $serviceSummaryByServer = [];
        $serverIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $id): bool => $id > 0));
        if (!empty($serverIds)) {
            $placeholders = implode(',', array_fill(0, count($serverIds), '?'));
            $stmt = db()->prepare(
                "SELECT server_id,
                        SUM(last_status = 'up') AS up_count,
                        SUM(last_status = 'down') AS down_count,
                        SUM(last_status = 'unknown') AS unknown_count
                 FROM server_service_states
                 WHERE server_id IN ({$placeholders})
                 GROUP BY server_id"
            );
            $stmt->execute($serverIds);
            foreach ($stmt->fetchAll() as $row) {
                $sid = (int) ($row['server_id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $serviceSummaryByServer[$sid] = [
                    'up' => (int) ($row['up_count'] ?? 0),
                    'down' => (int) ($row['down_count'] ?? 0),
                    'unknown' => (int) ($row['unknown_count'] ?? 0),
                ];
            }
        }

        $total = count($rows);
        $online = 0;
        $down = 0;
        $pending = 0;
        foreach ($rows as $row) {
            $status = serverStatusFromLastSeen($row['last_seen'] ?? null, (int) ($row['active'] ?? 0) === 1, $statusOnlineMinutes);
            if ($status === 'online') {
                $online++;
            } elseif ($status === 'down') {
                $down++;
            } else {
                $pending++;
            }
        }

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

    private static function cpuSeverityRank(float $cpuLoad, float $warn, float $critical): int
    {
        if ($cpuLoad > $critical) {
            return 2;
        }
        if ($cpuLoad > $warn) {
            return 1;
        }
        return 0;
    }
}
