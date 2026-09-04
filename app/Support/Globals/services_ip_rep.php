<?php
declare(strict_types=1);

use App\Services\IpReputationService;

function ip_rep_dnsbl_list(): array
{
    return IpReputationService::ip_rep_dnsbl_list();
}

function ip_rep_ip_type(string $ip): string
{
    return IpReputationService::ip_rep_ip_type($ip);
}

function ip_rep_reverse_ipv4(string $ip): string
{
    return IpReputationService::ip_rep_reverse_ipv4($ip);
}

function ip_rep_reverse_ipv6(string $ip): string
{
    return IpReputationService::ip_rep_reverse_ipv6($ip);
}

function ip_rep_http_status(?array $headers): int
{
    return IpReputationService::ip_rep_http_status($headers);
}

function ip_rep_ipinfo_due(?array $existing, int $nowTs, int $ttlSeconds = 86400): bool
{
    return IpReputationService::ip_rep_ipinfo_due($existing, $nowTs, $ttlSeconds);
}

function ip_rep_check_dnsbl(string $ip): array
{
    return IpReputationService::ip_rep_check_dnsbl($ip);
}

function ip_rep_check_abuseipdb(string $ip, string $apiKey): ?array
{
    return IpReputationService::ip_rep_check_abuseipdb($ip, $apiKey);
}

function ip_rep_check_virustotal(string $ip, string $apiKey): ?array
{
    return IpReputationService::ip_rep_check_virustotal($ip, $apiKey);
}

function ip_rep_check_ipinfo(string $ip, string $apiKey): ?array
{
    return IpReputationService::ip_rep_check_ipinfo($ip, $apiKey);
}

function ip_rep_check_single(string $ip, array $existingProviderResults = []): array
{
    return IpReputationService::ip_rep_check_single($ip, $existingProviderResults);
}

function ip_rep_due_targets(int $limit = 100): array
{
    return IpReputationService::ip_rep_due_targets($limit);
}

function ip_rep_record_check(int $targetId, array $result): array
{
    return IpReputationService::ip_rep_record_check($targetId, $result);
}

function ip_rep_display_status(string $status, int $active): string
{
    return IpReputationService::ip_rep_display_status($status, $active);
}

function ip_rep_status_badge_class(string $status): string
{
    return IpReputationService::ip_rep_status_badge_class($status);
}

function invalidate_ip_rep_cache(?int $targetId = null): void
{
    IpReputationService::invalidate_ip_rep_cache($targetId);
}
