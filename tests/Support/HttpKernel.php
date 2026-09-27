<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\App;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use Database\Seeds\DatabaseSeeder;

/**
 * In-process HTTP for integration tests: requests go through App::handle() (sessions, global + route middleware,
 * error pages, security headers) exactly as in production, with the CLI array session standing in for the browser's
 * cookie jar (one "browser" per test; reset with signOut()).
 */
trait HttpKernel
{
    protected static bool $booted = false;

    /** Boot against commune_test (fresh schema + seed). Returns false when the DB is unreachable. */
    protected static function bootTestApp(bool $fresh = true): bool
    {
        $uploads = sys_get_temp_dir() . '/commune-test-uploads-' . getmypid();
        foreach (['DB_DATABASE' => 'commune_test', 'MAIL_DSN' => 'null://null', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'UPLOADS_PATH' => $uploads] as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        App::boot(dirname(__DIR__, 2));
        Database::setInstance(null);
        try {
            $db = Database::getInstance();
            App::container()->instance(Database::class, $db);
            $db->pdo();
            if ($fresh) {
                (new Migrator($db, dirname(__DIR__, 2) . '/database/migrations', static fn () => null))->fresh();
                (new DatabaseSeeder($db, static fn () => null))->run();
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $params */
    protected function http(string $method, string $uri, array $params = [], array $server = []): Response
    {
        $server += ['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '10.0.0.' . random_int(1, 250)];
        $request = Request::create($method, $uri, $params, $server);
        return App::instance()->handle($request);
    }

    /** @param array<string, mixed> $params */
    protected function json(string $method, string $uri, array $params = []): Response
    {
        return $this->http($method, $uri, $params + ['_token' => $this->csrf()], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /** POST (or PUT/DELETE) with a valid CSRF token. @param array<string, mixed> $params */
    protected function submit(string $method, string $uri, array $params = []): Response
    {
        return $this->http($method, $uri, $params + ['_token' => $this->csrf()]);
    }

    protected function csrf(): string
    {
        if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    protected function signOut(): void
    {
        $_SESSION = [];
    }

    protected function actingAsStaff(int $id): void
    {
        $_SESSION = ['_auth_staff' => $id, '_auth_staff_at' => time()];
    }

    protected function actingAsVisitor(int $accountId): void
    {
        $_SESSION = ['_auth_visitor' => $accountId, '_auth_visitor_at' => time()];
    }

    protected static function location(Response $response): string
    {
        return (string) $response->getHeader('Location');
    }
}
