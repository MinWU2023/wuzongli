<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    /** Session cookie lifetime: 14 days. Each device keeps its own cookie/row. */
    private const LIFETIME = 1209600;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        self::configureHandler();

        // Prefer '/' so cookies survive host rewrite quirks. Subdirectory installs
        // still get APP_BASE_PATH + trailing slash.
        $cookiePath = '/';
        if (defined('APP_BASE_PATH') && APP_BASE_PATH !== '') {
            $cookiePath = rtrim((string) APP_BASE_PATH, '/') . '/';
        }

        $secure = self::isHttps();
        session_name('gle_session');
        session_set_cookie_params([
            'lifetime' => self::LIFETIME,
            'path' => $cookiePath,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_lifetime', (string) self::LIFETIME);
        ini_set('session.gc_maxlifetime', (string) self::LIFETIME);
        session_start();
    }

    /**
     * Prefer MySQL sessions when DB is ready; fall back to files.
     * File sessions on free hosts are a common source of permanent 419 on login.
     */
    private static function configureHandler(): void
    {
        try {
            Db::instance();
            $handler = new DatabaseSessionHandler(self::LIFETIME);
            session_set_save_handler($handler, true);
            return;
        } catch (\Throwable) {
            // Database not ready (install) — use files.
        }
        self::ensureSavePath();
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

        // Free hosts often strip HTTPS indicators. Trust app.url only for
        // non-local hosts — forcing Secure on http://localhost drops the cookie
        // and every POST becomes CSRF 419.
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $hostOnly = explode(':', $host)[0];
        $isLocal = $hostOnly === 'localhost'
            || $hostOnly === '127.0.0.1'
            || str_ends_with($hostOnly, '.local')
            || str_ends_with($hostOnly, '.test');
        if (!$isLocal) {
            $appUrl = (string) (function_exists('config') ? config('app.url', '') : '');
            if ($appUrl !== '' && str_starts_with(strtolower($appUrl), 'https://')) {
                return true;
            }
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
            // Only rotates THIS device's session id. Other devices keep theirs.
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
