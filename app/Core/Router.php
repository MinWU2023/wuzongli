<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Explicit route table with parameters, module controllers and method dispatch.
 *
 * Handler strings use the form "Module/Controller@method", for example
 * "Api/Task@next" maps to App\Modules\Api\Controllers\TaskController::next().
 */
final class Router
{
    /** @var array<int, array{method:string,path:string,regex:string,keys:array<int,string>,handler:mixed}> */
    private array $routes = [];

    public function get(string $path, mixed $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): self
    {
        return $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, mixed $handler): self
    {
        return $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, mixed $handler): self
    {
        return $this->add('DELETE', $path, $handler);
    }

    public function add(string $method, string $path, mixed $handler): self
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/';
        }
        [$regex, $keys] = $this->compile($path);
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'regex' => $regex,
            'keys' => $keys,
            'handler' => $handler,
        ];
        return $this;
    }

    /** @return array{0:string,1:array<int,string>} */
    private function compile(string $path): array
    {
        $tokens = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_]*(?::[^}]+)?\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $regex = '';
        $keys = [];
        foreach ($tokens ?: [] as $token) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}$/', $token, $m)) {
                $keys[] = $m[1];
                $constraint = $m[2] ?? '[^/]+';
                $regex .= '(?P<' . $m[1] . '>' . $constraint . ')';
            } else {
                $regex .= preg_quote($token, '#');
            }
        }
        return ['#^' . $regex . '$#', $keys];
    }

    public function dispatch(Request $request): mixed
    {
        $pathMatched = false;
        $method = $request->method();
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;

        foreach ($this->routes as $route) {
            $matches = [];
            if (!preg_match($route['regex'], $request->path(), $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $lookupMethod) {
                continue;
            }
            $params = [];
            foreach ($route['keys'] as $key) {
                $params[$key] = isset($matches[$key]) ? rawurldecode($matches[$key]) : null;
            }
            return $this->run($route['handler'], $request, $params);
        }

        throw new HttpException(
            $pathMatched ? 405 : 404,
            $pathMatched ? '该地址不支持当前请求方式' : '页面不存在',
            $pathMatched ? 'method_not_allowed' : 'not_found'
        );
    }

    /** @param array<string, string|null> $params */
    private function run(mixed $handler, Request $request, array $params): mixed
    {
        if (is_callable($handler)) {
            return $handler(...array_values($params));
        }

        if (!is_string($handler) || !str_contains($handler, '@')) {
            throw new \RuntimeException('Invalid route handler definition.');
        }

        [$controllerRef, $method] = explode('@', $handler, 2);
        if (!str_contains($controllerRef, '/')) {
            throw new \RuntimeException('Route controller must include a module name.');
        }
        [$module, $controller] = explode('/', $controllerRef, 2);
        $module = ucfirst(preg_replace('/[^a-zA-Z0-9_]/', '', $module) ?? '');
        $controller = ucfirst(preg_replace('/[^a-zA-Z0-9_]/', '', $controller) ?? '');
        $method = preg_replace('/[^a-zA-Z0-9_]/', '', $method) ?? '';

        $class = "App\\Modules\\{$module}\\Controllers\\{$controller}Controller";
        if (!class_exists($class)) {
            throw new HttpException(404, '控制器不存在', 'controller_not_found');
        }
        $instance = new $class($request);
        if (!method_exists($instance, $method)) {
            throw new HttpException(404, '方法不存在', 'method_not_found');
        }
        return $instance->{$method}(...array_values($params));
    }
}
