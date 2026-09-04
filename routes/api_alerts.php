<?php
declare(strict_types=1);

use App\Controllers\Api\AlertsApiController;
use App\Controllers\Api\EventsController;
use App\Controllers\Api\PublicAlertsController;

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/api/alerts',
        'handler' => [AlertsApiController::class, 'index'],
        'middleware' => [],
    ],
    [
        'methods' => ['GET'],
        'pattern' => '/api/events',
        'handler' => [EventsController::class, 'index'],
        'middleware' => [],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/api/public-alerts',
        'handler' => [PublicAlertsController::class, 'index'],
        'middleware' => [],
    ],
];
