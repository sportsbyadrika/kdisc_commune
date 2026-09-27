<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A single route definition. Created via Router::get()/post()/... and
 * configured fluently: ->name('staff.dashboard')->middleware('role:centre_manager')
 */
final class Route
{
    private ?string $name = null;

    /** @var list<string> */
    private array $middleware = [];

    /** @var array<string, string> custom regex per parameter, e.g. ['id' => '\d+'] */
    private array $wheres = [];

    private ?string $compiled = null;

    /** Name prefix inherited from the enclosing group ('as'), applied when ->name() is called. */
    public string $namePrefix = '';

    /**
     * @param list<string> $methods
     * @param callable|array{0: class-string, 1: string} $action
     */
    public function __construct(
        private readonly array $methods,
        private readonly string $uri,
        private readonly mixed $action,
        private readonly Router $router,
    ) {
    }

    public function name(string $name): self
    {
        $this->name = $this->namePrefix . $name;
        $this->router->registerName($name, $this);
        return $this;
    }

    /** @param string|list<string> ...$middleware */
    public function middleware(string|array ...$middleware): self
    {
        foreach ($middleware as $m) {
            foreach ((array) $m as $item) {
                $this->middleware[] = $item;
            }
        }
        return $this;
    }

    public function where(string $param, string $regex): self
    {
        $this->wheres[$param] = $regex;
        $this->compiled = null;
        return $this;
    }

    public function whereNumber(string $param): self
    {
        return $this->where($param, '\d+');
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return $this->methods;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function action(): mixed
    {
        return $this->action;
    }

    /** @return list<string> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Match a path, returning route parameters or null.
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        if (!preg_match($this->regex(), $path, $m)) {
            return null;
        }
        $params = [];
        foreach ($m as $key => $value) {
            if (is_string($key)) {
                $params[$key] = rawurldecode($value);
            }
        }
        return $params;
    }

    public function regex(): string
    {
        if ($this->compiled !== null) {
            return $this->compiled;
        }
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            function (array $m): string {
                $name = $m[1];
                $pattern = ($m[2] ?? '') !== '' ? $m[2] : ($this->wheres[$name] ?? '[^/]+');
                return '(?P<' . $name . '>' . $pattern . ')';
            },
            $this->quoteStatic($this->uri),
        );
        return $this->compiled = '#^' . $regex . '$#u';
    }

    /**
     * Build the URL path for this route.
     *
     * @param array<string, scalar> $params extra params become the query string
     */
    public function path(array $params = []): string
    {
        $path = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}#',
            function (array $m) use (&$params): string {
                $name = $m[1];
                if (!array_key_exists($name, $params)) {
                    throw new \InvalidArgumentException("Missing parameter [{$name}] for route [{$this->name}].");
                }
                $value = rawurlencode((string) $params[$name]);
                unset($params[$name]);
                return $value;
            },
            $this->uri,
        ) ?? $this->uri;
        $query = http_build_query($params);
        return $path . ($query !== '' ? '?' . $query : '');
    }

    /** Escape regex metacharacters in the static parts while keeping {param} tokens intact. */
    private function quoteStatic(string $uri): string
    {
        $parts = preg_split('#(\{[^}]+\})#', $uri, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        foreach ($parts as $part) {
            $out .= str_starts_with($part, '{') ? $part : preg_quote($part, '#');
        }
        return $out;
    }
}
