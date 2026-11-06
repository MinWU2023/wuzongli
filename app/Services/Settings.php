<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Db;

/**
 * Runtime settings stored in MySQL so they can be changed without editing PHP.
 */
final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = Db::instance()->table('settings')->get();
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        self::$cache = $settings;
        return $settings;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Read a value that may have been stored with Crypto::encrypt().
     * Plain values are returned unchanged so existing installations keep
     * working after the secret-storage upgrade.
     */
    public static function secret(string $key, string $default = ''): string
    {
        $value = (string) self::get($key, $default);
        if ($value === '') {
            return $default;
        }
        try {
            return Crypto::decrypt($value);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function set(string $key, scalar|null $value): void
    {
        self::setMany([$key => $value]);
    }

    /** @param array<string, scalar|null> $values */
    public static function setMany(array $values): void
    {
        $db = Db::instance();
        foreach ($values as $key => $value) {
            if (!preg_match('/^[a-z0-9_]{1,80}$/', (string) $key)) {
                continue;
            }
            $db->upsert('settings', [
                'setting_key' => (string) $key,
                'setting_value' => $value === null ? '' : (string) $value,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['setting_value', 'updated_at']);
        }
        self::$cache = null;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'keyword_count' => '24',
            'serp_pages' => '0',
            'classify_batch' => '30',
            'max_attempts' => '3',
            'google_domain' => 'www.google.com',
            'request_delay_ms' => '2500',
            'ai_json_mode' => '1',
            'search_provider' => 'auto',
            'serper_api_key' => 'a4be645a029169206f66e8024a73361f005fc5ef',
            'serper_base_url' => 'https://google.serper.dev/search',
            'serper_gl' => 'us',
            'serper_hl' => 'en',
            'serper_timeout' => '30',
            // App 控制：插件是否自动排队跑任务（1=开启排队，0=即时停止）
            'extension_runner_enabled' => '1',
        ];
    }
}
