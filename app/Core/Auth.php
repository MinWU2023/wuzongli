<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    /** @var array<string, mixed>|null */
    private static ?array $cachedUser = null;

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        $id = (int) Session::get('admin_id', 0);
        if ($id <= 0) {
            return null;
        }
        $user = Db::instance()->table('admins')->where('id', $id)->first();
        if ($user === null) {
            Session::forget('admin_id');
            return null;
        }
        unset($user['password_hash']);
        self::$cachedUser = $user;
        return $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function attempt(string $username, string $password): bool
    {
        $user = Db::instance()->table('admins')->where('username', $username)->first();
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }
        // Rotate only this device's session. Other devices keep independent rows.
        Session::regenerate();
        Session::put('admin_id', (int) $user['id']);
        Session::forget('login_attempts');
        self::$cachedUser = null;
        Db::instance()->update('admins', ['last_login_at' => now()], ['id' => (int) $user['id']]);
        return true;
    }

    public static function logout(): void
    {
        self::$cachedUser = null;
        Session::forget('admin_id');
        Session::regenerate();
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            Response::redirect(url('/admin/login'));
        }
    }
}
