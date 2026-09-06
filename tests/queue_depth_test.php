<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$svc=new \App\Services\Reliability\QueueDepthService();
$r=$svc->collect();
foreach(['alert_delivery_queue','alert_delivery_dead','export_jobs_queued','export_jobs_running'] as $k){ if(!array_key_exists($k,$r)){ echo "FAIL missing $k\n"; exit(1);} if(!is_int($r[$k])){ echo "FAIL not int $k\n"; exit(1);} }
echo "PASS queue_depth ".json_encode($r)."\n";
