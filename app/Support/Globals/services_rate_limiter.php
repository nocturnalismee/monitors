<?php
declare(strict_types=1);

use App\Support\RateLimiter;

function api_rate_limit_dir(): string
{
    return RateLimiter::api_rate_limit_dir();
}

function api_rate_check(string $endpoint, string $ip, int $maxPerMinute = 60): bool
{
    return RateLimiter::api_rate_check($endpoint, $ip, $maxPerMinute);
}

function api_rate_limit_exceeded(): void
{
    RateLimiter::api_rate_limit_exceeded();
}

function api_rate_limit_cleanup(): void
{
    RateLimiter::api_rate_limit_cleanup();
}
