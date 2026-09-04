<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

final class Guest
{
    public static function handle(Request $request): ?Response
    {
        if (is_logged_in()) {
            return Response::redirect(app_url('dashboard'));
        }
        return null;
    }
}
