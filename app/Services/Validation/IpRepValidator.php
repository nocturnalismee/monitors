<?php
declare(strict_types=1);

namespace App\Services\Validation;

/**
 * Shared validation for the IP reputation target add/edit forms.
 *
 * Note: flash messages are escaped at render time (Views/layouts/flash.php),
 * so messages must NOT pre-escape values with htmlspecialchars().
 */
final class IpRepValidator
{
    /** @return array{ip_address: string, label: string, server_id: int, check_interval_hours: int} */
    public static function normalizeTarget(array $input): array
    {
        return [
            'ip_address' => trim((string) ($input['ip_address'] ?? '')),
            'label' => trim((string) ($input['label'] ?? '')),
            'server_id' => (int) ($input['server_id'] ?? 0),
            'check_interval_hours' => max(1, (int) ($input['check_interval_hours'] ?? 6)),
        ];
    }

    /**
     * @param array{ip_address: string} $v
     * @param int|null $excludeId existing target id to exclude from the duplicate check (edit form), null on add.
     */
    public static function checkTarget(array $v, ?int $excludeId): ?string
    {
        $ip = (string) ($v['ip_address'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'Invalid IP address format.';
        }
        if ($excludeId === null) {
            $existing = db_one('SELECT id FROM ip_reputation_targets WHERE ip_address = :ip', [':ip' => $ip]);
        } else {
            $existing = db_one('SELECT id FROM ip_reputation_targets WHERE ip_address = :ip AND id != :id', [':ip' => $ip, ':id' => $excludeId]);
        }
        if ($existing !== null) {
            return 'IP address ' . $ip . ($excludeId === null ? ' is already monitored.' : ' is already monitored by another target.');
        }
        return null;
    }

    /** @return array{ip_address: string, label: string, server_id: int, check_interval_hours: int} */
    public static function validateTarget(array $input, ?int $excludeId, string $failPath): array
    {
        $v = self::normalizeTarget($input);
        $error = self::checkTarget($v, $excludeId);
        if ($error !== null) {
            flash_set('danger', $error);
            redirect($failPath);
        }
        return $v;
    }
}
