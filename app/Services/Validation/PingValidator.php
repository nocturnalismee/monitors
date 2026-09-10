<?php
declare(strict_types=1);

namespace App\Services\Validation;

/**
 * Shared validation for the ping monitor add/edit forms.
 */
final class PingValidator
{
    /**
     * @return array{name: string, target: string, target_type: string, check_method: string, check_interval_seconds: int, timeout_seconds: int, failure_threshold: int, active: int}
     */
    public static function normalizeMonitor(array $input): array
    {
        $checkMethod = ping_normalize_check_method((string) ($input['check_method'] ?? 'icmp'));
        $targetType = ping_normalize_target_type((string) ($input['target_type'] ?? 'domain'));
        if ($checkMethod === 'http') {
            $targetType = 'url';
        } elseif ($targetType === 'url') {
            $targetType = 'domain';
        }
        return [
            'name' => trim((string) ($input['name'] ?? '')),
            'target' => ping_normalize_target((string) ($input['target'] ?? '')),
            'target_type' => $targetType,
            'check_method' => $checkMethod,
            'check_interval_seconds' => max(30, min(3600, (int) ($input['check_interval_seconds'] ?? 60))),
            'timeout_seconds' => max(1, min(10, (int) ($input['timeout_seconds'] ?? 2))),
            'failure_threshold' => max(1, min(10, (int) ($input['failure_threshold'] ?? 2))),
            'active' => ($input['active'] ?? null) !== null ? 1 : 0,
        ];
    }

    /** @param array{name: string, target: string, target_type: string, check_method: string} $v */
    public static function checkMonitor(array $v): ?string
    {
        if (($v['name'] ?? '') === '') {
            return 'Monitor name is required.';
        }
        if (!ping_validate_target((string) ($v['target'] ?? ''), (string) ($v['target_type'] ?? ''), (string) ($v['check_method'] ?? 'icmp'))) {
            return 'Invalid ping target for selected type.';
        }
        return null;
    }

    /** @return array{name: string, target: string, target_type: string, check_method: string, check_interval_seconds: int, timeout_seconds: int, failure_threshold: int, active: int} */
    public static function validateMonitor(array $input, string $failPath): array
    {
        $v = self::normalizeMonitor($input);
        $error = self::checkMonitor($v);
        if ($error !== null) {
            flash_set('danger', $error);
            redirect($failPath);
        }
        return $v;
    }
}
