<?php
declare(strict_types=1);

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/ping',
        'handler' => [\App\Controllers\Admin\PingMonitorsController::class, 'index'],
        'middleware' => ['auth', 'csrf'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/ping/add',
        'handler' => [\App\Controllers\Admin\PingAddController::class, 'index'],
        'middleware' => ['admin', 'csrf'],
    ],
    [
        'methods' => ['GET'],
        'pattern' => '/ping/terminal/stream',
        'handler' => [\App\Controllers\Admin\PingTerminalController::class, 'stream'],
        'middleware' => ['admin'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/ping/{id}',
        'handler' => [\App\Controllers\Admin\PingDetailController::class, 'index'],
        'middleware' => ['auth', 'csrf'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/ping/{id}/edit',
        'handler' => [\App\Controllers\Admin\PingEditController::class, 'index'],
        'middleware' => ['admin', 'csrf'],
    ],
];
