<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

final class Registry
{
    /** @var array<string, class-string> */
    private const MAP = [
        'auth' => Auth::class,
        'admin' => Admin::class,
        'csrf' => Csrf::class,
        'guest' => Guest::class,
    ];

    public static function run(string $name, Request $request): ?Response
    {
        $class = self::MAP[$name] ?? null;
        if ($class === null) {
            throw new \RuntimeException("Unknown middleware: {$name}");
        }
        return $class::handle($request);
    }
}
