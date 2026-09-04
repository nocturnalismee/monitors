<?php
declare(strict_types=1);

use App\Services\TurnstileService;

function turnstile_is_configured(): bool
{
    return TurnstileService::turnstile_is_configured();
}

function turnstile_site_key(): string
{
    return TurnstileService::turnstile_site_key();
}

function turnstile_validate(string $token, string $remoteIp = ''): bool
{
    return TurnstileService::turnstile_validate($token, $remoteIp);
}
