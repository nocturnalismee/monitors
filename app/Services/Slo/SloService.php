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
   *
   * Query shape: one GROUP BY server_id per table (metrics_history +
   * metrics), regardless of fleet size — never N x per-server COUNTs.
   * Buckets are counted from the global cutoff and then clamped to each
   * server's own window total; a young server has no rows before its
   * created_at anyway, so the clamp keeps the math exact.
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
    $total = 0; $minFloor = $now; $serverTotals = [];
    foreach ($servers as $srv) {
      $sid = (int)($srv['id'] ?? 0);
      if ($sid <= 0) continue;
      $createdTs = isset($srv['created_at']) && ($ts = strtotime((string) $srv['created_at'])) !== false ? $ts : $cutoffTs;
      $floorTs = max($cutoffTs, $createdTs);
      if ($floorTs < $minFloor) $minFloor = $floorTs;
      $perTotal = (int) ceil(max(0, $now - $floorTs) / 300);
      if ($perTotal <= 0) continue;
      $serverTotals[$sid] = $perTotal;
      $total += $perTotal;
    }
    if ($serverTotals === []) {
      $unknown['servers_measured'] = 0;
      $unknown['total_buckets'] = $total;
      return $unknown;
    }
    // PDO with emulated prepares off rejects duplicate named placeholders,
    // so each UNION branch gets its own IN-list (:ha_*/:hb_*, :ma_*/:mb_*).
    $params = [':cutoff1' => $cutoff, ':cutoff2' => $cutoff];
    $inA = [];
    $inB = [];
    $i = 0;
    foreach (array_keys($serverTotals) as $sid) {
      $pa = ':ha_' . $i;
      $pb = ':hb_' . $i;
      $inA[] = $pa;
      $inB[] = $pb;
      $params[$pa] = $sid;
      $params[$pb] = $sid;
      $i++;
    }
    $onlineByServer = [];
    try {
      if ($historyExists) {
        $rows = db_all("SELECT server_id, COUNT(*) AS c FROM (SELECT DISTINCT server_id, FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics_history WHERE bucket_seconds=300 AND recorded_at>=:cutoff1 AND server_id IN (" . implode(',', $inA) . ") UNION SELECT DISTINCT server_id, FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics WHERE recorded_at>=:cutoff2 AND server_id IN (" . implode(',', $inB) . ")) AS u GROUP BY server_id", $params);
      } else {
        $rows = db_all("SELECT server_id, COUNT(*) AS c FROM (SELECT DISTINCT server_id, FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics WHERE recorded_at>=:cutoff1 AND server_id IN (" . implode(',', $inA) . ")) AS u GROUP BY server_id", $params);
      }
      foreach ($rows as $r) {
        $onlineByServer[(int)($r['server_id'] ?? $r['SERVER_ID'] ?? 0)] = (int)($r['c'] ?? $r['C'] ?? 0);
      }
    } catch (\Throwable $e) {
      try {
        $rows = db_all("SELECT server_id, COUNT(*) AS c FROM (SELECT DISTINCT server_id, FLOOR(UNIX_TIMESTAMP(recorded_at)/300)*300 AS bkt FROM metrics WHERE recorded_at>=:cutoff1 AND server_id IN (" . implode(',', $inA) . ")) AS u GROUP BY server_id", $params);
        foreach ($rows as $r) {
          $onlineByServer[(int)($r['server_id'] ?? $r['SERVER_ID'] ?? 0)] = (int)($r['c'] ?? $r['C'] ?? 0);
        }
      } catch (\Throwable $e2) { $unknown['error']=$e2->getMessage(); return $unknown; }
    }
    try {
      $onlineSum = 0; $pctSum = 0.0; $measured = 0;
      foreach ($serverTotals as $sid => $perTotal) {
        $perOnline = $onlineByServer[$sid] ?? 0;
        if ($perOnline > $perTotal) $perOnline = $perTotal;
        $onlineSum += $perOnline;
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
