<?php
declare(strict_types=1);

use App\Services\SettingsService;

function settings_get_all(): array
{
    return SettingsService::all();
}

function setting_get(string $key): string
{
    return SettingsService::get($key);
}

function settings_save_many(array $values): void
{
    SettingsService::saveMany($values);
}
