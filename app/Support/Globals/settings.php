<?php
declare(strict_types=1);

use App\Services\SettingsService;

function settings_defaults(): array
{
    return SettingsService::defaults();
}

function settings_is_sensitive(string $key): bool
{
    return SettingsService::isSensitive($key);
}

function settings_encrypt(string $value): string
{
    return SettingsService::encrypt($value);
}

function settings_decrypt(string $value): string
{
    return SettingsService::decrypt($value);
}

function settings_get_all(): array
{
    return SettingsService::all();
}

function setting_get(string $key): string
{
    return SettingsService::get($key);
}

function settings_set(string $key, string $value): void
{
    SettingsService::set($key, $value);
}

function settings_save_many(array $values): void
{
    SettingsService::saveMany($values);
}
