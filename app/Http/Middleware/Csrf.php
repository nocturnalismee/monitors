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
            // Forms carry the token in the body; fetch()-based clients send it
            // as a header so it never has to appear in a URL or form payload.
            $token = (string) ($request->input('_csrf_token') ?? '');
            if ($token === '') {
                $token = (string) ($request->header('x-csrf-token') ?? '');
            }
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
