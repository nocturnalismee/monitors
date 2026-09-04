<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$s=new \App\Services\Slo\IngestLagService();
$r=$s->percentiles(1);
foreach(['p50_ms','p95_ms','count'] as $k) if(!array_key_exists($k,$r)){ echo "FAIL $k\n"; exit(1);}
echo "PASS ingest ".json_encode($r)."\n";
