<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

final class Admin
{
    public static function handle(Request $request): ?Response
    {
        if (!has_role('admin')) {
            return Response::redirect(app_url('dashboard'));
        }
        return null;
    }
}
