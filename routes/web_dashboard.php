<?php
declare(strict_types=1);

use App\Controllers\Admin\DashboardController;
use App\Controllers\HomeController;

return [
    [
        'methods' => ['GET'],
        'pattern' => '/dashboard',
        'handler' => [DashboardController::class, 'index'],
        'middleware' => ['auth'],
    ],
    [
        'methods' => ['GET'],
        'pattern' => '/',
        'handler' => [HomeController::class, 'index'],
    ],
];
