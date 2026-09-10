<?php
declare(strict_types=1);

namespace App\Services\Validation;

/**
 * Shared validation for the server add/edit forms.
 *
 * Failure handling follows the codebase idiom: validate*() flashes the
 * message and redirects (which exits), so controllers stay thin. The
 * check*() variants are pure and return the message (or null) for tests.
 */
final class ServerValidator
{
    public const NAME_MAX = 100;
    public const LOCATION_MAX = 100;
    public const HOST_MAX = 100;
    public const TYPE_MAX = 50;
    public const PROVIDER_MAX = 100;
    public const LABEL_MAX = 100;
    public const EMAIL_MAX = 255;

    /**
     * @return array{name: string, location: string, host: string, type: string, provider: string, label: string}
     */
    public static function normalizeIdentity(array $input): array
    {
        return [
            'name' => trim((string) ($input['name'] ?? '')),
            'location' => trim((string) ($input['location'] ?? '')),
            'host' => trim((string) ($input['host'] ?? '')),
            'type' => trim((string) ($input['type'] ?? '')),
            'provider' => trim((string) ($input['provider'] ?? '')),
            'label' => trim((string) ($input['label'] ?? '')),
        ];
    }

    /**
     * @param array{name: string, location: string, host: string, type: string, provider: string, label: string} $v
     */
    public static function checkIdentity(array $v): ?string
    {
        if (($v['name'] ?? '') === '') {
            return 'Server name is required.';
        }
        $limits = [
            'name' => ['Server name', self::NAME_MAX],
            'location' => ['Location', self::LOCATION_MAX],
            'host' => ['Host', self::HOST_MAX],
            'type' => ['Type', self::TYPE_MAX],
            'provider' => ['Provider', self::PROVIDER_MAX],
            'label' => ['Label', self::LABEL_MAX],
        ];
        foreach ($limits as $field => [$label, $max]) {
            if (mb_strlen((string) ($v[$field] ?? '')) > $max) {
                return $label . ' must not exceed ' . $max . ' characters.';
            }
        }
        return null;
    }

    /**
     * @return array{name: string, location: string, host: string, type: string, provider: string, label: string}
     */
    public static function validateIdentity(array $input, string $failPath): array
    {
        $v = self::normalizeIdentity($input);
        $error = self::checkIdentity($v);
        if ($error !== null) {
            flash_set('danger', $error);
            redirect($failPath);
        }
        return $v;
    }

    public static function checkContact(string $notifyEmail, string $pushAllowedIps): ?string
    {
        if ($notifyEmail !== '') {
            if (mb_strlen($notifyEmail) > self::EMAIL_MAX) {
                return 'Notify email must not exceed 255 characters.';
            }
            if (filter_var($notifyEmail, FILTER_VALIDATE_EMAIL) === false) {
                return 'Notify email is not a valid email address.';
            }
        }
        $v = \App\Services\Security\PushAllowlistValidator::validate($pushAllowedIps);
        if (!$v['valid']) {
            return (string) ($v['error'] ?? 'Invalid push IP allowlist.');
        }
        return null;
    }

    /** @return array{notify_email: string, push_allowed_ips: string} */
    public static function validateContact(string $notifyEmail, string $pushAllowedIps, string $failPath): array
    {
        $error = self::checkContact($notifyEmail, $pushAllowedIps);
        if ($error !== null) {
            flash_set('danger', $error);
            redirect($failPath);
        }
        $v = \App\Services\Security\PushAllowlistValidator::validate($pushAllowedIps);
        return [
            'notify_email' => $notifyEmail,
            'push_allowed_ips' => (string) ($v['normalized'] ?? ''),
        ];
    }
}
