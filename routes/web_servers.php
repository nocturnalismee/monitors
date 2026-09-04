<?php
declare(strict_types=1);

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/servers',
        'handler' => [\App\Controllers\Admin\ServersController::class, 'index'],
        'middleware' => ['auth', 'csrf'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/servers/add',
        'handler' => [\App\Controllers\Admin\ServerAddController::class, 'index'],
        'middleware' => ['admin', 'csrf'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/servers/{id}',
        'handler' => [\App\Controllers\Admin\ServerDetailController::class, 'index'],
        'middleware' => ['auth', 'csrf'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/servers/{id}/edit',
        'handler' => [\App\Controllers\Admin\ServerEditController::class, 'index'],
        'middleware' => ['admin', 'csrf'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/servers/{id}/setup',
        'handler' => [\App\Controllers\Admin\ServerSetupController::class, 'index'],
        'middleware' => ['admin', 'csrf'],
    ],
];
