<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    /** @param array<string, mixed> $context */
    public static function log(string $level, string $message, array $context = []): void
    {
        $directory = STORAGE_PATH . '/logs';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $line = json_encode([
            'time' => date('Y-m-d H:i:s'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => self::sanitize($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        @file_put_contents(
            $directory . '/app-' . date('Y-m-d') . '.log',
            ($line === false ? $message : $line) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private static function sanitize(array $context): array
    {
        $hidden = ['password', 'password_hash', 'api_key', 'authorization', 'token', 'secret'];
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $hidden, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::sanitize($value);
            } elseif (is_string($value) && strlen($value) > 4000) {
                $context[$key] = substr($value, 0, 4000) . '…';
            }
        }
        return $context;
    }
}
