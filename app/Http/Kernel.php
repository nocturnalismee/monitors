<?php
declare(strict_types=1);

namespace App\Http;

final class Kernel
{
    public function handle(Request $request): Response
    {
        try {
            $router = new Router();
            return $router->dispatch($request);
        } catch (\Throwable $e) {
            error_log((string) $e);
            if (PHP_SAPI === 'cli') {
                throw $e;
            }
            if (APP_ENV !== 'production') {
                return Response::text('Internal Server Error' . PHP_EOL . $e->getMessage(), 500);
            }
            return Response::text('Internal Server Error', 500);
        }
    }
}
