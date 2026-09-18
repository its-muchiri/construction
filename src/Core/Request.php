<?php

namespace Construction\Core;

final class Request
{
    public string $method;
    public string $path;
    /** @var array<string,mixed> */
    public array $query;
    /** @var array<string,mixed> */
    public array $body;
    /** @var array<string,mixed> */
    public array $params = [];
    /** @var array<string,mixed>|null */
    public ?array $user = null;
    /** @var array<string,string> */
    public array $headers = [];

    public function __construct(string $method, string $path, array $query, array $body)
    {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $rawBody = file_get_contents('php://input') ?: '';
        $decoded = json_decode($rawBody, true);
        $body = is_array($decoded) ? $decoded : [];

        $request = new self($method, $path, $_GET, $body);
        $request->headers = self::collectHeaders();

        return $request;
    }

    /** @return array<string,string> */
    private static function collectHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }

    public function bearerToken(): ?string
    {
        foreach ($this->headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0 && str_starts_with($value, 'Bearer ')) {
                return substr($value, 7);
            }
        }

        return null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }
}
