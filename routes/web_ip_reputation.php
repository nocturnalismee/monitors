<?php
declare(strict_types=1);

use App\Controllers\Admin\IpReputationController;
use App\Controllers\Admin\IpReputationAddController;
use App\Controllers\Admin\IpReputationDetailController;
use App\Controllers\Admin\IpReputationEditController;

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/ip-reputation',
        'handler' => [IpReputationController::class, 'index'],
        'middleware' => ['auth', 'csrf'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/ip-reputation/add',
        'handler' => [IpReputationAddController::class, 'index'],
        'middleware' => ['admin', 'csrf'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/ip-reputation/{id}/edit',
        'handler' => [IpReputationEditController::class, 'index'],
        'middleware' => ['admin', 'csrf'],
    ],
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/ip-reputation/{id}',
        'handler' => [IpReputationDetailController::class, 'index'],
        'middleware' => ['auth', 'csrf'],
    ],
];
