<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class IpReputationDetailController
{
    public function index(Request $request): Response
    {
        require_login();

        $targetId = (int) ($request->params['id'] ?? 0);
        if ($targetId <= 0) {
            flash_set('danger', 'Invalid target ID.');
            redirect('ip-reputation');
        }

        $target = db_one(
            'SELECT t.id, t.ip_address, t.label, t.server_id, t.active, t.check_interval_hours,
                    s.overall_status, s.listed_count, s.total_checked, s.listed_on, s.provider_results,
                    s.last_checked_at, s.last_change_at,
                    sv.name AS server_name
             FROM ip_reputation_targets t
             LEFT JOIN ip_reputation_states s ON s.target_id = t.id
             LEFT JOIN servers sv ON sv.id = t.server_id
             WHERE t.id = :id',
            [':id' => $targetId]
        );

        if ($target === null) {
            flash_set('danger', 'Target not found.');
            redirect('ip-reputation');
        }

        $listedOnArr      = json_decode((string) ($target['listed_on'] ?? '[]'), true) ?: [];
        $providerResults  = json_decode((string) ($target['provider_results'] ?? '{}'), true) ?: [];
        $displayStatus    = ip_rep_display_status((string) ($target['overall_status'] ?? 'unknown'), (int) ($target['active'] ?? 0));

        $checks = db_all(
            'SELECT id, overall_status, listed_count, total_checked, listed_on, provider_results, check_duration_ms, checked_at
             FROM ip_reputation_checks
             WHERE target_id = :tid
             ORDER BY checked_at DESC
             LIMIT 50',
            [':tid' => $targetId]
        );
        foreach ($checks as &$chk) {
            $chk['listed_on_arr'] = json_decode((string) ($chk['listed_on'] ?? '[]'), true) ?: [];
            $chk['provider_results_arr'] = json_decode((string) ($chk['provider_results'] ?? '{}'), true) ?: [];
        }
        unset($chk);

        $dnsbls         = ip_rep_dnsbl_list();
        $dnsblResults   = $providerResults['dnsbl'] ?? [];
        $abuseipdb      = $providerResults['abuseipdb'] ?? null;
        $virustotal     = $providerResults['virustotal'] ?? null;
        $ipinfo         = $providerResults['ipinfo'] ?? null;
        $geo            = is_array($ipinfo) ? $ipinfo : null;
        $geoLocationParts = [];
        if ($geo !== null) {
            $geoLocationParts = array_values(array_filter([
                (string) ($geo['city'] ?? ''),
                (string) ($geo['region'] ?? ''),
                (string) ($geo['country'] ?? ''),
            ], static fn(string $v): bool => $v !== ''));
        }
        $geoFlags = [];
        if ($geo !== null) {
            $privacy = $geo['privacy'] ?? [];
            if ($privacy['vpn'] ?? false) $geoFlags[] = 'VPN';
            if ($privacy['proxy'] ?? false) $geoFlags[] = 'Proxy';
            if ($privacy['tor'] ?? false) $geoFlags[] = 'Tor';
            if ($privacy['hosting'] ?? false) $geoFlags[] = 'Hosting';
            if ($privacy['relay'] ?? false) $geoFlags[] = 'Relay';
        }

        $data = [
            'target'            => $target,
            'checks'            => $checks,
            'displayStatus'     => $displayStatus,
            'dnsbls'            => $dnsbls,
            'dnsblResults'      => $dnsblResults,
            'abuseipdb'         => $abuseipdb,
            'virustotal'        => $virustotal,
            'ipinfo'            => $ipinfo,
            'geo'               => $geo,
            'geoLocationParts'  => $geoLocationParts,
            'geoFlags'          => $geoFlags,
            'title'             => APP_NAME . ' - IP Reputation: ' . ($target['label'] ?: $target['ip_address']),
            'activeNav'         => 'ip_reputation',
        ];

        return Response::html(View::render('admin/ip_reputation_detail', $data, 'admin'));
    }
}
