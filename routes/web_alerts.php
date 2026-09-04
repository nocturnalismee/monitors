<?php
declare(strict_types=1);

use App\Controllers\Admin\AlertLogsController;
use App\Controllers\Admin\AuditLogsController;

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/alerts',
        'handler' => [AlertLogsController::class, 'index'],
        'middleware' => ['auth', 'csrf'],
    ],
    [
        'methods' => ['GET'],
        'pattern' => '/audit-logs',
        'handler' => [AuditLogsController::class, 'index'],
        'middleware' => ['admin'],
    ],
];
