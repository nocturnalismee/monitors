<?php
declare(strict_types=1);
namespace App\Services\Slo;
final class SloService {
  public function availability(int $windowDays,int $thresholdMinutes): array {
    $totalBuckets = (int)($windowDays*24*60/5);
    $activeServers = 0;
    try { $activeServers = (int)(db_one("SELECT COUNT(*) AS c FROM servers WHERE active=1")['c']??0); } catch (\Throwable $e) { $activeServers = 0; }
    $total = $totalBuckets * max(1,$activeServers);
    $cutoff = date('Y-m-d H:i:s', time()-$windowDays*86400);
    try{
      $rows=db_all("SELECT DISTINCT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics_history WHERE bucket_seconds=300 AND recorded_at>=:cutoff1 UNION SELECT DISTINCT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics WHERE recorded_at>=:cutoff2 LIMIT 2000",[':cutoff1'=>$cutoff, ':cutoff2'=>$cutoff]);
      $online = count($rows);
      // cap total vs online
      if($online>$total) $online=$total;
      $downtimeMin = max(0, ($total - $online)*5);
      $availability = $total>0 ? ($online/$total*100) : 0;
      $budgetMin = $windowDays*24*60*0.001; // 99.9%
      $remaining = $budgetMin - $downtimeMin;
      $remainingPct = $budgetMin>0 ? ($remaining/$budgetMin*100):0;
      $burn = $budgetMin>0 ? ($downtimeMin/$budgetMin):0;
      return ['availability_pct'=>round($availability,3),'total_buckets'=>$total,'online_buckets'=>$online,'downtime_minutes'=>$downtimeMin,'error_budget_minutes'=>round($budgetMin,2),'budget_remaining_minutes'=>round($remaining,2),'budget_remaining_pct'=>round($remainingPct,2),'burn_rate'=>round($burn,3)];
    }catch(\Throwable $e){ return ['availability_pct'=>0,'total_buckets'=>$total,'online_buckets'=>0,'downtime_minutes'=>0,'error_budget_minutes'=>0,'budget_remaining_minutes'=>0,'budget_remaining_pct'=>0,'burn_rate'=>0,'error'=>$e->getMessage()];}
  }
}
