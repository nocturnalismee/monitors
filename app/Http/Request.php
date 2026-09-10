<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    public string $method;
    public string $path;
    /** @var array<string, string> */
    public array $query;
    /** @var array<string, mixed> */
    public array $body;
    /** @var array<string, string> */
    public array $headers;
    /** @var array<string, string> */
    public array $params = [];

    public function __construct(string $method, string $path, array $query, array $body, array $headers)
    {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
        $this->headers = $headers;
    }

    public static function capture(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = [];
        $rawQuery = parse_url($uri, PHP_URL_QUERY);
        if (is_string($rawQuery) && $rawQuery !== '') {
            parse_str($rawQuery, $query);
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $query,
            $_POST,
            $headers
        );
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function ip(): string
    {
        return get_client_ip();
    }

    public function referer(): string
    {
        return (string) ($_SERVER['HTTP_REFERER'] ?? '');
    }
}
