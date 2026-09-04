<?php
declare(strict_types=1);

use App\Services\NotificationService;

function sanitize_email_header(string $value): string
{
    return NotificationService::sanitize_email_header($value);
}

function notify_email(string $subject, string $message, array $settings): bool
{
    return NotificationService::notify_email($subject, $message, $settings);
}

function smtp_read(mixed $socket): string
{
    return NotificationService::smtp_read($socket);
}

function smtp_expect(mixed $socket, array $expectedCodes): bool
{
    return NotificationService::smtp_expect($socket, $expectedCodes);
}

function smtp_send_cmd(mixed $socket, string $cmd, array $expectedCodes): bool
{
    return NotificationService::smtp_send_cmd($socket, $cmd, $expectedCodes);
}

function smtp_send_mail(string $to, string $subject, string $message, string $fromEmail, string $fromName, array $settings): bool
{
    return NotificationService::smtp_send_mail($to, $subject, $message, $fromEmail, $fromName, $settings);
}

function notify_telegram(string $message, array $settings): bool
{
    return NotificationService::notify_telegram($message, $settings);
}
