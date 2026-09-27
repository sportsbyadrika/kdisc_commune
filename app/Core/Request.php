<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable-ish wrapper around the PHP superglobals.
 * Route parameters and middleware data are stored as "attributes".
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $files
     * @param array<string, string> $cookies
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $files = [],
        private readonly array $cookies = [],
        private readonly string $basePath = '',
        private readonly ?string $body = null,
    ) {
    }

    public static function capture(): self
    {
        $server = $_SERVER;
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');

        // Support installs in a sub-directory (e.g. /commune/public/index.php).
        $basePath = '';
        if (PHP_SAPI !== 'cli-server') {
            $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
            $dir = rtrim(dirname($script), '/');
            if ($dir !== '' && $dir !== '.' && str_starts_with($path, $dir)) {
                $basePath = $dir;
                $path = substr($path, strlen($dir));
            }
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $post = $_POST;
        $body = null;
        $contentType = (string) ($server['CONTENT_TYPE'] ?? '');
        if ($method !== 'GET' && str_contains($contentType, 'application/json')) {
            $body = (string) file_get_contents('php://input');
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $post = $decoded;
            }
        }
        // HTML forms can only POST: allow _method=PUT|PATCH|DELETE override.
        if ($method === 'POST' && isset($post['_method']) && is_string($post['_method'])) {
            $override = strtoupper($post['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self($method, self::normalizePath($path), $_GET, $post, $server, $_FILES, $_COOKIE, $basePath, $body);
    }

    /**
     * Build a request manually (tests, console).
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $server
     */
    public static function create(string $method, string $uri, array $params = [], array $server = []): self
    {
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $query);
        $method = strtoupper($method);
        $server += ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1'];
        return $method === 'GET'
            ? new self($method, self::normalizePath($path), array_merge($query, $params), [], $server)
            : new self($method, self::normalizePath($path), $query, $params, $server);
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim(rawurldecode($path), '/');
        return preg_replace('#/+#', '/', $path) ?? '/';
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function is(string $pattern): bool
    {
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
        return (bool) preg_match($regex, $this->path);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->input($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->post) || array_key_exists($key, $this->query);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    /** @return array<string, mixed> Body parameters only (no query string). */
    public function post(): array
    {
        return $this->post;
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        $all = $this->all();
        return array_intersect_key($all, array_flip($keys));
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    /** @return array<string, mixed>|null the raw $_FILES entry */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    public function cookie(string $key): ?string
    {
        return $this->cookies[$key] ?? null;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }
        $plain = strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$plain]) ? (string) $this->server[$plain] : $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        return ($https !== '' && $https !== 'off')
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (int) ($this->server['SERVER_PORT'] ?? 80) === 443;
    }

    public function isAjax(): bool
    {
        return ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    public function wantsJson(): bool
    {
        return $this->isAjax() || str_contains((string) ($this->server['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    public function referer(): ?string
    {
        $ref = $this->server['HTTP_REFERER'] ?? null;
        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    public function fullPath(): string
    {
        $qs = http_build_query($this->query);
        return $this->basePath . $this->path . ($qs !== '' ? '?' . $qs : '');
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** Route parameter, e.g. {id} */
    public function route(string $key, mixed $default = null): mixed
    {
        $params = $this->attributes['route_params'] ?? [];
        return is_array($params) && array_key_exists($key, $params) ? $params[$key] : $default;
    }

    public function body(): ?string
    {
        return $this->body;
    }
}
