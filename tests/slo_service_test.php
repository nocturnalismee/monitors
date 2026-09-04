<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$s=new \App\Services\Slo\SloService();
$r=$s->availability(30,5);
foreach(['availability_pct','total_buckets','online_buckets','downtime_minutes','error_budget_minutes','budget_remaining_minutes','budget_remaining_pct','burn_rate'] as $k) if(!array_key_exists($k,$r)){ echo "FAIL $k\n"; exit(1);}
// effective window: total must not exceed the full 30d window and must cover online buckets
$fullTotal = (int)(30*24*60/5);
try { $active = (int)(db_one("SELECT COUNT(*) AS c FROM servers WHERE active=1")['c']??0); } catch (\Throwable $e) { $active = 0; }
$maxTotal = $fullTotal * max(1,$active);
if((int)$r['total_buckets'] > $maxTotal){ echo "FAIL total exceeds window got ".var_export($r['total_buckets'],true)." max $maxTotal\n"; exit(1);}
if((int)$r['online_buckets'] > (int)$r['total_buckets']){ echo "FAIL online exceeds total\n"; exit(1);}
echo "PASS slo ".json_encode($r)."\n";
