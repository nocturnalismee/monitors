<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;
use Throwable;

final class DiskHealthController
{
    public function index(Request $request): Response
    {
        require_login();

        $includeInactive = $request->query('include_inactive') !== null && (string) $request->query('include_inactive') === '1';
        $summaryRows = [];
        $loadError = '';

        try {
            $summaryRows = $this->fetchDiskHealthSummaryRows($includeInactive);
        } catch (Throwable $e) {
            $loadError = 'Disk health table is not ready or has no data yet.';
        }

        foreach ($summaryRows as &$row) {
            $serverId = (int) ($row['server_id'] ?? 0);
            $row['disk_count_int'] = max(0, (int) ($row['disk_count'] ?? 0));
            $row['disk_label'] = $this->buildPrimaryDiskLabel(
                isset($row['primary_disk_model']) ? (string) $row['primary_disk_model'] : null,
                isset($row['primary_disk_device']) ? (string) $row['primary_disk_device'] : null
            );
            $avgHealth = isset($row['avg_health_score']) ? (float) $row['avg_health_score'] : null;
            $avgPoh = isset($row['avg_power_on_time']) ? (float) $row['avg_power_on_time'] : null;
            $avgTbw = isset($row['avg_tbw_bytes']) ? (float) $row['avg_tbw_bytes'] : null;
            $row['health_pct'] = $this->formatHealthPct($avgHealth);
            $row['power_on_time'] = $this->formatPowerOnTime($avgPoh);
            $row['tbw'] = $this->formatTbwFromBytes($avgTbw);
            $row['detail_url'] = $serverId > 0 ? app_url('disk-health/' . $serverId) : '';
        }
        unset($row);

        $data = [
            'summaryRows' => $summaryRows,
            'includeInactive' => $includeInactive,
            'loadError' => $loadError,
            'title' => APP_NAME . ' - Disk Health',
            'activeNav' => 'disk_health',
        ];

        return Response::html(View::render('admin/disk_health', $data, 'admin'));
    }

    private function fetchDiskHealthSummaryRows(bool $includeInactive): array
    {
        return (new \App\Services\DiskHealthSummaryService())->summaryRows($includeInactive);
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

    private function formatHealthPct(?float $value): string
    {
        if ($value === null) {
            return '-';
        }
        return number_format($value, 0) . ' %';
    }

    private function buildPrimaryDiskLabel(?string $model, ?string $device): string
    {
        $modelText = trim((string) $model);
        if ($modelText !== '') {
            return $modelText;
        }

        $deviceText = trim((string) $device);
        if ($deviceText === '') {
            return '-';
        }

        $shortDevice = preg_replace('/\s*\(.*/', '', $deviceText);
        if (is_string($shortDevice) && trim($shortDevice) !== '') {
            return trim($shortDevice);
        }
        return $deviceText;
    }
}
