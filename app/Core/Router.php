<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\MethodNotAllowedException;
use App\Core\Exceptions\NotFoundException;
use Closure;
use InvalidArgumentException;

/**
 * Router with named routes, groups (prefix / name prefix / middleware) and
 * a middleware pipeline.
 *
 *   $router->get('/pricing', [PageController::class, 'pricing'])->name('pricing');
 *   $router->group(['prefix' => '/staff', 'as' => 'staff.', 'middleware' => ['auth.staff']], function (Router $r) {
 *       $r->get('/visitors/{id:\d+}', [VisitorController::class, 'show'])->name('visitors.show');
 *   });
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /** @var list<array{prefix: string, as: string, middleware: list<string>}> */
    private array $groupStack = [];

    /** @var array<string, class-string> alias => middleware class */
    private array $aliases = [];

    /** @var list<string> middleware run on every matched route */
    private array $global = [];

    public function __construct(private readonly ?Container $container = null)
    {
    }

    /** @param array<string, class-string> $aliases */
    public function aliasMiddleware(array $aliases): void
    {
        $this->aliases = array_merge($this->aliases, $aliases);
    }

    /** @param list<string> $middleware */
    public function globalMiddleware(array $middleware): void
    {
        $this->global = array_merge($this->global, $middleware);
    }

    /** @param callable|array{0: class-string, 1: string} $action */
    public function get(string $uri, callable|array $action): Route
    {
        return $this->add(['GET', 'HEAD'], $uri, $action);
    }

    /** @param callable|array{0: class-string, 1: string} $action */
    public function post(string $uri, callable|array $action): Route
    {
        return $this->add(['POST'], $uri, $action);
    }

    /** @param callable|array{0: class-string, 1: string} $action */
    public function put(string $uri, callable|array $action): Route
    {
        return $this->add(['PUT'], $uri, $action);
    }

    /** @param callable|array{0: class-string, 1: string} $action */
    public function patch(string $uri, callable|array $action): Route
    {
        return $this->add(['PATCH'], $uri, $action);
    }

    /** @param callable|array{0: class-string, 1: string} $action */
    public function delete(string $uri, callable|array $action): Route
    {
        return $this->add(['DELETE'], $uri, $action);
    }

    /**
     * @param list<string> $methods
     * @param callable|array{0: class-string, 1: string} $action
     */
    public function match(array $methods, string $uri, callable|array $action): Route
    {
        return $this->add(array_map('strtoupper', $methods), $uri, $action);
    }

    /**
     * @param array{prefix?: string, as?: string, middleware?: string|list<string>} $attributes
     */
    public function group(array $attributes, Closure $routes): void
    {
        $parent = end($this->groupStack) ?: ['prefix' => '', 'as' => '', 'middleware' => []];
        $this->groupStack[] = [
            'prefix' => $parent['prefix'] . '/' . trim($attributes['prefix'] ?? '', '/'),
            'as' => $parent['as'] . ($attributes['as'] ?? ''),
            'middleware' => array_merge($parent['middleware'], (array) ($attributes['middleware'] ?? [])),
        ];
        $routes($this);
        array_pop($this->groupStack);
    }

    /**
     * @param list<string> $methods
     * @param callable|array{0: class-string, 1: string} $action
     */
    private function add(array $methods, string $uri, callable|array $action): Route
    {
        $group = end($this->groupStack) ?: ['prefix' => '', 'as' => '', 'middleware' => []];
        $full = Request::normalizePath($group['prefix'] . '/' . trim($uri, '/'));
        $route = new Route($methods, $full, $action, $this);
        $route->namePrefix = $group['as'];
        if ($group['middleware'] !== []) {
            $route->middleware($group['middleware']);
        }
        $this->routes[] = $route;
        return $route;
    }

    /** @internal called by Route::name() */
    public function registerName(string $name, Route $route): void
    {
        $this->named[$route->namePrefix . $name] = $route;
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }

    /**
     * Generate a URL path (without host) for a named route.
     *
     * @param array<string, scalar> $params
     */
    public function url(string $name, array $params = []): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException("Route [{$name}] is not defined.");
        return $route->path($params);
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    /** @return array<string, Route> */
    public function namedRoutes(): array
    {
        return $this->named;
    }

    /**
     * Find the route for a request.
     *
     * @return array{0: Route, 1: array<string, string>}
     */
    public function resolve(Request $request): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            $params = $route->match($request->path());
            if ($params === null) {
                continue;
            }
            if (in_array($request->method(), $route->methods(), true)) {
                return [$route, $params];
            }
            array_push($allowed, ...$route->methods());
        }
        if ($allowed !== []) {
            throw new MethodNotAllowedException(array_values(array_unique($allowed)));
        }
        throw new NotFoundException();
    }

    public function dispatch(Request $request): Response
    {
        [$route, $params] = $this->resolve($request);
        $request->withAttribute('route', $route)->withAttribute('route_params', $params);

        $middleware = array_merge($this->global, $route->getMiddleware());
        $core = fn (Request $req): Response => $this->runAction($route, $req, $params);

        return $this->pipeline($middleware, $core)($request);
    }

    /**
     * @param list<string> $middleware "alias" or "alias:param1,param2" or FQCN
     * @param Closure(Request): Response $core
     * @return Closure(Request): Response
     */
    private function pipeline(array $middleware, Closure $core): Closure
    {
        $next = $core;
        foreach (array_reverse($middleware) as $definition) {
            [$alias, $args] = array_pad(explode(':', $definition, 2), 2, '');
            $class = $this->aliases[$alias] ?? $alias;
            if (!class_exists($class)) {
                throw new InvalidArgumentException("Middleware [{$alias}] is not registered.");
            }
            $params = $args === '' ? [] : explode(',', $args);
            $next = function (Request $request) use ($class, $params, $next): Response {
                /** @var Middleware $instance */
                $instance = $this->container?->get($class) ?? new $class();
                return $instance->handle($request, $next, ...$params);
            };
        }
        return $next;
    }

    /** @param array<string, string> $params */
    private function runAction(Route $route, Request $request, array $params): Response
    {
        $action = $route->action();
        $args = $params + ['request' => $request];
        $container = $this->container ?? new Container();
        $container->instance(Request::class, $request);

        if (is_array($action) && is_string($action[0])) {
            $controller = $container->get($action[0]);
            $result = $container->call([$controller, $action[1]], $args);
        } else {
            $result = $container->call($action, $args);
        }

        return match (true) {
            $result instanceof Response => $result,
            is_array($result) => Response::json($result),
            default => Response::html((string) $result),
        };
    }
}
