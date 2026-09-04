<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../config/bootstrap.php';
exit((new \App\Console\Workers\RollupWorker())->run());
