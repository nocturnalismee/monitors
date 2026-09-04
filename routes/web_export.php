<?php
declare(strict_types=1);

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/export',
        'handler' => [\App\Controllers\Admin\ExportController::class, 'index'],
        'middleware' => ['admin', 'csrf'],
    ],
    [
        'methods' => ['GET'],
        'pattern' => '/export/download/{id}',
        'handler' => [\App\Controllers\Admin\ExportDownloadController::class, 'index'],
        'middleware' => ['admin'],
    ],
];
