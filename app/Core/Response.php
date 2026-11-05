<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Stateless HTTP response helpers. All terminal helpers exit the request.
 */
final class Response
{
    /** @param mixed $data */
    public static function json(mixed $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($data, $flags);
        echo $json === false ? '{"ok":false,"error":{"message":"JSON encode failed"}}' : $json;
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $html;
        exit;
    }

    public static function redirect(string $url, int $status = 302): never
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);
        }
        exit;
    }

    public static function download(string $filename, string $content, string $contentType = 'text/csv; charset=utf-8'): never
    {
        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: no-store');
        }
        echo $content;
        exit;
    }

    public static function noContent(): never
    {
        if (!headers_sent()) {
            http_response_code(204);
        }
        exit;
    }
}
