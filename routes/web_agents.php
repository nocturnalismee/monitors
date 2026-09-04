<?php
declare(strict_types=1);

use App\Controllers\Public\AgentDownloadController;

return [
    [
        'methods' => ['GET'],
        'pattern' => '/agents/systemd/{file}',
        'handler' => [AgentDownloadController::class, 'download'],
        'middleware' => [],
    ],
    [
        'methods' => ['GET'],
        'pattern' => '/agents/{file}',
        'handler' => [AgentDownloadController::class, 'downloadRoot'],
        'middleware' => [],
    ],
];
