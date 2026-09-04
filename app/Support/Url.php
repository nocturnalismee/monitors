<?php
declare(strict_types=1);

namespace App\Support;

final class Url
{
    /** @var array<string, string> */
    private static array $named = [];

    public static function setRoute(string $name, string $path): void
    {
        self::$named[$name] = $path;
    }

    public static function route(string $name, array $params = []): string
    {
        $path = self::$named[$name] ?? '';
        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', (string) $value, $path);
        }
        return app_url($path);
    }
}
