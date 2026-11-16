<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

final class Admin
{
    public static function handle(Request $request): ?Response
    {
        if (has_role('admin')) {
            return null;
        }

        if (str_starts_with('/' . trim($request->path, '/'), '/api/')) {
            return Response::json(['error' => 'Forbidden', 'message' => 'Insufficient permission to access this action.'], 403);
        }

        flash_set('danger', 'Insufficient permission to access this action.');
        return Response::redirect(app_url('dashboard'), 303);
    }
}
