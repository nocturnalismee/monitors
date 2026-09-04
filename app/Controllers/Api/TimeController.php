<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;

final class TimeController
{
    public function index(Request $request): Response
    {
        if ($request->method !== 'GET') {
            return Response::json(['error' => 'Method not allowed'], 405)->withHeader('Allow', 'GET');
        }

        return Response::json([
            'unix_time' => time(),
            'iso8601' => date(DATE_ATOM),
        ])->withHeader('Expires', '0');
    }
}
