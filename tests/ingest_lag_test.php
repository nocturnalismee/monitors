<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$svc = \App\Services\Slo\IngestLagService::class;
function ae($a, $b, $m) { if ($a !== $b) { echo "FAIL $m ".var_export($a, true)." vs ".var_export($b, true)."\n"; exit(1); } echo "PASS $m\n"; }
ae($svc::computeLagMs(1000, 995), 5000, 'lag 5s');
ae($svc::computeLagMs(1000, 1000), 0, 'lag zero');
ae($svc::computeLagMs(1000, 1005), 0, 'future clamped');
ae($svc::computeLagMs(1000, null), null, 'null ts');
ae($svc::computeLagMs(1000, 0), null, 'zero ts');
ae($svc::summarize([3000, 1000, 2000]), ['p50_ms' => 2000, 'p95_ms' => 2000, 'count' => 3], 'summarize sorts');
ae($svc::summarize([]), ['p50_ms' => null, 'p95_ms' => null, 'count' => 0], 'summarize empty');
$s = new $svc();
$r = $s->percentiles(1);
foreach (['p50_ms', 'p95_ms', 'count'] as $k) if (!array_key_exists($k, $r)) { echo "FAIL $k\n"; exit(1); }
echo "PASS ingest ".json_encode($r)."\n";
