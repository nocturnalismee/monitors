<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$s=new \App\Services\Slo\SloService();
$r=$s->availability(30,5);
foreach(['availability_pct','total_buckets','online_buckets','downtime_minutes','error_budget_minutes','budget_remaining_minutes','budget_remaining_pct','burn_rate'] as $k) if(!array_key_exists($k,$r)){ echo "FAIL $k\n"; exit(1);}
// corrected: bucket column & is_active -> active, strict type float vs int, and multiplied total for active servers
$expectedBuckets = (int)(30*24*60/5);
try { $active = (int)(db_one("SELECT COUNT(*) AS c FROM servers WHERE active=1")['c']??0); } catch (\Throwable $e) { $active = 0; }
$expectedTotal = $expectedBuckets * max(1,$active);
if((int)$r['total_buckets'] !== $expectedTotal){ echo "FAIL total buckets got ".var_export($r['total_buckets'],true)." expected $expectedTotal\n"; exit(1);}
echo "PASS slo ".json_encode($r)."\n";
