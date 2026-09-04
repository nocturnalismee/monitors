<?php
declare(strict_types=1);

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/api/push',
        'handler' => [\App\Controllers\Api\PushController::class, 'index'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/api/push-disk',
        'handler' => [\App\Controllers\Api\PushDiskController::class, 'index'],
    ],
];
