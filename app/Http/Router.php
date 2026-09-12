<?php
declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\Registry;

final class Router
{
    /** @var array<int, array<string, mixed>> */
    private array $routes = [];
    private bool $loaded = false;

    public function loadRoutes(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        $files = glob(MONITORS_BASE_DIR . '/routes/*.php');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            $routes = require $file;
            if (is_array($routes)) {
                foreach ($routes as $route) {
                    $this->routes[] = $route;
                }
            }
        }
    }

    private function compilePattern(string $pattern): string
    {
        $pattern = '/' . trim($pattern, '/');
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern) ?? $pattern;
        return '~^' . $pattern . '$~';
    }

    public function dispatch(Request $request): Response
    {
        $this->loadRoutes();
        $path = '/' . trim($request->path, '/');

        foreach ($this->routes as $route) {
            if (!in_array($request->method, (array) ($route['methods'] ?? []), true)) {
                continue;
            }
            if (!preg_match($this->compilePattern((string) ($route['pattern'] ?? '')), $path, $matches)) {
                continue;
            }
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $request->params[$key] = $value;
                }
            }

            $handler = $route['handler'] ?? null;

            if (is_string($handler) && str_starts_with($handler, 'redirect:')) {
                $target = substr($handler, 9);
                $queryString = http_build_query($request->query);
                if ($queryString !== '') {
                    $target .= (str_contains($target, '?') ? '&' : '?') . $queryString;
                }
                return Response::redirect(app_url($target), 301);
            }

            if (is_string($handler) && (str_starts_with($handler, 'redirect307:') || str_starts_with($handler, 'redirect308:'))) {
                $status = str_starts_with($handler, 'redirect307:') ? 307 : 308;
                $target = substr($handler, 12);
                $queryString = http_build_query($request->query);
                if ($queryString !== '') {
                    $target .= (str_contains($target, '?') ? '&' : '?') . $queryString;
                }
                return Response::redirect(app_url($target), $status);
            }

            foreach ((array) ($route['middleware'] ?? []) as $middlewareName) {
                $blocked = Registry::run((string) $middlewareName, $request);
                if ($blocked instanceof Response) {
                    return $blocked;
                }
            }

            $result = $this->invoke($handler, $request);
            return $result instanceof Response ? $result : Response::text((string) $result);
        }

        return Response::text('Not Found', 404);
    }

    private function invoke(mixed $handler, Request $request): mixed
    {
        if (is_callable($handler)) {
            return $handler($request);
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $controller = new $class();
            return $controller->{$method}($request);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $controller = is_object($class) ? $class : new $class();
            return $controller->{$method}($request);
        }

        throw new \RuntimeException('Invalid route handler');
    }
}
