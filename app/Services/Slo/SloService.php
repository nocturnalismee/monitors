<?php
declare(strict_types=1);
namespace App\Services\Slo;
final class SloService {
  public function availability(int $windowDays,int $thresholdMinutes): array {
    $now = time();
    $cutoffTs = $now - $windowDays*86400;
    $cutoff = date('Y-m-d H:i:s', $cutoffTs);
    $activeServers = 0;
    try { $row = db_one("SELECT COUNT(*) AS c FROM servers WHERE active=1"); $activeServers = (int)($row['c'] ?? $row['C'] ?? 0); } catch (\Throwable $e) { $activeServers = 0; }
    $budgetMin = $windowDays*24*60*0.001; // 99.9%
    // Effective window: never expect data older than the oldest evidence
    // (oldest retained metric or oldest active server creation). A fresh
    // install must not report 0% just because the 30d window is empty.
    $floorTs = $cutoffTs;
    try {
      $candidates = [];
      $r1 = db_one("SELECT MIN(recorded_at) AS m FROM metrics WHERE recorded_at>=:c", [':c' => $cutoff]);
      if (isset($r1['m']) && $r1['m'] !== null && ($ts = strtotime((string) $r1['m'])) !== false) $candidates[] = $ts;
      try {
        $r2 = db_one("SELECT MIN(recorded_at) AS m FROM metrics_history WHERE recorded_at>=:c", [':c' => $cutoff]);
        if (isset($r2['m']) && $r2['m'] !== null && ($ts = strtotime((string) $r2['m'])) !== false) $candidates[] = $ts;
      } catch (\Throwable $e) { /* history table may be absent */ }
      try {
        $r3 = db_one("SELECT MIN(created_at) AS m FROM servers WHERE active=1");
        if (isset($r3['m']) && $r3['m'] !== null && ($ts = strtotime((string) $r3['m'])) !== false) $candidates[] = $ts;
      } catch (\Throwable $e) { /* created_at may be absent */ }
      if ($candidates !== []) {
        $floorTs = max($cutoffTs, min($candidates));
      }
    } catch (\Throwable $e) { $floorTs = $cutoffTs; }
    $total = (int) (ceil(max(0, $now - $floorTs) / 300) * max(1, $activeServers));
    $unknown = ['availability_pct'=>null,'total_buckets'=>$total,'online_buckets'=>0,'downtime_minutes'=>null,'error_budget_minutes'=>round($budgetMin,2),'budget_remaining_minutes'=>null,'budget_remaining_pct'=>null,'burn_rate'=>null];
    try{
      $online = null;
      try {
        $row = db_one("SELECT COUNT(*) AS c FROM (SELECT DISTINCT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics_history WHERE bucket_seconds=300 AND recorded_at>=:cutoff1 UNION SELECT DISTINCT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics WHERE recorded_at>=:cutoff2) AS u",[':cutoff1'=>$cutoff, ':cutoff2'=>$cutoff]);
        $online = (int)($row['c'] ?? $row['C'] ?? 0);
      } catch (\Throwable $e) {
        $row = db_one("SELECT COUNT(*) AS c FROM (SELECT DISTINCT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics WHERE recorded_at>=:cutoff) AS u",[':cutoff'=>$cutoff]);
        $online = (int)($row['c'] ?? $row['C'] ?? 0);
      }
      if ($online === 0) return $unknown;
      if($online>$total) $online=$total;
      $downtimeMin = max(0, ($total - $online)*5);
      $availability = $total>0 ? ($online/$total*100) : 0;
      $remaining = $budgetMin - $downtimeMin;
      $remainingPct = $budgetMin>0 ? ($remaining/$budgetMin*100):0;
      $burn = $budgetMin>0 ? max(0, $downtimeMin/$budgetMin):0;
      return ['availability_pct'=>round($availability,3),'total_buckets'=>$total,'online_buckets'=>$online,'downtime_minutes'=>$downtimeMin,'error_budget_minutes'=>round($budgetMin,2),'budget_remaining_minutes'=>round($remaining,2),'budget_remaining_pct'=>round($remainingPct,2),'burn_rate'=>round($burn,3)];
    }catch(\Throwable $e){ $unknown['error']=$e->getMessage(); return $unknown;}
  }
}
