<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        Session::start();
        $token = $_SESSION['_csrf_token'] ?? null;
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf_token'] = $token;
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $token): bool
    {
        Session::start();
        $expected = $_SESSION['_csrf_token'] ?? '';
        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    public static function assert(?string $token): void
    {
        if (!self::verify($token)) {
            throw new HttpException(419, '页面已过期，请刷新后重试', 'csrf_mismatch');
        }
    }
}
