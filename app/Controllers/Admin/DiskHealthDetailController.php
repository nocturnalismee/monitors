<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;
use Throwable;

final class DiskHealthDetailController
{
    public function index(Request $request): Response
    {
        require_login();

        $id = (int) ($request->params['id'] ?? 0);
        if ($id <= 0) {
            flash_set('danger', 'Invalid server.');
            redirect('disk-health');
        }

        $server = db_one(
            'SELECT id, name, host, location, active
             FROM servers
             WHERE id = :id
             LIMIT 1',
            [':id' => $id]
        );
        if ($server === null) {
            flash_set('danger', 'Server not found.');
            redirect('disk-health');
        }

        $diskHealthRows = [];
        $diskHealthLoadError = '';
        if ($this->diskHealthTableExists()) {
            try {
                $diskHealthRows = $this->fetchDiskHealthDetailRows($id);
            } catch (Throwable) {
                $diskHealthLoadError = 'Disk health data unavailable.';
            }
        } else {
            $diskHealthLoadError = 'Disk health table not found.';
        }

        foreach ($diskHealthRows as &$disk) {
            $healthStatus = strtolower(trim((string) ($disk['health_status'] ?? 'unknown')));
            $disk['health_status'] = $healthStatus;
            $disk['health_badge_class'] = $this->diskHealthBadgeClass($healthStatus);
            $healthScore = isset($disk['health_score']) ? (float) $disk['health_score'] : null;
            $temperature = isset($disk['temperature_c']) ? (float) $disk['temperature_c'] : null;
            $powerOnHours = isset($disk['power_on_value']) ? (float) $disk['power_on_value'] : null;
            $tbwBytes = isset($disk['total_written_bytes']) ? (float) $disk['total_written_bytes'] : null;
            [$devicePrimary, $deviceMeta] = $this->formatDeviceParts(
                isset($disk['device_name']) ? (string) $disk['device_name'] : null,
                isset($disk['disk_key']) ? (string) $disk['disk_key'] : null
            );
            $disk['device_primary'] = $devicePrimary;
            $disk['device_meta'] = $deviceMeta;
            $disk['health_pct'] = $this->formatHealthPct($healthScore);
            $disk['temperature_text'] = $temperature === null ? '-' : number_format($temperature, 1) . ' C';
            $disk['power_on_time'] = $this->formatPowerOnTime($powerOnHours);
            $disk['tbw'] = $this->formatTbwFromBytes($tbwBytes);
        }
        unset($disk);

        $data = [
            'id' => $id,
            'server' => $server,
            'diskHealthRows' => $diskHealthRows,
            'diskHealthLoadError' => $diskHealthLoadError,
            'title' => APP_NAME . ' - Disk Health Detail',
            'activeNav' => 'disk_health',
        ];

        return Response::html(View::render('admin/disk_health_detail', $data, 'admin'));
    }

    private function fetchDiskHealthDetailRows(int $serverId): array
    {
        $powerOnColumn = $this->diskHealthPowerOnColumn();
        return db_all(
            'SELECT
                disk_key, device_name, model, serial,
                health_status, health_score, temperature_c, ' . $powerOnColumn . ' AS power_on_value,
                total_written_bytes, updated_at
             FROM disk_health_states
             WHERE server_id = :id
             ORDER BY
                CASE health_status
                    WHEN "critical" THEN 4
                    WHEN "warning" THEN 3
                    WHEN "ok" THEN 2
                    ELSE 1
                END DESC,
                IFNULL(CAST(SUBSTRING_INDEX(REGEXP_SUBSTR(device_name, "#0/[0-9]+"), "/", -1) AS UNSIGNED), 999999) ASC,
                updated_at DESC,
                disk_key ASC',
            [':id' => $serverId]
        );
    }

    private function diskHealthPowerOnColumn(): string
    {
        static $column = null;
        if (is_string($column) && $column !== '') {
            return $column;
        }

        $column = db_column_exists('disk_health_states', 'power_on_time') ? 'power_on_time' : 'power_on_hours';

        return $column;
    }

    private function formatTbwFromBytes(?float $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '-';
        }
        $tb = $bytes / 1000 / 1000 / 1000 / 1000;
        return number_format($tb, 2) . ' TB';
    }

    private function formatPowerOnTime(?float $hours): string
    {
        if ($hours === null || $hours <= 0) {
            return '-';
        }
        $totalHours = (int) round($hours);
        $days = intdiv($totalHours, 24);
        $remainHours = $totalHours % 24;
        if ($days > 0) {
            return $days . ' days, ' . $remainHours . ' hours';
        }
        return $totalHours . ' hours';
    }

    private function formatDeviceParts(?string $deviceName, ?string $diskKey): array
    {
        $raw = trim((string) ($deviceName ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($diskKey ?? '-'));
        }
        if ($raw === '') {
            $raw = '-';
        }
        if (preg_match('/^(.+?)\s*\((.+)\)$/', $raw, $m) === 1) {
            return [trim($m[1]), trim($m[2])];
        }
        return [$raw, ''];
    }

    private function formatHealthPct(?float $value): string
    {
        if ($value === null) {
            return '-';
        }
        return number_format($value, 0) . ' %';
    }

    private function diskHealthBadgeClass(string $healthStatus): string
    {
        return match (strtolower(trim($healthStatus))) {
            'critical' => 'badge-severity badge-severity-danger',
            'warning' => 'badge-severity badge-severity-warning',
            'ok' => 'badge-severity badge-severity-success',
            default => 'badge-severity badge-severity-info',
        };
    }

    private function diskHealthTableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $row = db_one(
                "SELECT 1
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'disk_health_states'
                 LIMIT 1"
            );
            $exists = ($row !== null);
        } catch (Throwable) {
            $exists = false;
        }
        return $exists;
    }
}
