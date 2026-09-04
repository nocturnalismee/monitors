<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$cls = \App\Services\Security\PushAllowlistValidator::class;
if(!class_exists($cls)){ echo "FAIL missing\n"; exit(1);}
function ae($a,$b,$m){ if($a!==$b){ echo "FAIL $m ".var_export($a,true)." vs ".var_export($b,true)."\n"; exit(1);} echo "PASS $m\n"; }
$r=$cls::validate('10.0.0.1, 10.0.0.0/24'); ae($r['valid'],true,'mixed valid'); ae($r['normalized'],'10.0.0.1, 10.0.0.0/24','norm');
$r=$cls::validate('10.0.0.1, 10.0.0.1'); ae(count($r['entries']),1,'dedupe');
$r=$cls::validate('10.0.0.0/33'); ae($r['valid'],false,'prefix 33 fail');
$r=$cls::validate(''); ae($r['valid'],true,'empty allow all');
$r=$cls::validate('2001:db8::1'); ae($r['valid'],true,'ipv6 single');
$r=$cls::validate(str_repeat('1.1.1.1, ',600)); ae($r['valid'],false,'length >2000 fail');
$r=$cls::validate("10.0.0.1 10.0.0.2\n10.0.0.3"); ae(count($r['entries']),3,'whitespace split');
echo "ALL PASS allowlist\n";
