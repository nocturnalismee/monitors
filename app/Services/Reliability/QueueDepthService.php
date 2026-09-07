<?php
declare(strict_types=1);
namespace App\Services\Reliability;
final class QueueDepthService {
  public function collect(): array {
    try {
      $a=db_one("SELECT COUNT(*) AS c FROM alert_delivery_queue WHERE delivered_at IS NULL AND attempts<5");
      $d=db_one("SELECT COUNT(*) AS c FROM alert_delivery_queue WHERE delivered_at IS NULL AND attempts>=5");
      $q=db_one("SELECT COUNT(*) AS c FROM export_jobs WHERE status='queued'");
      $r=db_one("SELECT COUNT(*) AS c FROM export_jobs WHERE status='running'");
      return ['alert_delivery_queue'=>(int)($a['c']??$a['COUNT(*)']??0),'alert_delivery_dead'=>(int)($d['c']??$d['COUNT(*)']??0),'export_jobs_queued'=>(int)($q['c']??0),'export_jobs_running'=>(int)($r['c']??0),'error'=>null];
    } catch(\Throwable $e){ return ['alert_delivery_queue'=>0,'alert_delivery_dead'=>0,'export_jobs_queued'=>0,'export_jobs_running'=>0,'error'=>$e->getMessage()];}
  }
}
