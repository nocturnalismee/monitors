<?php
declare(strict_types=1);

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/api/stream',
        'handler' => [\App\Controllers\Api\LiveStreamController::class, 'index'],
        'middleware' => [],
    ],
];
