<?php
declare(strict_types=1);

/**
 * Optional router for PHP's built-in development server.
 *
 * Usage:
 *   php -S 127.0.0.1:8080 dev-server.php
 *
 * Apache/Nginx should use the normal rewrite rules instead. This file refuses
 * to run under FPM/Apache so it cannot become an accidental public endpoint.
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$root = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/(?:app|config|storage|migrations)(?:/|$)#', $path)) {
    http_response_code(404);
    exit;
}
// Block dotfiles such as .env, but never files whose extension merely starts
// with a dot-like segment (for example /export.csv).
foreach (explode('/', trim($path, '/')) as $segment) {
    if ($segment !== '' && $segment[0] === '.') {
        http_response_code(404);
        exit;
    }
}
$file = realpath($root . $path);
if ($path !== '/' && $file !== false && str_starts_with($file, $root) && is_file($file)) {
    return false;
}

require $root . '/index.php';
