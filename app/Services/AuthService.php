<?php
declare(strict_types=1);

namespace App\Services;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\Database;

final class AuthService
{
    private static bool $userLoaded = false;
    private static ?array $userCache = null;

    public static function currentUser(): ?array
    {
        if (self::$userLoaded) {
            return self::$userCache;
        }
        self::$userLoaded = true;

        $userId = $_SESSION['user_id'] ?? null;
        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            return null;
        }

        $row = Database::one(
            'SELECT id, username, role, last_login, created_at FROM users WHERE id = :id LIMIT 1',
            [':id' => (int) $userId]
        );
        self::$userCache = $row;
        return self::$userCache;
    }

    public static function isLoggedIn(): bool
    {
        return self::currentUser() !== null;
    }

    private static function isApiRequest(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        return str_starts_with($path, '/api/');
    }

    private static function respondUnauthenticated(string $message): never
    {
        if (self::isApiRequest()) {
            json_response(['error' => 'Unauthorized', 'message' => $message], 401);
        }
        flash_set('warning', $message);
        redirect('login');
    }

    public static function requireLogin(): void
    {
        $expired = self::sessionExpiryResponse(null);
        if ($expired instanceof Response) {
            if (self::isApiRequest()) {
                json_response($expired->meta['jsonMessage'] ?? ['error' => 'Unauthorized'], 401);
            }
            flash_set('warning', 'Session expired or invalidated. Please log in again.');
            redirect('login');
        }

        if (self::currentUser() === null) {
            self::respondUnauthenticated('Please log in to continue.');
        }

        $_SESSION['last_activity_at'] = time();
    }

    /**
     * Evaluate session expiry without exiting the request.
     *
     * Returns a 401 Response when the session must be considered dead,
     * null otherwise. Shared by the auth middleware (defence in depth) and
     * require_login() so both enforce identical rules.
     */
    public static function sessionExpiryResponse(?Request $request = null): ?Response
    {
        $now = time();
        $idleTimeoutMinutes = max(5, (int) setting_get('session_idle_timeout_minutes'));
        $absoluteTimeoutMinutes = max(15, (int) setting_get('session_absolute_timeout_minutes'));

        $loginAt = (int) ($_SESSION['login_at'] ?? 0);
        $lastActivityAt = (int) ($_SESSION['last_activity_at'] ?? 0);

        $currentUserAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $sessionUserAgent = (string) ($_SESSION['user_agent'] ?? '');

        if ($loginAt <= 0) {
            return null;
        }

        $idleExpired = $lastActivityAt > 0 && ($now - $lastActivityAt) > ($idleTimeoutMinutes * 60);
        $absoluteExpired = ($now - $loginAt) > ($absoluteTimeoutMinutes * 60);
        $agentMismatch = $sessionUserAgent !== '' && $currentUserAgent !== $sessionUserAgent;

        if (!$idleExpired && !$absoluteExpired && !$agentMismatch) {
            return null;
        }

        self::logoutUser();
        $reason = $agentMismatch
            ? 'Session invalidated (browser changed). Please log in again.'
            : ($absoluteExpired ? 'Session expired (maximum lifetime reached).' : 'Session expired due to inactivity.');

        $response = Response::json(['error' => 'Unauthorized', 'message' => $reason], 401);
        return $response->withData('jsonMessage', ['error' => 'Unauthorized', 'message' => $reason]);
    }

    public static function roleRank(string $role): int
    {
        return match ($role) {
            'admin' => 20,
            'viewer' => 10,
            default => 0,
        };
    }

    public static function hasRole(string $requiredRole): bool
    {
        $user = self::currentUser();
        if ($user === null) {
            return false;
        }

        $currentRole = (string) ($user['role'] ?? '');
        return self::roleRank($currentRole) >= self::roleRank($requiredRole);
    }

    public static function requireRole(string $requiredRole): void
    {
        self::requireLogin();
        if (self::hasRole($requiredRole)) {
            return;
        }

        if (self::isApiRequest()) {
            json_response(['error' => 'Forbidden', 'message' => 'Insufficient permission to access this action.'], 403);
        }
        flash_set('danger', 'Insufficient permission to access this action.');
        redirect('dashboard');
    }

    public static function loginUser(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $now = time();
        $_SESSION['login_at'] = $now;
        $_SESSION['last_activity_at'] = $now;
        $_SESSION['user_agent'] = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    /**
     * Constant-time credential check.
     *
     * An unknown username still burns a bcrypt verification against a real
     * dummy hash so response timing cannot be used to enumerate accounts.
     * Returns the user row on success, null otherwise.
     */
    public static function attemptLogin(string $username, string $password): ?array
    {
        $user = Database::one(
            'SELECT id, username, password_hash, role FROM users WHERE username = :username LIMIT 1',
            [':username' => $username]
        );

        if ($user === null) {
            password_verify($password, self::dummyHash());
            return null;
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return null;
        }

        if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            if ($newHash !== false) {
                Database::exec(
                    'UPDATE users SET password_hash = :hash WHERE id = :id',
                    [':hash' => $newHash, ':id' => (int) $user['id']]
                );
            }
        }

        return $user;
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        if ($hash === null) {
            // Generated once per process: same cost as a real bcrypt hash.
            $hash = password_hash('monitors-timing-equalizer', PASSWORD_BCRYPT, ['cost' => 12]);
        }

        return (string) $hash;
    }

    public static function logoutUser(): void
    {
        $_SESSION = [];
        // Under the CLI test runner there is no HTTP response to attach the
        // expiry header to (and headers may already be sent); the session
        // array is cleared above either way.
        if (PHP_SAPI === 'cli' || headers_sent()) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            return;
        }
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function isIpRateLimited(string $ip): bool
    {
        return self::isRateLimited('ip_address', $ip, LOGIN_MAX_ATTEMPTS);
    }

    /**
     * Per-username throttle so distributed attacks against one account are
     * slowed even when they arrive from many IPs. The budget is intentionally
     * larger than the per-IP one because a shared office IP legitimately tries
     * several accounts.
     */
    public static function isUsernameRateLimited(string $username): bool
    {
        $username = trim($username);
        if ($username === '') {
            return false;
        }

        return self::isRateLimited('username', $username, LOGIN_USERNAME_MAX_ATTEMPTS);
    }

    /**
     * Combined pre-check used by the login controller: deleted rows are
     * pruned once, then both budgets are evaluated in a single pass.
     */
    public static function isLoginRateLimited(string $ip, string $username): bool
    {
        self::pruneAttempts();
        if (self::isRateLimited('ip_address', $ip, LOGIN_MAX_ATTEMPTS, false)) {
            return true;
        }

        $username = trim($username);
        if ($username === '') {
            return false;
        }

        return self::isRateLimited('username', $username, LOGIN_USERNAME_MAX_ATTEMPTS, false);
    }

    private static function isRateLimited(string $column, string $value, int $maxAttempts, bool $prune = true): bool
    {
        if ($prune) {
            self::pruneAttempts();
        }

        $cutoff = self::attemptCutoff();
        // $column is a controlled identifier (never user input), so the
        // interpolation below cannot be injected.
        $attempt = Database::one(
            "SELECT COUNT(*) AS total, MIN(attempted_at) AS first_attempt FROM login_attempts
             WHERE {$column} = :value
             AND attempted_at > :cutoff",
            [':value' => $value, ':cutoff' => $cutoff]
        );

        $total = (int) ($attempt['total'] ?? 0);
        if ($total < $maxAttempts) {
            return false;
        }

        $waitSeconds = min((int) pow(2, $total - $maxAttempts), 3600);
        $firstAttempt = (string) ($attempt['first_attempt'] ?? '');
        $firstTs = strtotime($firstAttempt);
        if ($firstTs === false) {
            return true;
        }
        return (time() - $firstTs) < $waitSeconds;
    }

    private static function attemptCutoff(): string
    {
        return date('Y-m-d H:i:s', time() - (LOGIN_WINDOW_MINUTES * 60));
    }

    private static function pruneAttempts(): void
    {
        Database::exec(
            'DELETE FROM login_attempts WHERE attempted_at <= :cutoff',
            [':cutoff' => self::attemptCutoff()]
        );
    }

    public static function registerLoginAttempt(string $ip, ?string $username): void
    {
        Database::exec(
            'INSERT INTO login_attempts (ip_address, username, attempted_at) VALUES (:ip, :username, NOW())',
            [':ip' => $ip, ':username' => $username]
        );
    }

    public static function clearLoginAttempts(string $ip, ?string $username = null): void
    {
        Database::exec('DELETE FROM login_attempts WHERE ip_address = :ip', [':ip' => $ip]);
        $username = trim((string) $username);
        if ($username !== '') {
            Database::exec('DELETE FROM login_attempts WHERE username = :username', [':username' => $username]);
        }
    }
}
