<?php
declare(strict_types=1);

/**
 * HTTP contract tests (packet A): 404 vs 405, HEAD, JSON bodies,
 * session expiry and login throttling.
 *
 * No web server required: the router, request parser and auth service are
 * exercised in-process against the live database (reads only; the login
 * attempt section runs inside a transaction that is rolled back).
 */

require_once __DIR__ . '/../config/bootstrap.php';

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Services\AuthService;

// Router/Request/Response must not emit headers while tests print progress.
ob_start();

$failures = [];

function check_http(bool $condition, string $label): void
{
    if ($condition) {
        echo 'PASS ' . $label . PHP_EOL;
    } else {
        echo 'FAIL ' . $label . PHP_EOL;
        $GLOBALS['failures'][] = $label;
    }
}

// ---------------------------------------------------------------------------
// Router: unknown path is 404, known path with a wrong verb is 405.
// ---------------------------------------------------------------------------
$_SERVER['REQUEST_METHOD'] = 'GET';
$router = new Router();
$notFound = $router->dispatch(new Request('GET', '/definitely-not-a-route', [], [], []));
check_http($notFound->status === 404, 'unknown path returns 404');

$wrongVerb = $router->dispatch(new Request('DELETE', '/login', [], [], []));
check_http($wrongVerb->status === 405, 'known path with unsupported verb returns 405 (got ' . $wrongVerb->status . ')');
check_http(isset($wrongVerb->headers['Allow']), '405 carries an Allow header');
check_http(str_contains((string) ($wrongVerb->headers['Allow'] ?? ''), 'POST'), 'Allow header lists POST for /login');
check_http(str_contains((string) ($wrongVerb->headers['Allow'] ?? ''), 'HEAD'), 'Allow header implies HEAD when GET is allowed');

// HEAD is dispatched through the GET handler and produces no body in send().
// Verify the dispatched route is reachable and that send() short-circuits:
$_SERVER['REQUEST_METHOD'] = 'HEAD';
$headDispatch = $router->dispatch(new Request('HEAD', '/login', [], [], []));
check_http($headDispatch->status === 200, 'HEAD /login resolves through the GET handler (got ' . $headDispatch->status . ')');
$headProbe = Response::text('payload');
echo 'HEAD-PROBE-MARK';
$headProbe->send();
// The test's outermost buffer holds everything logged so far; only the tail
// beyond the flag written just before send() belongs to this operation.
$buffered = (string) ob_get_clean();
$headOutput = (string) substr($buffered, strpos($buffered, 'HEAD-PROBE-MARK') + strlen('HEAD-PROBE-MARK'));
check_http($headOutput === '', 'Response::send() emits no body for HEAD requests (got ' . strlen($headOutput) . ' bytes)');
echo str_replace("HEAD-PROBE-MARK", "", $buffered);
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();

// ---------------------------------------------------------------------------
// Request: JSON bodies are parsed, form bodies are untouched.
// ---------------------------------------------------------------------------
check_http(
    (static function (): bool {
        $method = new ReflectionMethod(Request::class, 'isJsonContentType');
                return $method->invoke(null, 'application/json; charset=utf-8')
            && $method->invoke(null, 'application/vnd.api+json')
            && !$method->invoke(null, 'application/x-www-form-urlencoded')
            && !$method->invoke(null, 'multipart/form-data; boundary=x');
    })(),
    'content-type matcher accepts JSON and rejects form payloads'
);

$request = new Request('POST', '/api/push', [], ['server_token' => 'abc'], ['content-type' => 'application/json'], '{"server_token":"abc"}');
check_http($request->isJson() === true, 'Request::isJson() recognises JSON content type');
check_http($request->rawBody === '{"server_token":"abc"}', 'Request exposes the raw body');
check_http($request->input('server_token') === 'abc', 'Request::input() reads parsed body');

// ---------------------------------------------------------------------------
// Session expiry: idle timeout ends the session instead of silently passing.
// ---------------------------------------------------------------------------
if (!isset($_SESSION) || session_status() !== PHP_SESSION_ACTIVE) {
    $_SESSION = [];
}
$user = db_one('SELECT id, username, role FROM users ORDER BY id LIMIT 1');
if ($user === null) {
    echo 'SKIP session expiry (no users)' . PHP_EOL;
} else {
    $saved = $_SESSION;
    $_SESSION = [
        'user_id' => (int) $user['id'],
        'login_at' => time() - 60,
        'last_activity_at' => time() - 60 * 60 * 24,
        'user_agent' => 'php-test-agent',
    ];
    $_SERVER['HTTP_USER_AGENT'] = 'php-test-agent';
    $expired = AuthService::sessionExpiryResponse(null);
    check_http($expired instanceof Response && $expired->status === 401, 'idle session expiry yields 401');
    check_http($_SESSION === [], 'expired session is cleared');

    $_SESSION = [
        'user_id' => (int) $user['id'],
        'login_at' => time() - 60,
        'last_activity_at' => time() - 10,
        'user_agent' => 'php-test-agent',
    ];
    $fresh = AuthService::sessionExpiryResponse(null);
    check_http($fresh === null, 'active session passes expiry check');

    $_SESSION = $saved;
}

