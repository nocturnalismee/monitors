<?php
declare(strict_types=1);

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/login',
        'handler' => [\App\Controllers\Auth\LoginController::class, 'index'],
        'middleware' => ['guest', 'csrf'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/logout',
        'handler' => [\App\Controllers\Auth\LogoutController::class, 'index'],
        'middleware' => ['auth', 'csrf'],
    ],
    [
        'methods' => ['GET'],
        'pattern' => '/status',
        'handler' => [\App\Controllers\Public\PublicStatusController::class, 'index'],
    ],
];
