<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

final class Csrf
{
    public static function handle(Request $request): ?Response
    {
        if ($request->isPost()) {
            $token = (string) ($request->input('_csrf_token') ?? '');
            if (!csrf_validate($token)) {
                flash_set('danger', 'Invalid security token. Please try again.');
                $target = app_url('dashboard');
                $referer = $request->referer();
                if ($referer !== '') {
                    $refererHost = (string) parse_url($referer, PHP_URL_HOST);
                    $appHost = APP_URL !== '' ? (string) parse_url(APP_URL, PHP_URL_HOST) : '';
                    if ($appHost !== '' && strcasecmp($refererHost, $appHost) === 0) {
                        $target = $referer;
                    }
                }
                return Response::redirect($target, 303);
            }
        }
        return null;
    }
}
