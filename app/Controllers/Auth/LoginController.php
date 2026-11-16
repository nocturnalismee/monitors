<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Http\Request;
use App\Http\Response;
use App\Support\View;

final class LoginController
{
    public function index(Request $request): Response
    {
        if (current_user() !== null) {
            redirect('dashboard');
        }

        if ($request->query('logged_out') === '1') {
            flash_set('success', 'You have logged out.');
        }

        if (is_post()) {
            // CSRF single-guard: enforced by csrf middleware.
            $ip = get_client_ip();
            $username = trim((string) ($request->input('username') ?? ''));
            if (is_login_rate_limited($ip, $username)) {
                flash_set('danger', 'Too many login attempts. Please try again later.');
                redirect('login');
            }

            if (turnstile_is_configured() && !turnstile_validate(
                (string) ($request->input('cf-turnstile-response') ?? ''),
                $ip
            )) {
                register_login_attempt($ip, $username);
                flash_set('danger', 'Security verification failed. Please try again.');
                redirect('login');
            }

            $password = (string) ($request->input('password') ?? '');
            $user = attempt_login($username, $password);

            if ($user !== null) {
                login_user($user);
                clear_login_attempts($ip, $username);
                db_exec('UPDATE users SET last_login = NOW() WHERE id = :id', [':id' => (int) $user['id']]);
                audit_log('auth_login_success', 'User login success', 'user', (int) $user['id']);
                flash_set('success', 'Login successful.');
                redirect('dashboard');
            }

            register_login_attempt($ip, $username);
            audit_log('auth_login_failed', 'Login failed attempt', 'auth', null, ['username' => $username]);
            flash_set('danger', 'Invalid username or password.');
            redirect('login');
        }

        $turnstileEnabled = turnstile_is_configured();

        $uiSettings = settings_get_all();
        $brandingLogoRaw = trim((string) ($uiSettings['branding_logo_url'] ?? ''));
        $brandingLogoUrl = '';
        if ($brandingLogoRaw !== '') {
            if (preg_match('/^(https?:)?\/\//i', $brandingLogoRaw) === 1 || str_starts_with($brandingLogoRaw, 'data:')) {
                $brandingLogoUrl = $brandingLogoRaw;
            } else {
                $brandingLogoUrl = app_url(ltrim($brandingLogoRaw, '/'));
            }
        }

        $data = [
            'title' => APP_NAME . ' - Login Admin',
            'turnstileEnabled' => $turnstileEnabled,
            'brandingLogoUrl' => $brandingLogoUrl,
        ];
        return Response::html(View::render('auth/login', $data, 'auth'));
    }
}
