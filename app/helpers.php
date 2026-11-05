<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Session;
use App\Core\Str;

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return Config::all();
        }
        return Config::get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return BASE_PATH . ($path === '' ? '' : '/' . ltrim($path, '/\\'));
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return STORAGE_PATH . ($path === '' ? '' : '/' . ltrim($path, '/\\'));
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if ($path === '') {
            return APP_BASE_PATH === '' ? '/' : APP_BASE_PATH . '/';
        }
        return (APP_BASE_PATH === '' ? '' : APP_BASE_PATH) . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return url($path) . '?v=' . rawurlencode(defined('APP_VERSION') ? APP_VERSION : '1');
    }
}

if (!function_exists('now')) {
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('db')) {
    function db(): Db
    {
        return Db::instance();
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        try {
            return \App\Services\Settings::get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}

if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }
}

if (!function_exists('flashes')) {
    /** @return array<int, array{type:string,message:string}> */
    function flashes(): array
    {
        return Session::flashes();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return Session::get('_old.' . $key, $default);
    }
}

if (!function_exists('format_dt')) {
    function format_dt(?string $value, string $format = 'Y-m-d H:i'): string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return '—';
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? e($value) : date($format, $timestamp);
    }
}

if (!function_exists('truncate_text')) {
    function truncate_text(?string $value, int $limit = 100): string
    {
        return Str::limit($value, $limit);
    }
}

if (!function_exists('format_number')) {
    function format_number(mixed $value): string
    {
        return number_format((float) $value);
    }
}

if (!function_exists('stage_meta')) {
    /**
     * @return array<string, array{label:string,short:string,index:int,icon:string}>
     */
    function stage_meta(): array
    {
        return [
            'keywords' => ['label' => 'AI 生成关键词', 'short' => '关键词', 'index' => 0, 'icon' => 'spark'],
            'serp' => ['label' => '搜索结果', 'short' => '搜索', 'index' => 1, 'icon' => 'search'],
            'classify' => ['label' => 'AI 企业筛选', 'short' => '筛选', 'index' => 2, 'icon' => 'filter'],
            'discover' => ['label' => '识别官网页面', 'short' => '页面', 'index' => 3, 'icon' => 'layout'],
            'extract' => ['label' => '提取联系方式', 'short' => '联系', 'index' => 4, 'icon' => 'phone'],
            'done' => ['label' => '任务完成', 'short' => '完成', 'index' => 5, 'icon' => 'check'],
        ];
    }
}

if (!function_exists('stage_label')) {
    function stage_label(string $stage): string
    {
        return stage_meta()[$stage]['label'] ?? $stage;
    }
}

if (!function_exists('task_status_label')) {
    function task_status_label(string $status): string
    {
        return match ($status) {
            'active' => '运行中',
            'paused' => '已暂停',
            'completed' => '已完成',
            'failed' => '异常',
            default => $status,
        };
    }
}

if (!function_exists('host_of')) {
    function host_of(?string $url): string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }
}

if (!function_exists('short_url')) {
    function short_url(?string $url, int $limit = 52): string
    {
        $url = preg_replace('#^https?://#i', '', (string) $url) ?? '';
        return Str::limit($url, $limit);
    }
}

if (!function_exists('csv_safe')) {
    /**
     * Neutralise spreadsheet formulas while keeping the original text readable.
     * Only free-text columns are passed through this helper; URLs and phone
     * numbers are left untouched.
     */
    function csv_safe(mixed $value): string
    {
        $value = (string) $value;
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'" . $value;
        }
        return $value;
    }
}

if (!function_exists('mask_secret')) {
    function mask_secret(?string $secret): string
    {
        return \App\Core\Crypto::mask($secret);
    }
}

if (!function_exists('absolute_url')) {
    function absolute_url(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $configured = (string) config('app.url', '');
        if ($configured !== '') {
            return rtrim($configured, '/') . '/' . ltrim($path, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . url($path);
    }
}
