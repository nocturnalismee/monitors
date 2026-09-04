<?php
declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Http\Request;
use App\Http\Response;

final class LogoutController
{
    public function index(Request $request): Response
    {
        if (!is_post()) {
            flash_set('warning', 'Use secure logout button.');
            redirect('dashboard');
        }

        // CSRF single-guard: enforced by csrf middleware (POST only; GET passes through).

        audit_log('auth_logout', 'User logout via secure POST', 'auth');
        logout_user();
        redirect('login?logged_out=1');
    }
}
