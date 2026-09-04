<?php
declare(strict_types=1);

use App\Controllers\Admin\DiskHealthController;
use App\Controllers\Admin\DiskHealthDetailController;

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/disk-health',
        'handler' => [DiskHealthController::class, 'index'],
        'middleware' => ['auth', 'csrf'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/disk-health/{id}',
        'handler' => [DiskHealthDetailController::class, 'index'],
        'middleware' => ['auth', 'csrf'],
    ],
];
