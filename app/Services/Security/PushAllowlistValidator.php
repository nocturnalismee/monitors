<?php
declare(strict_types=1);
namespace App\Services\Security;
final class PushAllowlistValidator {
  public static function validate(string $raw): array {
    $trim = trim($raw);
    if ($trim === '') return ['valid'=>true,'error'=>null,'normalized'=>'','entries'=>[]];
    if (mb_strlen($raw) > 2000) return ['valid'=>false,'error'=>'Allowlist too long (max 2000 chars)','normalized'=>null,'entries'=>[]];
    $parts = preg_split('/[\s,]+/', $trim);
    $entries=[]; $seen=[];
    foreach ($parts as $p) { $e=trim((string)$p); if($e==='') continue; if(isset($seen[$e])) continue; $seen[$e]=true; $entries[]=$e; }
    foreach ($entries as $e) {
      if (str_contains($e,'/')) {
        [$ip,$pref]=explode('/',$e,2);
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) && !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)) return ['valid'=>false,'error'=>"Invalid IP in CIDR: {$e}",'normalized'=>null,'entries'=>[]];
        if (!ctype_digit($pref)) return ['valid'=>false,'error'=>"Invalid prefix in: {$e}",'normalized'=>null,'entries'=>[]];
        $isV6 = filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6) !== false;
        $max = $isV6 ? 128 : 32;
        $pi=(int)$pref; if($pi<0||$pi>$max) return ['valid'=>false,'error'=>"Invalid prefix in: {$e}",'normalized'=>null,'entries'=>[]];
      } else {
        if (!filter_var($e,FILTER_VALIDATE_IP)) return ['valid'=>false,'error'=>"Invalid IP: {$e}",'normalized'=>null,'entries'=>[]];
      }
    }
    return ['valid'=>true,'error'=>null,'normalized'=>implode(', ', $entries),'entries'=>$entries];
  }
}
