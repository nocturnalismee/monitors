<?php
declare(strict_types=1);

namespace App\Services;

final class TurnstileService
{
    public static function turnstile_is_configured(): bool
    {
        return TURNSTILE_SITE_KEY !== '' && TURNSTILE_SECRET_KEY !== '';
    }

    public static function turnstile_site_key(): string
    {
        return TURNSTILE_SITE_KEY;
    }

    public static function turnstile_validate(string $token, string $remoteIp = ''): bool
    {
        if (TURNSTILE_SECRET_KEY === '' || trim($token) === '') {
            return false;
        }

        $payload = [
            'secret' => TURNSTILE_SECRET_KEY,
            'response' => $token,
        ];
        if ($remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        $body = http_build_query($payload, '', '&');
        $responseBody = false;

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl === false) {
                return false;
            }

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
            ]);
            $responseBody = curl_exec($curl);
            curl_close($curl);
        } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $body,
                    'timeout' => 5,
                    'ignore_errors' => true,
                ],
            ]);
            $responseBody = @file_get_contents($url, false, $context);
        }

        if (!is_string($responseBody) || $responseBody === '') {
            error_log('Turnstile validation failed: no response from Cloudflare.');
            return false;
        }

        $result = json_decode($responseBody, true);
        if (!is_array($result)) {
            error_log('Turnstile validation failed: invalid response from Cloudflare.');
            return false;
        }

        return ($result['success'] ?? false) === true;
    }
}
