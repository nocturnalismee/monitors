<?php
declare(strict_types=1);

use App\Controllers\Api\HealthController;
use App\Controllers\Api\StatusController;
use App\Controllers\Api\TimeController;

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/api/status',
        'handler' => [StatusController::class, 'index'],
        'middleware' => [],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/api/health',
        'handler' => [HealthController::class, 'index'],
        'middleware' => [],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/api/time',
        'handler' => [TimeController::class, 'index'],
        'middleware' => [],
    ],
];
