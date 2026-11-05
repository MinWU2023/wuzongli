<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        self::ensureSavePath();

        // Cookie path must be '/' (or end with '/'). Using APP_BASE_PATH without a
        // trailing slash breaks cookies on many hosts and causes CSRF_MISMATCH on every POST.
        $cookiePath = '/';
        if (defined('APP_BASE_PATH') && APP_BASE_PATH !== '') {
            $cookiePath = rtrim((string) APP_BASE_PATH, '/') . '/';
        }

        $secure = self::isHttps();
        session_name('gle_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    private static function ensureSavePath(): void
    {
        $dir = STORAGE_PATH . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwarded === 'https' || str_starts_with($forwarded, 'https,')) {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        // Prefer app.url when proxy headers are missing (common on free hosts).
        $appUrl = (string) (function_exists('config') ? config('app.url', '') : '');
        if ($appUrl !== '' && str_starts_with(strtolower($appUrl), 'https://')) {
            return true;
        }
        return false;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);
        return $value;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    /** Add a flash message consumed by the next rendered page. */
    public static function flash(string $type, string $message): void
    {
        $flashes = $_SESSION['_flash'] ?? [];
        $flashes[] = ['type' => $type, 'message' => $message];
        $_SESSION['_flash'] = $flashes;
    }

    /** @return array<int, array{type:string,message:string}> */
    public static function flashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($flashes) ? $flashes : [];
    }
}
