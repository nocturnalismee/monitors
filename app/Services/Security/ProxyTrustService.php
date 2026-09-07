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
    if ($remote === '' || filter_var($remote, FILTER_VALIDATE_IP) === false) return false;
    foreach ($list as $e) {
      $entry = trim((string)$e);
      if ($entry === '') continue;
      if (strcasecmp($remote, $entry)===0) return true;
      if (str_contains($entry, '/') && self::ipInCidr($remote, $entry)) return true;
    }
    return false;
  }
  public static function ipInCidr(string $ip, string $cidr): bool {
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) return false;
    $net = trim($parts[0]);
    $bitsStr = trim($parts[1]);
    if ($bitsStr === '' || !ctype_digit($bitsStr)) return false;
    $bits = (int)$bitsStr;
    $ipBin = @inet_pton(trim($ip));
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) return false;
    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) return false;
    $full = intdiv($bits, 8);
    if ($full > 0 && substr($ipBin, 0, $full) !== substr($netBin, 0, $full)) return false;
    $rem = $bits % 8;
    if ($rem > 0) {
      $mask = (0xFF << (8 - $rem)) & 0xFF;
      if ((ord($ipBin[$full]) & $mask) !== (ord($netBin[$full]) & $mask)) return false;
    }
    return true;
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
