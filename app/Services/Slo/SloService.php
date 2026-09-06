<?php
declare(strict_types=1);
namespace App\Services\Slo;
final class SloService {
  /**
   * Honest availability: computed PER active server, then averaged.
   * One healthy server can no longer mask N down servers.
   * When retention (or a young server) truncates the window, the result
   * carries window_truncated=true + window_from so the UI can label it
   * explicitly (e.g. "dihitung 7 dari 30 hari").
   */
  public function availability(int $windowDays,int $thresholdMinutes): array {
    $now = time();
    $cutoffTs = $now - $windowDays*86400;
    $cutoff = date('Y-m-d H:i:s', $cutoffTs);
    $budgetMin = $windowDays*24*60*0.001; // 99.9%
    $unknown = ['availability_pct'=>null,'total_buckets'=>0,'online_buckets'=>0,'downtime_minutes'=>null,'error_budget_minutes'=>round($budgetMin,2),'budget_remaining_minutes'=>null,'budget_remaining_pct'=>null,'burn_rate'=>null,'servers_measured'=>0,'window_truncated'=>false,'window_from'=>$cutoff,'window_requested_from'=>$cutoff,'effective_days'=>round($windowDays,1)];
    try {
      $servers = db_all("SELECT id, created_at FROM servers WHERE active=1");
    } catch (\Throwable $e) { $unknown['error']=$e->getMessage(); return $unknown; }
    if ($servers === []) return $unknown;
    $historyExists = true;
    try { $historyExists = \App\Services\RetentionService::retention_table_exists('metrics_history'); }
    catch (\Throwable $e) { $historyExists = true; }
    $total = 0; $onlineSum = 0; $pctSum = 0.0; $measured = 0; $minFloor = $now;
    try {
      foreach ($servers as $srv) {
        $createdTs = isset($srv['created_at']) && ($ts = strtotime((string) $srv['created_at'])) !== false ? $ts : $cutoffTs;
        $floorTs = max($cutoffTs, $createdTs);
        if ($floorTs < $minFloor) $minFloor = $floorTs;
        $perTotal = (int) ceil(max(0, $now - $floorTs) / 300);
        if ($perTotal <= 0) continue;
        $floor = date('Y-m-d H:i:s', $floorTs);
        $sid = (int) ($srv['id'] ?? 0);
        $perOnline = 0;
        try {
          if ($historyExists) {
            $row = db_one("SELECT COUNT(*) AS c FROM (SELECT DISTINCT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics_history WHERE bucket_seconds=300 AND server_id=:sid AND recorded_at>=:cutoff1 UNION SELECT DISTINCT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics WHERE server_id=:sid2 AND recorded_at>=:cutoff2) AS u",[':sid'=>$sid, ':cutoff1'=>$floor, ':sid2'=>$sid, ':cutoff2'=>$floor]);
          } else {
            $row = db_one("SELECT COUNT(*) AS c FROM (SELECT DISTINCT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics WHERE server_id=:sid AND recorded_at>=:cutoff) AS u",[':sid'=>$sid, ':cutoff'=>$floor]);
          }
          $perOnline = (int)($row['c'] ?? $row['C'] ?? 0);
        } catch (\Throwable $e) {
          $row = db_one("SELECT COUNT(*) AS c FROM (SELECT DISTINCT FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics WHERE server_id=:sid AND recorded_at>=:cutoff) AS u",[':sid'=>$sid, ':cutoff'=>$floor]);
          $perOnline = (int)($row['c'] ?? $row['C'] ?? 0);
        }
        if ($perOnline > $perTotal) $perOnline = $perTotal;
        $total += $perTotal; $onlineSum += $perOnline;
        $pctSum += $perOnline / $perTotal * 100;
        $measured++;
      }
      if ($measured === 0 || $onlineSum === 0) {
        $unknown['servers_measured'] = $measured;
        $unknown['total_buckets'] = $total;
        return $unknown;
      }
      $truncated = $minFloor > $cutoffTs;
      $downtimeMin = max(0, ($total - $onlineSum)*5);
      $availability = $pctSum / $measured; // mean of per-server availability
      $remaining = $budgetMin - $downtimeMin;
      $remainingPct = $budgetMin>0 ? ($remaining/$budgetMin*100):0;
      $burn = $budgetMin>0 ? max(0, $downtimeMin/$budgetMin):0;
      return ['availability_pct'=>round($availability,3),'total_buckets'=>$total,'online_buckets'=>$onlineSum,'downtime_minutes'=>$downtimeMin,'error_budget_minutes'=>round($budgetMin,2),'budget_remaining_minutes'=>round($remaining,2),'budget_remaining_pct'=>round($remainingPct,2),'burn_rate'=>round($burn,3),'servers_measured'=>$measured,'window_truncated'=>$truncated,'window_from'=>date('Y-m-d H:i:s',$minFloor),'window_requested_from'=>$cutoff,'effective_days'=>round(($now-$minFloor)/86400,1)];
    } catch(\Throwable $e){ $unknown['error']=$e->getMessage(); return $unknown;}
  }
}
