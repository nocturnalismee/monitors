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

function installer_still_present(): bool
{
    return is_file(MONITORS_BASE_DIR . '/public/install.php');
}

function installer_lock_path(): string
{
    return MONITORS_BASE_DIR . '/config/.installer-locked';
}

function installer_locked(): bool
{
    return is_file(installer_lock_path());
}

/**
 * Permission snapshot of config/local.php for the Settings → Ops posture card.
 *
 * @return array{exists: bool, octal: string, world_readable: bool}
 */
function local_config_perms(): array
{
    $path = MONITORS_BASE_DIR . '/config/local.php';
    if (!is_file($path)) {
        return ['exists' => false, 'octal' => '-', 'world_readable' => false];
    }
    $perms = fileperms($path);
    if ($perms === false) {
        return ['exists' => true, 'octal' => '?', 'world_readable' => false];
    }
    $mode = $perms & 0777;
    return ['exists' => true, 'octal' => sprintf('%04o', $mode), 'world_readable' => ($mode & 0004) !== 0];
}
