<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;

final class HomeController
{
    public function index(Request $request): Response
    {
        if (is_logged_in()) {
            return Response::redirect(app_url('dashboard'));
        }
        return Response::redirect(app_url('login'));
    }
}
