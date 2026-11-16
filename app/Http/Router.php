<?php
declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\Registry;

final class Router
{
    /** @var array<int, array<string, mixed>> */
    private array $routes = [];
    /** @var array<string, string> compiled pattern cache (pattern => regex) */
    private array $compiled = [];
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
        return $this->compiled[$pattern] ??= (static function (string $pattern): string {
            $pattern = '/' . trim($pattern, '/');
            $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern) ?? $pattern;
            return '~^' . $pattern . '$~';
        })($pattern);
    }

    public function dispatch(Request $request): Response
    {
        $this->loadRoutes();
        $path = '/' . trim($request->path, '/');

        // HEAD is served by the GET handler; the response body is stripped in
        // Response::send() so cache/proxy semantics stay correct.
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $allowed = [];

        foreach ($this->routes as $route) {
            $methods = array_map('strtoupper', (array) ($route['methods'] ?? []));
            $pattern = (string) ($route['pattern'] ?? '');
            if (!preg_match($this->compilePattern($pattern), $path, $matches)) {
                continue;
            }
            if (!in_array($method, $methods, true)) {
                $allowed = array_merge($allowed, $methods);
                continue;
            }
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $request->params[$key] = $value;
                }
            }

            $handler = $route['handler'] ?? null;

            if (is_string($handler) && str_starts_with($handler, 'redirect:')) {
                return Response::redirect($this->withQuery(app_url(substr($handler, 9)), $request), 301);
            }

            if (is_string($handler) && (str_starts_with($handler, 'redirect307:') || str_starts_with($handler, 'redirect308:'))) {
                $status = str_starts_with($handler, 'redirect307:') ? 307 : 308;
                return Response::redirect($this->withQuery(app_url(substr($handler, 12)), $request), $status);
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

        if ($allowed !== []) {
            // Path exists but not for this verb: 405 with an Allow header so
            // clients and crawlers stop guessing (HEAD implied by GET).
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            $allowed[] = 'OPTIONS';
            $allowed = array_values(array_unique($allowed));
            sort($allowed);
            $allowHeader = implode(', ', $allowed);
            if ($request->method === 'OPTIONS') {
                // The methods we advertise in Allow are the methods we answer.
                $response = Response::text('', 204);
                $response->headers['Allow'] = $allowHeader;
                return $response;
            }
            $response = Response::text('Method Not Allowed', 405);
            $response->headers['Allow'] = $allowHeader;
            return $response;
        }

        return Response::text('Not Found', 404);
    }

    private function withQuery(string $target, Request $request): string
    {
        $queryString = http_build_query($request->query);
        if ($queryString === '') {
            return $target;
        }

        return $target . (str_contains($target, '?') ? '&' : '?') . $queryString;
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
