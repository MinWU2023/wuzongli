<?php
declare(strict_types=1);

namespace App\Core;

/**
 * AES-256-GCM encryption for relay API keys stored in MySQL.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('Unable to encrypt the API key.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $payload): string
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            return $payload;
        }
        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    public static function mask(?string $secret): string
    {
        $secret = (string) $secret;
        if ($secret === '') {
            return '';
        }
        if (strlen($secret) <= 10) {
            return str_repeat('•', max(4, strlen($secret)));
        }
        return substr($secret, 0, 5) . str_repeat('•', 8) . substr($secret, -4);
    }

    private static function key(): string
    {
        $appKey = (string) config('app.key', '');
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is not configured.');
        }
        return hash('sha256', $appKey, true);
    }
}
