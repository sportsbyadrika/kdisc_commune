<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Auth\Guard;
use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Application kernel: bootstraps env/config/services and turns a Request into a Response.
 *
 *   $app = App::boot(dirname(__DIR__));
 *   $app->handle(Request::capture())->send();
 */
final class App
{
    private static ?App $instance = null;

    private ?Request $request = null;

    private function __construct(
        private readonly string $basePath,
        private readonly Container $container,
        private readonly Config $config,
    ) {
    }

    public static function boot(string $basePath): self
    {
        if (is_file($basePath . '/.env')) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }
        $container = new Container();
        Container::setInstance($container);
        $config = Config::fromDirectory($basePath . '/config');
        $app = new self($basePath, $container, $config);
        self::$instance = $app;

        date_default_timezone_set((string) $config->get('app.timezone', 'Asia/Kolkata'));
        mb_internal_encoding('UTF-8');
        \App\Services\Kyc\KycRules::register();

        $container->instance(self::class, $app);
        $container->instance(Container::class, $container);
        $container->instance(Config::class, $config);
        $container->singleton(LoggerInterface::class, fn () => Logger::create(
            $basePath . '/storage/logs/app.log',
            (string) $config->get('app.log_level', 'debug'),
        ));
        $container->singleton(Database::class, fn () => Database::getInstance());
        $container->singleton(View::class, fn () => new View($basePath . '/resources/views'));
        $container->singleton(\App\Services\Notify\Mailer::class, fn (Container $c) => new \App\Services\Notify\Mailer(
            (array) $config->get('mail', []),
            $c->get(View::class),
            $c->get(LoggerInterface::class),
        ));
        $container->singleton(\App\Services\Kyc\AadhaarVault::class, fn () => new \App\Services\Kyc\AadhaarVault());
        $container->singleton(Router::class, function (Container $c) use ($basePath, $config): Router {
            $router = new Router($c);
            $router->aliasMiddleware((array) $config->get('middleware.aliases', []));
            $router->globalMiddleware((array) $config->get('middleware.global', []));
            foreach ((array) $config->get('app.route_files', []) as $file) {
                (static function (Router $router) use ($basePath, $file): void {
                    require $basePath . '/routes/' . $file;
                })($router);
            }
            return $router;
        });
        $container->singleton(ErrorHandler::class, fn (Container $c) => new ErrorHandler(
            (bool) $config->get('app.debug', false),
            $c->get(LoggerInterface::class),
            $c->get(View::class),
        ));

        return $app;
    }

    public static function instance(): self
    {
        return self::$instance ?? throw new \RuntimeException('Application has not been booted.');
    }

    public static function isBooted(): bool
    {
        return self::$instance !== null;
    }

    public static function basePath(string $path = ''): string
    {
        $base = self::$instance !== null ? self::$instance->basePath : dirname(__DIR__, 2);
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        return self::$instance?->config->get($key, $default) ?? $default;
    }

    public static function container(): Container
    {
        return self::$instance !== null ? self::$instance->container : Container::getInstance();
    }

    public static function request(): ?Request
    {
        return self::$instance?->request;
    }

    /** Resolve an auth guard ('staff' | 'visitor'). */
    public static function guard(string $name): Guard
    {
        $container = self::container();
        $key = 'guard.' . $name;
        if (!$container->has($key)) {
            $cfg = (array) self::config('auth.guards.' . $name, []);
            if ($cfg === []) {
                throw new \InvalidArgumentException("Auth guard [{$name}] is not configured.");
            }
            $session = Session::current() ?? new Session();
            /** @var array{table: string, active_column?: string|null} $cfg */
            $container->instance($key, new Guard($name, $cfg, $session, $container->get(Database::class)));
        }
        /** @var Guard */
        return $container->get($key);
    }

    public function registerErrorHandler(): void
    {
        $this->container->get(ErrorHandler::class)->register();
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $this->container->instance(Request::class, $request);

        try {
            $this->startSession($request);
            $this->shareViewData();
            /** @var Router $router */
            $router = $this->container->get(Router::class);
            $response = $router->dispatch($request);
        } catch (Throwable $e) {
            /** @var ErrorHandler $handler */
            $handler = $this->container->get(ErrorHandler::class);
            $response = $handler->render($e, $request);
        }

        return $this->withSecurityHeaders($response, $request);
    }

    /** Pick the staff or site session based on the URL prefix (see config/session.php). */
    private function startSession(Request $request): void
    {
        $contexts = (array) $this->config->get('session.contexts', []);
        $chosen = $contexts['site'] ?? [];
        $name = 'site';
        foreach ($contexts as $contextName => $ctx) {
            $prefix = (string) ($ctx['prefix'] ?? '');
            if ($prefix !== '' && ($request->path() === $prefix || str_starts_with($request->path(), $prefix . '/'))) {
                $chosen = $ctx;
                $name = (string) $contextName;
                break;
            }
        }
        $options = array_merge((array) $this->config->get('session.defaults', []), $chosen);
        $options['path'] = $request->basePath() . ($options['path'] ?? '/');
        $options['secure'] = (bool) ($options['secure'] ?? false) || $request->isSecure();
        $session = new Session($options);
        $session->start();
        Session::setCurrent($session);
        $request->withAttribute('session_context', $name);
        $this->container->instance(Session::class, $session);
        $this->container->instance(Csrf::class, new Csrf($session));
    }

    private function shareViewData(): void
    {
        /** @var View $view */
        $view = $this->container->get(View::class);
        $view->share('app_name', (string) $this->config->get('app.name', 'Commune'));
        $view->share('current_path', $this->request?->path() ?? '/');
    }

    private function withSecurityHeaders(Response $response, Request $request): Response
    {
        foreach ((array) $this->config->get('security.headers', []) as $name => $value) {
            if ($response->getHeader((string) $name) === null) {
                $response->header((string) $name, (string) $value);
            }
        }
        if ($request->isSecure() && (bool) $this->config->get('security.hsts', true)) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($request->attribute('session_context') !== null && $response->getHeader('Cache-Control') === null) {
            // Pages may contain CSRF tokens / personal data: never cache in shared proxies.
            $response->header('Cache-Control', 'no-store, private');
        }
        return $response;
    }
}
