<?php
declare(strict_types=1);

namespace App\Services;

use App\Http\Request;
use Throwable;

/**
 * Shared ingest authentication preamble for /api/push and /api/push-disk.
 *
 * Every failure responds with a JSON error and exits (via json_response),
 * so callers only continue on success. Both endpoints enforce the same
 * contract: token lookup, IP allowlist, per-server rate limit, body caps,
 * and HMAC-SHA256 request signing.
 */
final class PushAuthService
{
    /**
     * @param array{max_body_bytes?: int, rate_key?: string, rate_max?: int, signature_required?: bool, check_allowlist?: bool} $options
     * @return array{server: array, server_id: int, client_ip: string, raw: string, agent_ts: ?int, data: array}
     */
    public static function authenticate(Request $request, array $options): array
    {
        $maxBodyBytes = max(1, (int) ($options['max_body_bytes'] ?? 1048576));
        $rateKey = (string) ($options['rate_key'] ?? 'push_api');
        $rateMax = max(1, (int) ($options['rate_max'] ?? 600));
        $signatureRequired = (bool) ($options['signature_required'] ?? true);
        $checkAllowlist = (bool) ($options['check_allowlist'] ?? true);

        if (strtoupper($request->method) !== 'POST') {
            header('Allow: POST');
            json_response(['error' => 'Method not allowed'], 405);
        }

        $contentType = strtolower(trim((string) ($request->header('Content-Type') ?? '')));
        if (!str_starts_with($contentType, 'application/json')) {
            json_response(['error' => 'Content-Type must be application/json'], 415);
        }

        $contentLength = $request->header('Content-Length') !== null
            ? max(0, (int) $request->header('Content-Length'))
            : 0;
        if ($contentLength > $maxBodyBytes) {
            json_response(['error' => 'Payload too large'], 413);
        }

        $token = (string) ($request->header('X-Server-Token') ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            json_response(['error' => 'Invalid token'], 403);
        }

        try {
            $server = null;
            $tokenHash = hash('sha256', $token);
            if (db_column_exists('servers', 'push_allowed_ips')) {
                $server = db_one(
                    'SELECT id, active, push_allowed_ips FROM servers WHERE token_hash = :token_hash LIMIT 1',
                    [':token_hash' => $tokenHash]
                );
            } else {
                $server = db_one(
                    'SELECT id, active, NULL AS push_allowed_ips FROM servers WHERE token_hash = :token_hash LIMIT 1',
                    [':token_hash' => $tokenHash]
                );
            }
        } catch (Throwable $e) {
            error_log('push auth server lookup failed error=' . $e->getMessage());
            json_response(['error' => 'Database unavailable'], 503);
        }
        if ($server === null || (int) $server['active'] !== 1) {
            json_response(['error' => 'Invalid token'], 403);
        }

        $clientIp = get_client_ip();
        if ($checkAllowlist) {
            $allowlist = trim((string) ($server['push_allowed_ips'] ?? ''));
            if ($allowlist !== '' && !self::ipInAllowlist($clientIp, $allowlist)) {
                json_response(['error' => 'Source IP not allowed'], 403);
            }
        }

        // Rate limit per server token + client IP to prevent token leaks from flooding ingest.
        if (!api_rate_check($rateKey, (int) $server['id'] . ':' . $clientIp, $rateMax)) {
            api_rate_limit_exceeded();
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw)) {
            json_response(['error' => 'Unable to read request body'], 400);
        }
        if (trim($raw) === '') {
            json_response(['error' => 'Empty request body'], 400);
        }
        if (strlen($raw) > $maxBodyBytes) {
            json_response(['error' => 'Payload too large'], 413);
        }

        $signedTimestamp = trim((string) ($request->header('X-Server-Timestamp') ?? ''));
        $signedSignature = strtolower(trim((string) ($request->header('X-Server-Signature') ?? '')));
        $agentTs = null;

        if ($signedTimestamp !== '' || $signedSignature !== '' || $signatureRequired) {
            if ($signedTimestamp === '' || preg_match('/^\d{10}$/', $signedTimestamp) !== 1) {
                json_response(['error' => 'Invalid or missing X-Server-Timestamp'], 400);
            }
            if ($signedSignature === '' || preg_match('/^[a-f0-9]{64}$/', $signedSignature) !== 1) {
                json_response(['error' => 'Invalid or missing X-Server-Signature'], 400);
            }

            $requestTs = (int) $signedTimestamp;
            if (abs(time() - $requestTs) > 60) {
                json_response(['error' => 'Signature timestamp expired'], 403);
            }

            $expectedSig = hash_hmac('sha256', $signedTimestamp . '.' . (string) $raw, $token);
            if (!hash_equals($expectedSig, $signedSignature)) {
                json_response(['error' => 'Invalid request signature'], 403);
            }
            $agentTs = $requestTs;
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            json_response(['error' => 'Invalid JSON payload'], 400);
        }

        $serverId = isset($data['server_id']) ? (int) $data['server_id'] : (int) $server['id'];
        if ($serverId !== (int) $server['id']) {
            json_response(['error' => 'server_id does not match token'], 400);
        }

        return [
            'server' => $server,
            'server_id' => $serverId,
            'client_ip' => $clientIp,
            'raw' => $raw,
            'agent_ts' => $agentTs,
            'data' => $data,
        ];
    }

    public static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (str_contains($cidr, '/') === false) {
            return false;
        }
        [$network, $prefix] = explode('/', $cidr, 2);
        $network = trim((string) $network);
        $prefix = trim((string) $prefix);
        if ($network === '' || $prefix === '' || !ctype_digit($prefix)) {
            return false;
        }
        $prefixInt = (int) $prefix;
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($network);
        if ($ipBin === false || $netBin === false) {
            return false;
        }
        if (strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        if ($prefixInt < 0 || $prefixInt > $max) {
            return false;
        }
        if ($prefixInt === 0) {
            return true;
        }
        $bytes = intdiv($prefixInt, 8);
        $bits = $prefixInt % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($bits !== 0) {
            $mask = (0xFF << (8 - $bits)) & 0xFF;
            $ipByte = ord($ipBin[$bytes]);
            $netByte = ord($netBin[$bytes]);
            if (($ipByte & $mask) !== ($netByte & $mask)) {
                return false;
            }
        }
        return true;
    }

    public static function ipInAllowlist(string $ip, string $allowlist): bool
    {
        $trim = trim($allowlist);
        if ($trim === '') {
            return true;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $entries = preg_split('/[\s,]+/', $trim) ?: [];
        foreach ($entries as $entry) {
            $candidate = trim((string) $entry);
            if ($candidate === '') {
                continue;
            }
            if (str_contains($candidate, '/')) {
                if (self::ipMatchesCidr($ip, $candidate)) {
                    return true;
                }
                continue;
            }
            if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                continue;
            }
            $ipBin = @inet_pton($ip);
            $candBin = @inet_pton($candidate);
            if ($ipBin !== false && $candBin !== false) {
                if ($ipBin === $candBin) {
                    return true;
                }
                continue;
            }
            if (strcasecmp($ip, $candidate) === 0) {
                return true;
            }
        }
        return false;
    }
}
