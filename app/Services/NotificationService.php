<?php
declare(strict_types=1);

namespace App\Services;

final class NotificationService
{
    public static function sanitize_email_header(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    public static function notify_email(string $subject, string $message, array $settings): bool
    {
        if (($settings['channel_email_enabled'] ?? '0') !== '1') {
            return false;
        }
        $to = trim((string) ($settings['smtp_to_email'] ?? ''));
        if ($to === '') {
            return false;
        }

        $fromEmail = trim((string) ($settings['smtp_from_email'] ?? ''));
        $fromName = trim((string) ($settings['smtp_from_name'] ?? 'monitors'));
        $smtpHost = trim((string) ($settings['smtp_host'] ?? ''));

        if ($smtpHost !== '') {
            return self::smtp_send_mail($to, $subject, $message, $fromEmail !== '' ? $fromEmail : 'noreply@localhost', $fromName, $settings);
        }

        $headers = "MIME-Version: 1.0\r\nContent-type: text/plain; charset=UTF-8\r\n";
        if ($fromEmail !== '') {
            $safeName = self::sanitize_email_header($fromName);
            $safeEmail = self::sanitize_email_header($fromEmail);
            $headers .= 'From: ' . $safeName . ' <' . $safeEmail . ">\r\n";
        }

        $safeTo = self::sanitize_email_header($to);
        $safeSubject = self::sanitize_email_header($subject);

        $ok = @mail($safeTo, $safeSubject, $message, $headers);
        if (!$ok) {
            monitors_log_error('mail() failed for subject: ' . $subject, 'email', ['to' => $to]);
        }
        return $ok;
    }

    public static function smtp_read(mixed $socket): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (preg_match('/^\d{3}\s/', $line) === 1) {
                break;
            }
        }
        return $response;
    }

    public static function smtp_expect(mixed $socket, array $expectedCodes): bool
    {
        $resp = self::smtp_read($socket);
        if ($resp === '') {
            return false;
        }
        $code = (int) substr($resp, 0, 3);
        return in_array($code, $expectedCodes, true);
    }

    public static function smtp_send_cmd(mixed $socket, string $cmd, array $expectedCodes): bool
    {
        fwrite($socket, $cmd . "\r\n");
        return self::smtp_expect($socket, $expectedCodes);
    }

    public static function smtp_send_mail(string $to, string $subject, string $message, string $fromEmail, string $fromName, array $settings): bool
    {
        $host = (string) ($settings['smtp_host'] ?? '');
        $port = (int) ($settings['smtp_port'] ?? 587);
        $secure = strtolower((string) ($settings['smtp_secure'] ?? 'tls'));
        $username = (string) ($settings['smtp_username'] ?? '');
        $password = (string) ($settings['smtp_password'] ?? '');

        $transportHost = ($secure === 'ssl') ? "ssl://{$host}" : $host;
        $socket = @stream_socket_client($transportHost . ':' . $port, $errno, $errstr, 3);
        if (!$socket) {
            monitors_log_error('SMTP connect failed: ' . $errstr, 'email', ['host' => $host, 'port' => $port, 'errno' => $errno]);
            return false;
        }

        stream_set_timeout($socket, 3);
        if (!self::smtp_expect($socket, [220])) {
            monitors_log_error('SMTP greeting failed', 'email', ['host' => $host]);
            fclose($socket);
            return false;
        }

        if (!self::smtp_send_cmd($socket, 'EHLO monitors', [250])) {
            monitors_log_error('SMTP EHLO failed', 'email', ['host' => $host]);
            fclose($socket);
            return false;
        }

        if ($secure === 'tls') {
            if (!self::smtp_send_cmd($socket, 'STARTTLS', [220])) {
                monitors_log_error('SMTP STARTTLS failed', 'email', ['host' => $host]);
                fclose($socket);
                return false;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                monitors_log_error('SMTP TLS crypto upgrade failed', 'email', ['host' => $host]);
                fclose($socket);
                return false;
            }
            if (!self::smtp_send_cmd($socket, 'EHLO monitors', [250])) {
                monitors_log_error('SMTP EHLO after STARTTLS failed', 'email', ['host' => $host]);
                fclose($socket);
                return false;
            }
        }

        if ($username !== '') {
            if (!self::smtp_send_cmd($socket, 'AUTH LOGIN', [334])) {
                monitors_log_error('SMTP AUTH LOGIN failed', 'email', ['host' => $host, 'username' => $username]);
                fclose($socket);
                return false;
            }
            if (!self::smtp_send_cmd($socket, base64_encode($username), [334])) {
                monitors_log_error('SMTP AUTH username rejected', 'email', ['host' => $host]);
                fclose($socket);
                return false;
            }
            if (!self::smtp_send_cmd($socket, base64_encode($password), [235])) {
                monitors_log_error('SMTP AUTH password rejected', 'email', ['host' => $host]);
                fclose($socket);
                return false;
            }
        }

        $safeFromName = self::sanitize_email_header($fromName);
        $safeFromEmail = self::sanitize_email_header($fromEmail);
        $safeTo = self::sanitize_email_header($to);
        $safeSubject = self::sanitize_email_header($subject);

        if (!self::smtp_send_cmd($socket, 'MAIL FROM:<' . $safeFromEmail . '>', [250])) {
            monitors_log_error('SMTP MAIL FROM rejected', 'email', ['from' => $safeFromEmail]);
            fclose($socket);
            return false;
        }
        if (!self::smtp_send_cmd($socket, 'RCPT TO:<' . $safeTo . '>', [250, 251])) {
            monitors_log_error('SMTP RCPT TO rejected', 'email', ['to' => $safeTo]);
            fclose($socket);
            return false;
        }
        if (!self::smtp_send_cmd($socket, 'DATA', [354])) {
            monitors_log_error('SMTP DATA command rejected', 'email');
            fclose($socket);
            return false;
        }

        $data = "From: {$safeFromName} <{$safeFromEmail}>\r\n"
            . "To: <{$safeTo}>\r\n"
            . "Subject: {$safeSubject}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $message . "\r\n.";
        if (!self::smtp_send_cmd($socket, $data, [250])) {
            monitors_log_error('SMTP DATA send failed', 'email', ['subject' => $subject]);
            fclose($socket);
            return false;
        }

        self::smtp_send_cmd($socket, 'QUIT', [221]);
        fclose($socket);

        monitors_log_info('Email sent successfully', 'email', ['to' => $to, 'subject' => $subject]);
        return true;
    }

    public static function notify_telegram(string $message, array $settings): bool
    {
        if (($settings['channel_telegram_enabled'] ?? '0') !== '1') {
            return false;
        }
        $token = trim((string) ($settings['telegram_bot_token'] ?? ''));
        $chatId = trim((string) ($settings['telegram_chat_id'] ?? ''));
        if ($token === '' || $chatId === '') {
            return false;
        }
        if (!function_exists('curl_init')) {
            monitors_log_error('curl extension not available for Telegram', 'telegram');
            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        $threadId = trim((string) ($settings['telegram_thread_id'] ?? ''));
        if ($threadId !== '') {
            $payload['message_thread_id'] = $threadId;
        }

        $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 10,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($res === false || $code < 200 || $code >= 300) {
            monitors_log_error('Telegram send failed', 'telegram', [
                'http_code' => $code,
                'curl_error' => $curlError,
                'chat_id' => $chatId,
            ]);
            return false;
        }

        monitors_log_info('Telegram message sent', 'telegram', ['chat_id' => $chatId]);
        return true;
    }
}
