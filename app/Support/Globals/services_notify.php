<?php
declare(strict_types=1);

use App\Services\NotificationService;

function notify_email(string $subject, string $message, array $settings): bool
{
    return NotificationService::notify_email($subject, $message, $settings);
}

function notify_telegram(string $message, array $settings): bool
{
    return NotificationService::notify_telegram($message, $settings);
}
