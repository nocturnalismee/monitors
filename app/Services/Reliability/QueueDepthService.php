<?php
declare(strict_types=1);
namespace App\Services\Reliability;
final class QueueDepthService {
  public function collect(): array {
    try {
      // One conditional-SUM query per table instead of four COUNT scans.
      $a=db_one("SELECT COALESCE(SUM(CASE WHEN attempts<5 THEN 1 ELSE 0 END),0) AS pending, COALESCE(SUM(CASE WHEN attempts>=5 THEN 1 ELSE 0 END),0) AS dead FROM alert_delivery_queue WHERE delivered_at IS NULL");
      $q=db_one("SELECT COALESCE(SUM(CASE WHEN status='queued' THEN 1 ELSE 0 END),0) AS queued, COALESCE(SUM(CASE WHEN status='running' THEN 1 ELSE 0 END),0) AS running FROM export_jobs");
      return ['alert_delivery_queue'=>(int)($a['pending']??0),'alert_delivery_dead'=>(int)($a['dead']??0),'export_jobs_queued'=>(int)($q['queued']??0),'export_jobs_running'=>(int)($q['running']??0),'error'=>null];
    } catch(\Throwable $e){ return ['alert_delivery_queue'=>0,'alert_delivery_dead'=>0,'export_jobs_queued'=>0,'export_jobs_running'=>0,'error'=>$e->getMessage()];}
  }
}
