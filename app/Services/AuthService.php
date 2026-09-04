<?php
declare(strict_types=1);

namespace App\Services;

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
        $now = time();
        $idleTimeoutMinutes = max(5, (int) setting_get('session_idle_timeout_minutes'));
        $absoluteTimeoutMinutes = max(15, (int) setting_get('session_absolute_timeout_minutes'));

        $loginAt = (int) ($_SESSION['login_at'] ?? 0);
        $lastActivityAt = (int) ($_SESSION['last_activity_at'] ?? 0);

        $currentUserAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $sessionUserAgent = (string) ($_SESSION['user_agent'] ?? '');

        if ($loginAt > 0) {
            $idleExpired = $lastActivityAt > 0 && ($now - $lastActivityAt) > ($idleTimeoutMinutes * 60);
            $absoluteExpired = ($now - $loginAt) > ($absoluteTimeoutMinutes * 60);
            $agentMismatch = $sessionUserAgent !== '' && $currentUserAgent !== $sessionUserAgent;

            if ($idleExpired || $absoluteExpired || $agentMismatch) {
                self::logoutUser();
                self::respondUnauthenticated('Session expired or invalidated. Please log in again.');
            }
        }

        if (self::currentUser() === null) {
            self::respondUnauthenticated('Please log in to continue.');
        }

        $_SESSION['last_activity_at'] = $now;
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

    public static function logoutUser(): void
    {
        $_SESSION = [];
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
        $cutoff = date('Y-m-d H:i:s', time() - (LOGIN_WINDOW_MINUTES * 60));

        Database::exec(
            'DELETE FROM login_attempts WHERE attempted_at <= :cutoff',
            [':cutoff' => $cutoff]
        );

        $attempt = Database::one(
            'SELECT COUNT(*) AS total FROM login_attempts
             WHERE ip_address = :ip
             AND attempted_at > :cutoff',
            [':ip' => $ip, ':cutoff' => $cutoff]
        );

        return ((int) ($attempt['total'] ?? 0)) >= LOGIN_MAX_ATTEMPTS;
    }

    public static function registerLoginAttempt(string $ip, ?string $username): void
    {
        Database::exec(
            'INSERT INTO login_attempts (ip_address, username, attempted_at) VALUES (:ip, :username, NOW())',
            [':ip' => $ip, ':username' => $username]
        );
    }

    public static function clearLoginAttempts(string $ip): void
    {
        Database::exec('DELETE FROM login_attempts WHERE ip_address = :ip', [':ip' => $ip]);
    }
}
