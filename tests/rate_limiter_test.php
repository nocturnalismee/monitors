<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$cls=\App\Support\RateLimiter::class;
if(!class_exists($cls)) $cls='RateLimiter';
// test clamp negative -> must not bypass (return false)
$res = api_rate_check('test_negative_'.time(), -5);
if ($res !== false) { echo "FAIL negative should deny\n"; exit(1);} echo "PASS negative deny\n";
// normal allow
$res2 = api_rate_check('test_allow_'.time(), 5);
if ($res2 !== true) { echo "FAIL allow should pass\n"; exit(1);} echo "PASS allow\n";
echo "ALL PASS rate_limiter\n";