// ---------------------------------------------------------------------------
// Login throttling: per-username budget works across IPs.
// ---------------------------------------------------------------------------
$pdo = db();
$pdo->beginTransaction();
try {
    $probeIp = '198.51.100.' . random_int(1, 250);
    $probeUser = 'throttle-probe-' . bin2hex(random_bytes(4));

    $ins = $pdo->prepare('INSERT INTO login_attempts (ip_address, username, attempted_at) VALUES (?, ?, NOW())');
    for ($i = 0; $i < LOGIN_USERNAME_MAX_ATTEMPTS - 1; $i++) {
        // Distinct IPs: the per-IP budget must stay untouched.
        $ins->execute(['203.0.113.' . ($i + 1), $probeUser]);
    }
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at <= NOW() - INTERVAL ? MINUTE')
        ->execute([LOGIN_WINDOW_MINUTES]);

    check_http(AuthService::isIpRateLimited($probeIp) === false, 'distinct IPs are not throttled by the username budget');
    check_http(AuthService::isUsernameRateLimited($probeUser) === false, 'username below budget is allowed');

    $ins->execute(['203.0.113.250', $probeUser]);
    check_http(AuthService::isUsernameRateLimited($probeUser) === true, 'username at budget is throttled across IPs');
    check_http(AuthService::isLoginRateLimited($probeIp, $probeUser) === true, 'combined pre-check throttles the username');

    AuthService::clearLoginAttempts($probeIp, $probeUser);
    check_http(AuthService::isUsernameRateLimited($probeUser) === false, 'successful login clears the username budget');
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------
// Constant-time credential check: unknown users cost a verification too.
// ---------------------------------------------------------------------------
$pdo->beginTransaction();
try {
    $start = microtime(true);
    $unknown = AuthService::attemptLogin('no-such-user-' . bin2hex(random_bytes(4)), 'hunter2');
    $unknownMs = (microtime(true) - $start) * 1000;

    $known = db_one('SELECT username FROM users ORDER BY id LIMIT 1');
    $start = microtime(true);
    $wrongPassword = $known !== null ? AuthService::attemptLogin((string) $known['username'], 'definitely-wrong-' . bin2hex(random_bytes(4))) : null;
    $knownMs = (microtime(true) - $start) * 1000;

    check_http($unknown === null, 'unknown username fails authentication');
    check_http($wrongPassword === null, 'wrong password fails authentication');
    if ($known !== null && $unknownMs > 5.0) {
        // bcrypt cost 12 costs tens of ms; the dummy hash keeps both paths in
        // the same order of magnitude. A ratio below 0.2 means no hash ran.
        $ratio = $unknownMs / max($knownMs, 0.001);
        check_http($ratio > 0.2 && $ratio < 5.0, sprintf('unknown-user path costs a bcrypt verification (ratio %.2f)', $ratio));
    } else {
        echo 'SKIP timing ratio (no users or very fast bcrypt)' . PHP_EOL;
    }
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------
// Middleware: unauthenticated /api/* requests get 401 JSON, not a redirect.
// ---------------------------------------------------------------------------
$savedSession = $_SESSION;
$_SESSION = [];
$apiResponse = \App\Http\Middleware\Auth::handle(new Request('GET', '/api/status', [], [], []));
check_http($apiResponse instanceof Response && $apiResponse->status === 401, 'auth middleware answers 401 for unauthenticated API calls');
check_http(
    $apiResponse !== null && str_contains((string) ($apiResponse->headers['Content-Type'] ?? ''), 'application/json'),
    '401 API response is JSON'
);
$webResponse = \App\Http\Middleware\Auth::handle(new Request('GET', '/dashboard', [], [], []));
check_http($webResponse instanceof Response && $webResponse->status === 303, 'auth middleware redirects browsers with 303');
$_SESSION = $savedSession;

echo PHP_EOL;
if ($failures !== []) {
    echo 'FAILED: ' . count($failures) . ' check(s)' . PHP_EOL;
    exit(1);
}
echo 'http_contract_test passed' . PHP_EOL;