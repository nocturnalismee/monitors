<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

exit(\App\Console\ResetPasswordCommand::run($argv));
