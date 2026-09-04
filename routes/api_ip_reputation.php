<?php
declare(strict_types=1);

use App\Controllers\Api\IpReputationApiController;

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/api/ip-reputation',
        'handler' => [IpReputationApiController::class, 'index'],
    ],
];
