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
    /** Raw request payload (used for JSON bodies and HMAC verification). */
    public string $rawBody = '';

    public function __construct(string $method, string $path, array $query, array $body, array $headers, string $rawBody = '')
    {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
        $this->headers = $headers;
        $this->rawBody = $rawBody;
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

        $body = $_POST;
        $rawBody = '';
        // JSON payloads are not populated by PHP into $_POST, so parse them
        // explicitly. The raw body is always kept for signature checks.
        if (self::isJsonContentType($headers['content-type'] ?? '')) {
            $raw = (string) file_get_contents('php://input');
            $rawBody = $raw;
            if ($raw !== '') {
                try {
                    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $body = $decoded;
                    }
                } catch (\JsonException $e) {
                    // Malformed JSON: leave the body empty so controllers can
                    // answer with a validation error instead of fataling.
                    error_log('Request::capture: malformed JSON body: ' . $e->getMessage());
                }
            }
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $query,
            $body,
            $headers,
            $rawBody
        );
    }

    private static function isJsonContentType(string $contentType): bool
    {
        $normalized = strtolower(trim($contentType));
        if ($normalized === '') {
            return false;
        }
        if (str_starts_with($normalized, 'application/x-www-form-urlencoded') || str_starts_with($normalized, 'multipart/form-data')) {
            return false;
        }

        return str_starts_with($normalized, 'application/json')
            || str_starts_with($normalized, 'application/ld+json')
            || preg_match('~^application/[a-z0-9.+-]*\+json~', $normalized) === 1
            || str_contains($normalized, '+json');
    }

    public function isJson(): bool
    {
        return self::isJsonContentType((string) ($this->headers['content-type'] ?? ''));
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
