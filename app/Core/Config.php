<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Small, dot-notation configuration repository.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    /** @param array<string, mixed> $items */
    public static function load(array $items): void
    {
        self::$items = $items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return self::$items;
        }

        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor = &self::$items;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $cursor[$segment] = $value;
                return;
            }
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__missing__') !== '__missing__';
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return self::$items;
    }
}
