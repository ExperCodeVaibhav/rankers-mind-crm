<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\PermissionMiddleware;

final class Router
{
    private array $routes = [];

    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, mixed $handler, array $middleware): void
    {
        $this->routes[] = compact('method', 'path', 'handler', 'middleware');
    }

    public function dispatch(string $method, string $uri): void
    {
        $requestPath = '/' . trim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($requestPath, $scriptDir)) {
            $requestPath = substr($requestPath, strlen($scriptDir)) ?: '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }
            $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $route['path']);
            if (!preg_match('#^' . $pattern . '/?$#', $requestPath, $matches)) {
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            foreach ($route['middleware'] as $middleware) {
                $this->runMiddleware($middleware);
            }
            $this->invoke($route['handler'], $params);
            return;
        }

        http_response_code(404);
        view('errors/404', ['title' => 'Page not found']);
    }

    private function runMiddleware(string $middleware): void
    {
        [$name, $argument] = array_pad(explode(':', $middleware, 2), 2, null);
        $instance = match ($name) {
            'auth' => new AuthMiddleware(),
            'guest' => new GuestMiddleware(),
            'csrf' => new CsrfMiddleware(),
            'permission' => new PermissionMiddleware(),
            default => throw new \RuntimeException('Unknown middleware: ' . $name),
        };
        $instance->handle($argument);
    }

    private function invoke(mixed $handler, array $params): void
    {
        if (is_callable($handler)) {
            $handler(...array_values($params));
            return;
        }
        [$class, $method] = $handler;
        (new $class())->{$method}(...array_values($params));
    }
}
