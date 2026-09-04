<?php
declare(strict_types=1);

namespace App\Services;

final class IpReputationService
{
    public static function ip_rep_dnsbl_list(): array
    {
        return [
            'zen.spamhaus.org',
            'bl.spamcop.net',
            'b.barracudacentral.org',
            'dnsbl.sorbs.net',
            'cbl.abuseat.org',
            'psbl.surriel.com',
            'dnsbl-1.uceprotect.net',
            'all.s5h.net',
        ];
    }

    public static function ip_rep_ip_type(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'ipv4';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'ipv6';
        }
        return 'invalid';
    }

    public static function ip_rep_reverse_ipv4(string $ip): string
    {
        return implode('.', array_reverse(explode('.', $ip)));
    }

    public static function ip_rep_reverse_ipv6(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return '';
        }
        $hex = unpack('H*', $packed);
        $nibbles = str_split($hex[1] ?? '', 1);
        return implode('.', array_reverse($nibbles));
    }

    public static function ip_rep_http_status(?array $headers): int
    {
        if (empty($headers)) {
            return 0;
        }
        $line = (string) ($headers[0] ?? '');
        if (preg_match('/\s(\d{3})\s/', $line, $m) === 1) {
            return (int) $m[1];
        }
        return 0;
    }

    public static function ip_rep_ipinfo_due(?array $existing, int $nowTs, int $ttlSeconds = 86400): bool
    {
        if ($existing === null) {
            return true;
        }
        $fetchedAt = (string) ($existing['geo_fetched_at'] ?? '');
        if ($fetchedAt === '') {
            return true;
        }
        $ts = strtotime($fetchedAt);
        if ($ts === false) {
            return true;
        }
        return ($nowTs - $ts) >= $ttlSeconds;
    }

    public static function ip_rep_check_dnsbl(string $ip): array
    {
        $type = self::ip_rep_ip_type($ip);
        $reversed = '';
        if ($type === 'ipv4') {
            $reversed = self::ip_rep_reverse_ipv4($ip);
        } elseif ($type === 'ipv6') {
            $reversed = self::ip_rep_reverse_ipv6($ip);
        }

        $dnsbls = self::ip_rep_dnsbl_list();
        $listed = [];
        $clean = [];
        $failed = [];

        if ($reversed === '') {
            return [
                'listed' => [],
                'clean' => [],
                'failed' => $dnsbls,
                'total' => count($dnsbls),
                'success_count' => 0,
            ];
        }

        foreach ($dnsbls as $dnsbl) {
            $query = $reversed . '.' . $dnsbl;
            $records = @dns_get_record($query, DNS_A);
            if ($records === false) {
                if ($type === 'ipv6') {
                    $clean[] = $dnsbl;
                } else {
                    $failed[] = $dnsbl;
                }
                continue;
            }
            $isListed = false;
            $isRefused = false;
            foreach ($records as $rec) {
                $ipAddr = (string) ($rec['ip'] ?? '');
                if ($ipAddr !== '' && str_starts_with($ipAddr, '127.')) {
                    if (str_starts_with($ipAddr, '127.255.255.')) {
                        $isRefused = true;
                        break;
                    }
                    $isListed = true;
                    break;
                }
            }
            if ($isRefused) {
                $failed[] = $dnsbl;
            } elseif ($isListed) {
                $listed[] = $dnsbl;
            } else {
                $clean[] = $dnsbl;
            }
        }

        return [
            'listed' => $listed,
            'clean'  => $clean,
            'failed' => $failed,
            'total' => count($dnsbls),
            'success_count' => count($listed) + count($clean),
        ];
    }

    public static function ip_rep_check_abuseipdb(string $ip, string $apiKey): ?array
    {
        if ($apiKey === '') {
            return null;
        }

        $url = 'https://api.abuseipdb.com/api/v2/check?' . http_build_query([
            'ipAddress'    => $ip,
            'maxAgeInDays' => 90,
            'verbose'      => '',
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Key: {$apiKey}\r\nAccept: application/json\r\n",
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
        $status = self::ip_rep_http_status($http_response_header ?? null);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['data'])) {
            return null;
        }

        $data = $json['data'];
        return [
            'score'         => (int) ($data['abuseConfidenceScore'] ?? 0),
            'total_reports'  => (int) ($data['totalReports'] ?? 0),
            'country'       => (string) ($data['countryCode'] ?? ''),
            'isp'           => (string) ($data['isp'] ?? ''),
            'domain'        => (string) ($data['domain'] ?? ''),
            'usage_type'    => (string) ($data['usageType'] ?? ''),
            'is_whitelisted' => (bool) ($data['isWhitelisted'] ?? false),
        ];
    }

    public static function ip_rep_check_virustotal(string $ip, string $apiKey): ?array
    {
        if ($apiKey === '') {
            return null;
        }

        $url = 'https://www.virustotal.com/api/v3/ip_addresses/' . urlencode($ip);
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "x-apikey: {$apiKey}\r\nAccept: application/json\r\n",
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
        $status = self::ip_rep_http_status($http_response_header ?? null);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['data']['attributes'])) {
            return null;
        }

        $attrs = $json['data']['attributes'];
        $stats = $attrs['last_analysis_stats'] ?? [];

        return [
            'malicious'  => (int) ($stats['malicious'] ?? 0),
            'suspicious' => (int) ($stats['suspicious'] ?? 0),
            'harmless'   => (int) ($stats['harmless'] ?? 0),
            'undetected' => (int) ($stats['undetected'] ?? 0),
            'as_owner'   => (string) ($attrs['as_owner'] ?? ''),
            'country'    => (string) ($attrs['country'] ?? ''),
            'network'    => (string) ($attrs['network'] ?? ''),
        ];
    }

    public static function ip_rep_check_ipinfo(string $ip, string $apiKey): ?array
    {
        if ($apiKey === '') {
            return null;
        }

        $url = 'https://ipinfo.io/' . urlencode($ip) . '/json?token=' . urlencode($apiKey);
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Accept: application/json\r\n",
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
        $status = self::ip_rep_http_status($http_response_header ?? null);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }

        $privacy = $json['privacy'] ?? [];
        $org = (string) ($json['org'] ?? '');
        $asn = '';
        if ($org !== '' && preg_match('/\\bAS\\d+\\b/', $org, $m) === 1) {
            $asn = $m[0];
        }
        return [
            'bogon'    => (bool) ($json['bogon'] ?? false),
            'hostname' => (string) ($json['hostname'] ?? ''),
            'org'      => $org,
            'city'     => (string) ($json['city'] ?? ''),
            'region'   => (string) ($json['region'] ?? ''),
            'country'  => (string) ($json['country'] ?? ''),
            'asn'      => $asn,
            'privacy'  => [
                'vpn'     => (bool) ($privacy['vpn'] ?? false),
                'proxy'   => (bool) ($privacy['proxy'] ?? false),
                'tor'     => (bool) ($privacy['tor'] ?? false),
                'relay'   => (bool) ($privacy['relay'] ?? false),
                'hosting' => (bool) ($privacy['hosting'] ?? false),
            ],
        ];
    }

    public static function ip_rep_check_single(string $ip, array $existingProviderResults = []): array
    {
        $startMs = hrtime(true);
        $nowIso = date('c');
        $nowTs = time();

        $dnsbl = self::ip_rep_check_dnsbl($ip);
        $listedOn = $dnsbl['listed'];
        $listedCount = count($listedOn);
        $totalChecked = (int) ($dnsbl['total'] ?? (count($dnsbl['listed']) + count($dnsbl['clean'])));

        $providerResults = ['dnsbl' => $dnsbl];
        $providerSuccess = 0;
        if (($dnsbl['success_count'] ?? 0) > 0) {
            $providerSuccess++;
        }

        $abuseKey = setting_get('ip_rep_abuseipdb_key');
        $abuseResult = self::ip_rep_check_abuseipdb($ip, $abuseKey);
        if ($abuseResult !== null) {
            $providerResults['abuseipdb'] = $abuseResult;
            $providerSuccess++;
        }

        $vtKey = setting_get('ip_rep_virustotal_key');
        $vtResult = self::ip_rep_check_virustotal($ip, $vtKey);
        if ($vtResult !== null) {
            $providerResults['virustotal'] = $vtResult;
            $providerSuccess++;
        }

        $ipinfoKey = setting_get('ip_rep_ipinfo_key');
        $existingIpinfo = null;
        if (isset($existingProviderResults['ipinfo']) && is_array($existingProviderResults['ipinfo'])) {
            $existingIpinfo = $existingProviderResults['ipinfo'];
        }
        if (!self::ip_rep_ipinfo_due($existingIpinfo, $nowTs)) {
            $providerResults['ipinfo'] = $existingIpinfo;
        } else {
            $ipinfoResult = null;
            if ($ipinfoKey !== '') {
                $ipinfoResult = self::ip_rep_check_ipinfo($ip, $ipinfoKey);
            }
            if ($ipinfoResult !== null) {
                $ipinfoResult['geo_fetched_at'] = $nowIso;
                $providerResults['ipinfo'] = $ipinfoResult;
            } elseif ($existingIpinfo !== null) {
                $providerResults['ipinfo'] = $existingIpinfo;
            }
        }

        $overallStatus = 'clean';
        if ($providerSuccess === 0) {
            $overallStatus = 'unknown';
        }

        if ($overallStatus !== 'unknown' && $listedCount > 0) {
            $overallStatus = 'listed';
        }

        if ($overallStatus !== 'unknown' && $abuseResult !== null && ($abuseResult['score'] ?? 0) > 25) {
            $overallStatus = 'listed';
        }

        if ($overallStatus !== 'unknown' && $vtResult !== null && ($vtResult['malicious'] ?? 0) > 0) {
            $overallStatus = 'listed';
        }

        $durationMs = (int) ((hrtime(true) - $startMs) / 1_000_000);

        return [
            'overall_status'   => $overallStatus,
            'listed_count'     => $listedCount,
            'total_checked'    => $totalChecked,
            'listed_on'        => $listedOn,
            'provider_results' => $providerResults,
            'duration_ms'      => $durationMs,
        ];
    }

    public static function ip_rep_due_targets(int $limit = 100): array
    {
        $defaultInterval = max(1, min(168, (int) setting_get('ip_rep_check_interval_hours')));
        $limit = max(1, min(500, $limit));
        return db_all(
            "SELECT t.id, t.ip_address, t.label, t.server_id, t.check_interval_hours
             FROM ip_reputation_targets t
             LEFT JOIN ip_reputation_states s ON s.target_id = t.id
             WHERE t.active = 1
               AND (s.last_checked_at IS NULL
                    OR s.last_checked_at <= DATE_SUB(NOW(), INTERVAL COALESCE(t.check_interval_hours, {$defaultInterval}) HOUR))
             ORDER BY s.last_checked_at ASC, t.id ASC
             LIMIT {$limit}"
        );
    }

    public static function ip_rep_record_check(int $targetId, array $result): array
    {
        $overallStatus   = (string) ($result['overall_status'] ?? 'unknown');
        $listedCount     = (int) ($result['listed_count'] ?? 0);
        $totalChecked    = (int) ($result['total_checked'] ?? 0);
        $listedOn        = $result['listed_on'] ?? [];
        $providerResults = $result['provider_results'] ?? [];
        $durationMs      = (int) ($result['duration_ms'] ?? 0);

        $listedOnJson        = json_encode($listedOn, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $providerResultsJson = json_encode($providerResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');

        db_exec(
            'INSERT INTO ip_reputation_checks
                (target_id, overall_status, listed_count, total_checked, listed_on, provider_results, check_duration_ms, checked_at)
             VALUES (:tid, :status, :listed_count, :total_checked, :listed_on, :provider_results, :duration_ms, :checked_at)',
            [
                ':tid'              => $targetId,
                ':status'           => $overallStatus,
                ':listed_count'     => $listedCount,
                ':total_checked'    => $totalChecked,
                ':listed_on'        => $listedOnJson,
                ':provider_results' => $providerResultsJson,
                ':duration_ms'      => $durationMs,
                ':checked_at'       => $now,
            ]
        );

        $prevState = db_one('SELECT overall_status FROM ip_reputation_states WHERE target_id = :tid', [':tid' => $targetId]);
        $prevStatus = $prevState !== null ? ($prevState['overall_status'] ?? null) : null;
        $changed = ($prevStatus !== null && $prevStatus !== $overallStatus);

        $changeClause = $changed ? ', last_change_at = VALUES(last_change_at)' : '';
        db_exec(
            "INSERT INTO ip_reputation_states
                (target_id, overall_status, listed_count, total_checked, listed_on, provider_results, last_checked_at, last_change_at)
             VALUES (:tid, :status, :listed_count, :total_checked, :listed_on, :provider_results, :now, :now2)
             ON DUPLICATE KEY UPDATE
                overall_status  = VALUES(overall_status),
                listed_count    = VALUES(listed_count),
                total_checked   = VALUES(total_checked),
                listed_on       = VALUES(listed_on),
                provider_results = VALUES(provider_results),
                last_checked_at = VALUES(last_checked_at){$changeClause}",
            [
                ':tid'              => $targetId,
                ':status'           => $overallStatus,
                ':listed_count'     => $listedCount,
                ':total_checked'    => $totalChecked,
                ':listed_on'        => $listedOnJson,
                ':provider_results' => $providerResultsJson,
                ':now'              => $now,
                ':now2'             => $now,
            ]
        );

        return [
            'changed'       => $changed,
            'prev_status'   => $prevStatus,
            'new_status'    => $overallStatus,
            'listed_count'  => $listedCount,
            'listed_on'     => $listedOn,
        ];
    }

    public static function ip_rep_display_status(string $status, int $active): string
    {
        if ($active !== 1) {
            return 'paused';
        }
        return match ($status) {
            'clean'  => 'clean',
            'listed' => 'listed',
            default  => 'unknown',
        };
    }

    public static function ip_rep_status_badge_class(string $status): string
    {
        return match ($status) {
            'clean'  => 'badge-clean',
            'listed' => 'badge-listed',
            'paused' => 'text-bg-secondary',
            default  => 'badge-pending',
        };
    }

    public static function invalidate_ip_rep_cache(?int $targetId = null): void
    {
        cache_delete('ip_rep:list');
        if ($targetId !== null) {
            cache_delete('ip_rep:detail:' . $targetId);
        }
    }
}
