<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    public int $status;
    /** @var array<string, string> */
    public array $headers;
    public string $body;
    /** @var array<string, mixed> extra metadata carried between layers (never emitted) */
    public array $meta = [];

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withData(string $key, mixed $value): self
    {
        $this->meta[$key] = $value;
        return $this;
    }

    public static function json(array $payload, int $status = 200): self
    {
        $response = new self(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status
        );
        $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->withHeader('Pragma', 'no-cache');
        return $response;
    }

    public static function redirect(string $path, int $status = 302): self
    {
        return new self('', $status, ['Location' => $path]);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        $isHead = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'HEAD';
        // HEAD must advertise the same status/headers as GET but must not emit
        // a body (RFC 9110 §9.3.2); announce the GET length instead.
        if ($isHead && !isset($this->headers['Content-Length']) && $this->body !== '' && $this->status >= 200 && $this->status < 300 && $this->status !== 204) {
            header('Content-Length: ' . strlen($this->body));
        }
        if ($isHead) {
            return;
        }
        echo $this->body;
    }
}
