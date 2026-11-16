<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Services\AuthService;

final class Auth
{
    public static function handle(Request $request): ?Response
    {
        if (!is_logged_in()) {
            return self::unauthenticated('Please log in to continue.', $request);
        }

        // Session expiry (idle, absolute, user-agent binding) is enforced in a
        // single place so no controller can silently skip it. AuthService
        // returns a Response instead of exiting, which keeps the kernel's
        // error handling and tests in control of the exit path.
        return AuthService::sessionExpiryResponse($request);
    }

    private static function unauthenticated(string $message, Request $request): Response
    {
        if (str_starts_with('/' . trim($request->path, '/'), '/api/')) {
            return Response::json(['error' => 'Unauthorized', 'message' => $message], 401);
        }

        flash_set('warning', $message);
        return Response::redirect(app_url('login'), 303);
    }
}
