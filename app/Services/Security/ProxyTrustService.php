<?php
declare(strict_types=1);
namespace App\Services\Security;
final class ProxyTrustService {
  public static function parseTrustedProxies(string $csv): array {
    $parts = explode(',', $csv);
    $out = [];
    foreach ($parts as $p) { $t = trim($p); if ($t !== '') $out[] = $t; }
    return $out;
  }
  public static function isTrustedProxy(string $remoteAddr, array $list): bool {
    $remote = trim($remoteAddr);
    if ($remote === '') return false;
    foreach ($list as $e) { if (strcasecmp($remote, trim((string)$e))===0) return true; }
    return false;
  }
  public static function isHttps(array $server, string $trustedCsv): bool {
    $https = !empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off' && $server['HTTPS'] !== '0';
    if ($https) return true;
    $trusted = self::parseTrustedProxies($trustedCsv);
    $remote = (string)($server['REMOTE_ADDR'] ?? '');
    if ($trusted === [] || !self::isTrustedProxy($remote, $trusted)) return false;
    $proto = strtolower(trim((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($proto === '') return false;
    foreach (explode(',', $proto) as $tok) {
      if (trim($tok) === 'https') return true;
    }
    return false;
  }
}
