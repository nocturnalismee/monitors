<?php
declare(strict_types=1);

return [
    [
        'methods' => ['GET', 'POST'],
        'pattern' => '/settings',
        'handler' => [\App\Controllers\Admin\SettingsController::class, 'index'],
        'middleware' => ['admin', 'csrf'],
    ],
];
