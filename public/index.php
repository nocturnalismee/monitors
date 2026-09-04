<?php
declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

$kernel = new \App\Http\Kernel();
$response = $kernel->handle(\App\Http\Request::capture());
$response->send();
