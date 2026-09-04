<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

$svc = \App\Services\Security\ProxyTrustService::class;
if (!class_exists($svc)) { echo "FAIL: class missing\n"; exit(1); }
function assert_eq($a,$b,$msg){ if($a!==$b){ echo "FAIL $msg ".var_export($a,true)." vs ".var_export($b,true)."\n"; exit(1);} echo "PASS $msg\n"; }
// spoof xhttpsy must be false even with trusted proxy
$server = ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_PROTO'=>'xhttpsy'];
assert_eq($svc::isHttps($server,'10.0.0.1'), false, 'xhttpsy strict false');
// strict https true
$server = ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_PROTO'=>'https'];
assert_eq($svc::isHttps($server,'10.0.0.1'), true, 'https true');
// comma list https, http -> true if any token https and trusted
$server = ['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_PROTO'=>'https, http'];
assert_eq($svc::isHttps($server,'10.0.0.1'), true, 'comma https true');
// non-trusted remote -> false even with https header
$server = ['REMOTE_ADDR'=>'1.2.3.4','HTTP_X_FORWARDED_PROTO'=>'https'];
assert_eq($svc::isHttps($server,'10.0.0.1'), false, 'non-trusted false');
// parse
assert_eq($svc::parseTrustedProxies('127.0.0.1, ::1 ,'), ['127.0.0.1','::1'], 'parse trim');
// isTrusted
assert_eq($svc::isTrustedProxy('10.0.0.1',['10.0.0.1']), true, 'isTrusted true');
assert_eq($svc::isTrustedProxy('1.1.1.1',['10.0.0.1']), false, 'isTrusted false');
echo "ALL PASS proxy_trust\n";
