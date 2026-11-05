<?php
declare(strict_types=1);

namespace App\Core;

final class Str
{
    public static function limit(?string $value, int $limit = 120, string $end = '…'): string
    {
        $value = trim((string) $value);
        if ($limit <= 0 || mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }
        return mb_substr($value, 0, $limit, 'UTF-8') . $end;
    }

    public static function random(int $bytes = 24): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    public static function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
