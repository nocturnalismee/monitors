<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Http\Request;
use App\Http\Response;

/**
 * Short-lived, single-use handshake for the ping terminal stream.
 *
 * The terminal is an SSE stream. `EventSource` cannot attach request headers,
 * and putting the CSRF token in the query string would expose it to access
 * logs, Referer headers and browser history. The client first POSTs here
 * (authenticated session + CSRF header/form field as usual) and receives a
 * random nonce bound to the session, valid for one stream open.
 */
final class PingTerminalTokenController
{
    private const NONCE_TTL_SECONDS = 120;

    public function issue(Request $request): Response
    {
        require_role('admin');

        $token = (string) ($request->header('x-csrf-token') ?? '');
        if ($token === '') {
            $token = (string) ($request->input('_csrf_token') ?? '');
        }
        if (!csrf_validate($token)) {
            return Response::json(['error' => 'Invalid security token'], 419);
        }

        $nonce = bin2hex(random_bytes(24));
        $_SESSION['ping_terminal_nonce'] = [
            'value' => $nonce,
            'expires_at' => time() + self::NONCE_TTL_SECONDS,
        ];

        return Response::json(['nonce' => $nonce, 'expires_in' => self::NONCE_TTL_SECONDS]);
    }
}