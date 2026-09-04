<?php
declare(strict_types=1);

use App\Support\Csrf;

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_input(): string
{
    return Csrf::input();
}

function csrf_validate(?string $token): bool
{
    return Csrf::validate($token);
}
