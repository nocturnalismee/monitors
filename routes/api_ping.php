<?php
declare(strict_types=1);

use App\Controllers\Api\PingDetailController;

return [
    [
        'methods' => ['GET'],
        'pattern' => '/api/ping-detail',
        'handler' => [PingDetailController::class, 'index'],
        'middleware' => [],
    ],
];
