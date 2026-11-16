<?php
declare(strict_types=1);

use App\Services\AuthService;

function current_user(): ?array
{
    return AuthService::currentUser();
}

function is_logged_in(): bool
{
    return AuthService::isLoggedIn();
}

function require_login(): void
{
    AuthService::requireLogin();
}

function has_role(string $requiredRole): bool
{
    return AuthService::hasRole($requiredRole);
}

function require_role(string $requiredRole): void
{
    AuthService::requireRole($requiredRole);
}

function login_user(array $user): void
{
    AuthService::loginUser($user);
}

function logout_user(): void
{
    AuthService::logoutUser();
}

function is_ip_rate_limited(string $ip): bool
{
    return AuthService::isIpRateLimited($ip);
}

function is_username_rate_limited(string $username): bool
{
    return AuthService::isUsernameRateLimited($username);
}

function is_login_rate_limited(string $ip, string $username): bool
{
    return AuthService::isLoginRateLimited($ip, $username);
}

function attempt_login(string $username, string $password): ?array
{
    return AuthService::attemptLogin($username, $password);
}

function register_login_attempt(string $ip, ?string $username): void
{
    AuthService::registerLoginAttempt($ip, $username);
}

function clear_login_attempts(string $ip, ?string $username = null): void
{
    AuthService::clearLoginAttempts($ip, $username);
}
