<?php
declare(strict_types=1);

use App\Support\Cache;

function redis_client(): ?Redis
{
    return Cache::client();
}

function cache_key(string $suffix): string
{
    return Cache::key($suffix);
}

function cache_get(string $key): mixed
{
    return Cache::get($key);
}

function cache_set(string $key, mixed $value, int $ttlSeconds): void
{
    Cache::set($key, $value, $ttlSeconds);
}

function cache_delete(string $key): void
{
    Cache::delete($key);
}

function cache_delete_pattern(string $pattern): void
{
    Cache::deletePattern($pattern);
}

function cache_ttl(string $settingKey, int $fallback): int
{
    return Cache::ttl($settingKey, $fallback);
}

function invalidate_status_cache(?int $serverId = null): void
{
    Cache::invalidateStatus($serverId);
}

/**
 * Version suffix that must be appended to every status:single / status:history
 * cache key so invalidate_status_cache() can orphan them without SCAN.
 */
function status_cache_version(?int $serverId = null): string
{
    return Cache::statusVersionSuffix($serverId);
}

function invalidate_alert_cache(): void
{
    Cache::invalidateAlerts();
}

function invalidate_ping_cache(?int $monitorId = null): void
{
    Cache::invalidatePing($monitorId);
}
