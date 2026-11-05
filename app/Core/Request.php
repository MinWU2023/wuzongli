<?php
declare(strict_types=1);

namespace App\Core;

/**
 * HTTP request wrapper with JSON / form body support.
 */
final class Request
{
    private string $method;
    private string $path;
    /** @var array<string, mixed> */
    private array $query;
    /** @var array<string, mixed> */
    private array $body;
    /** @var array<string, string> */
    private array $headers;
    private ?string $rawBody = null;

    public function __construct()
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Allow forms / clients to tunnel PATCH/DELETE where needed.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }
        $this->method = $method;
        $this->path = $this->resolvePath();
        $this->query = $_GET;
        $this->headers = $this->collectHeaders();
        $this->body = $this->parseBody();
    }

    private function resolvePath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    /** @return array<string, mixed> */
    private function parseBody(): array
    {
        $contentType = strtolower($this->header('Content-Type') ?? '');
        if (str_contains($contentType, 'application/json')) {
            $raw = $this->rawBody();
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new HttpException(400, '请求 JSON 格式不正确', 'invalid_json');
            }
            return $decoded;
        }
        if (str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return $_POST;
        }
        // Be forgiving for clients that omit the content type.
        $raw = $this->rawBody();
        if ($raw !== '' && in_array($raw[0], ['{', '['], true)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST;
    }

    public function rawBody(): string
    {
        if ($this->rawBody === null) {
            $raw = file_get_contents('php://input');
            $this->rawBody = $raw === false ? '' : $raw;
        }
        return $this->rawBody;
    }

    /** @return array<string, string> */
    private function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = (string) $value;
            }
        }
        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/') || $this->path === '/api';
    }

    public function wantsJson(): bool
    {
        if ($this->isApi()) {
            return true;
        }
        $accept = strtolower($this->header('Accept') ?? '');
        return str_contains($accept, 'application/json') || $this->isAjax();
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With') ?? '') === 'xmlhttprequest';
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $value = $this->body;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        if (is_array($value) || is_object($value)) {
            return $default;
        }
        return trim((string) $value);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    }
}
