<?php
declare(strict_types=1);
namespace App\Services\Slo;
final class IngestLagService {
  public static function computeLagMs(int $serverNow, ?int $agentTs): ?int {
    if ($agentTs === null || $agentTs <= 0) return null;
    return max(0, ($serverNow - $agentTs) * 1000);
  }
  /** @return array{p50_ms:?int,p95_ms:?int,count:int} */
  public static function summarize(array $lagsMs): array {
    $lags = array_values(array_filter($lagsMs, static fn($v): bool => is_int($v) && $v >= 0));
    sort($lags);
    $n = count($lags);
    if ($n === 0) return ['p50_ms' => null, 'p95_ms' => null, 'count' => 0];
    return ['p50_ms' => $lags[(int) floor(0.5 * ($n - 1))], 'p95_ms' => $lags[(int) floor(0.95 * ($n - 1))], 'count' => $n];
  }
  public function percentiles(int $windowDays): array {
    $cutoff=date('Y-m-d H:i:s', time()-$windowDays*86400);
    try{
      if (function_exists('db_column_exists') && db_column_exists('metrics', 'ingest_lag_ms')) {
        $rows=db_all("SELECT ingest_lag_ms FROM metrics WHERE recorded_at>=:cutoff AND ingest_lag_ms IS NOT NULL ORDER BY recorded_at DESC LIMIT 2000",[':cutoff'=>$cutoff]);
        $stored=[];
        foreach($rows as $r){ if(isset($r['ingest_lag_ms'])) $stored[]=(int)$r['ingest_lag_ms']; }
        if ($stored !== []) return self::summarize($stored);
      }
      $rows=db_all("SELECT recorded_at FROM metrics WHERE recorded_at>=:cutoff ORDER BY recorded_at DESC LIMIT 2000",[':cutoff'=>$cutoff]);
      $lags=[];
      $now=time();
      foreach($rows as $r){ $ts=strtotime((string)($r['recorded_at']??'')); if($ts===false) continue; $lags[]=max(0,($now-$ts)*1000);}
      return self::summarize($lags);
    }catch(\Throwable $e){ return ['p50_ms'=>null,'p95_ms'=>null,'count'=>0,'error'=>$e->getMessage()];}
  }
}
