<?php
declare(strict_types=1);

namespace App\Support;

final class Logger
{
    public const DEBUG = 'DEBUG';
    public const INFO = 'INFO';
    public const WARN = 'WARN';
    public const ERROR = 'ERROR';

    public static function dir(): string
    {
        $dir = MONITORS_BASE_DIR . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true)) {
                error_log('monitors: failed to create log dir: ' . $dir);
            }
        }
        return $dir;
    }

    public static function log(string $level, string $message, string $context = 'app', array $extra = []): void
    {
        $validLevels = ['DEBUG', 'INFO', 'WARN', 'ERROR'];
        $level = strtoupper($level);
        if (!in_array($level, $validLevels, true)) {
            $level = 'INFO';
        }

        if ($level === 'DEBUG' && APP_ENV === 'production') {
            return;
        }

        $date = date('Y-m-d');
        $datetime = date('Y-m-d H:i:s');
        $logFile = self::dir() . DIRECTORY_SEPARATOR . 'monitors-' . $date . '.log';

        $line = sprintf('[%s] [%s] [%s] %s', $datetime, $level, $context, $message);

        if (!empty($extra)) {
            $line .= ' ' . json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $written = @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log('monitors: failed to write log: ' . $logFile);
        }
    }

    public static function info(string $message, string $context = 'app', array $extra = []): void
    {
        self::log(self::INFO, $message, $context, $extra);
    }

    public static function warn(string $message, string $context = 'app', array $extra = []): void
    {
        self::log(self::WARN, $message, $context, $extra);
    }

    public static function error(string $message, string $context = 'app', array $extra = []): void
    {
        self::log(self::ERROR, $message, $context, $extra);
    }
}
