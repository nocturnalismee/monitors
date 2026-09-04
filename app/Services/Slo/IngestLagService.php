<?php
declare(strict_types=1);
namespace App\Services\Slo;
final class IngestLagService {
  public function percentiles(int $windowDays): array {
    $cutoff=date('Y-m-d H:i:s', time()-$windowDays*86400);
    try{
      $rows=db_all("SELECT recorded_at FROM metrics WHERE recorded_at>=:cutoff ORDER BY recorded_at DESC LIMIT 2000",[':cutoff'=>$cutoff]);
      $lags=[];
      $now=time();
      foreach($rows as $r){ $ts=strtotime((string)($r['recorded_at']??'')); if($ts===false) continue; $lags[]=max(0,($now-$ts)*1000);}
      sort($lags);
      $n=count($lags);
      if($n===0) return ['p50_ms'=>null,'p95_ms'=>null,'count'=>0];
      $p50=$lags[(int)floor(0.5*($n-1))]; $p95=$lags[(int)floor(0.95*($n-1))];
      return ['p50_ms'=>$p50,'p95_ms'=>$p95,'count'=>$n];
    }catch(\Throwable $e){ return ['p50_ms'=>null,'p95_ms'=>null,'count'=>0,'error'=>$e->getMessage()];}
  }
}
