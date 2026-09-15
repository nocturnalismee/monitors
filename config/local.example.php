<?php
declare(strict_types=1);

return [
    'APP_NAME' => 'monitors',
    'APP_ENV' => 'production',
    'APP_URL' => 'http://127.0.0.1:8000',
    'APP_TZ' => 'Asia/Jakarta',
    'APP_KEY' => '', // 64-character hex key (e.g. generated via bin2hex(random_bytes(32)))
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'monitoring',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'REDIS_ENABLED' => '0',
    'REDIS_HOST' => '127.0.0.1',
    'REDIS_PORT' => '6379',
    'REDIS_PASSWORD' => '',
    'REDIS_DB' => '0',
    'REDIS_PREFIX' => 'monitors',
    'TRUSTED_PROXIES' => '127.0.0.1,::1',
];
