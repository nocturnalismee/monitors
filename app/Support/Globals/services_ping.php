<?php
declare(strict_types=1);

use App\Services\PingService;

function ping_normalize_target(string $target): string
{
    return PingService::ping_normalize_target($target);
}

function ping_normalize_target_type(?string $targetType): string
{
    return PingService::ping_normalize_target_type($targetType);
}

function ping_normalize_check_method(?string $checkMethod): string
{
    return PingService::ping_normalize_check_method($checkMethod);
}

function ping_validate_target(string $target, string $targetType, string $checkMethod = 'icmp'): bool
{
    return PingService::ping_validate_target($target, $targetType, $checkMethod);
}

function ping_display_status(?string $lastStatus, int $active): string
{
    return PingService::ping_display_status($lastStatus, $active);
}

function ping_probe_target(string $target, int $timeoutSeconds, string $checkMethod = 'icmp'): array
{
    return PingService::ping_probe_target($target, $timeoutSeconds, $checkMethod);
}

function ping_due_monitors(int $limit = 500): array
{
    return PingService::ping_due_monitors($limit);
}

function ping_record_check(int $monitorId, array $probe, int $failureThreshold): array
{
    return PingService::ping_record_check($monitorId, $probe, $failureThreshold);
}
