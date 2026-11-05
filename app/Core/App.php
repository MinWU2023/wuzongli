<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Application kernel: boot, install guard, CORS, routing and error rendering.
 */
final class App
{
    private ?Request $request = null;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        Config::load($config);
        date_default_timezone_set((string) Config::get('app.timezone', 'Asia/Shanghai'));
        $debug = (bool) Config::get('app.debug', false);
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
    }

    public function isInstalled(): bool
    {
        return is_file(STORAGE_PATH . '/installed.lock') && (string) config('app.key', '') !== '';
    }

    public function run(): void
    {
        try {
            $this->request = new Request();
            $this->sendSecurityHeaders();
            $this->handleCors($this->request);

            if (!$this->isInstalled()) {
                if ($this->request->isApi()) {
                    Response::json([
                        'ok' => false,
                        'error' => [
                            'code' => 'not_installed',
                            'message' => '系统尚未安装，请先访问 install.php 完成安装。',
                            'install_url' => absolute_url('install.php'),
                        ],
                    ], 503);
                }
                Response::redirect(url('/install.php'));
            }

            Db::init((array) config('db', []));

            if (!$this->request->isApi()) {
                Session::start();
            }

            if ($this->request->path() === '/') {
                Response::redirect(url('/admin'));
            }

            $router = new Router();
            require APP_PATH . '/routes.php';
            $result = $router->dispatch($this->request);

            if (is_array($result) || is_object($result)) {
                Response::json($result);
            }
            if (is_string($result)) {
                echo $result;
            }
        } catch (\Throwable $e) {
            $this->handleException($e, $this->request);
        }
    }

    private function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-XSS-Protection: 0');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    private function handleCors(Request $request): void
    {
        if (!$request->isApi()) {
            return;
        }
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        if ($request->method() === 'OPTIONS') {
            Response::noContent();
        }
    }

    public function handleException(\Throwable $e, ?Request $request = null): void
    {
        $isHttp = $e instanceof HttpException;
        $status = $isHttp ? $e->status : 500;
        $code = $isHttp ? $e->errorCode : 'server_error';
        $message = $isHttp ? $e->getMessage() : '服务器开小差了，请稍后再试';
        $details = $isHttp ? $e->details : null;
        $wantsJson = $request?->wantsJson()
            ?? $request?->isApi()
            ?? (str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/'));

        // API clients need a usable reason; free hosts often kill long AI calls with a fatal timeout.
        if (!$isHttp && $wantsJson) {
            $raw = trim($e->getMessage());
            if ($raw !== '') {
                if (preg_match('/maximum execution time|max_execution_time|timed out|timeout/i', $raw)) {
                    $message = '服务器执行超时（免费主机常见 60 秒限制）。请减少关键词数量、换更快模型，或缩短 AI 中转站超时。';
                    $code = 'execution_timeout';
                } else {
                    $message = '服务器错误：' . (function_exists('mb_substr') ? mb_substr($raw, 0, 280) : substr($raw, 0, 280));
                }
            }
        }

        Logger::error($e->getMessage(), [
            'type' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'path' => $request?->path() ?? ($_SERVER['REQUEST_URI'] ?? ''),
            'method' => $request?->method() ?? ($_SERVER['REQUEST_METHOD'] ?? ''),
            'ip' => $request?->ip() ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);

        if ((bool) config('app.debug', false) && !$isHttp) {
            $details = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
            ];
        }

        if ($wantsJson) {
            Response::json([
                'ok' => false,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'details' => $details,
                ],
            ], $status);
        }

        try {
            $html = View::render('errors/error', [
                'status' => $status,
                'message' => $message,
                'code' => $code,
                'details' => $details,
            ]);
            Response::html($html, $status);
        } catch (\Throwable) {
            Response::html('<!doctype html><meta charset="utf-8"><title>' . $status . '</title><h1>' . e($status) . '</h1><p>' . e($message) . '</p>', $status);
        }
    }
}
