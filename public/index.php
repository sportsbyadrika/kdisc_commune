<?php

/**
 * Front controller. Every request that is not a real file under public/ is
 * routed here (see public/.htaccess and nginx.conf.example), so URLs never
 * contain ".php".
 *
 * Also works as the router script for PHP's built-in server:
 *   php -S 127.0.0.1:8000 -t public public/index.php
 */

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $file = __DIR__ . rawurldecode($path);
    if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false; // let the built-in server serve static assets
    }
}

$basePath = dirname(__DIR__);

/*
 * Start-up safety net (shared hosting): anything that breaks before the app's own error handler is running — a
 * missing PHP extension, an unreadable .env, a fatal error in a library — is written to storage/logs/boot-error.log
 * and, when APP_DEBUG=true in .env, shown on the page instead of a bare "HTTP 500".
 */
$bootFailed = static function (string $message) use ($basePath): void {
    @mkdir($basePath . '/storage/logs', 0775, true);
    @file_put_contents($basePath . '/storage/logs/boot-error.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
    $env = (string) @file_get_contents($basePath . '/.env');
    $debug = preg_match('/^\s*APP_DEBUG\s*=\s*["\']?(true|1|on|yes)\b/mi', $env) === 1;
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Commune — start-up error</title>'
        . '<div style="font:15px/1.5 system-ui,sans-serif;max-width:760px;margin:10vh auto;padding:0 16px">'
        . '<h1 style="font-size:22px">The application could not start</h1>'
        . ($debug
            ? '<pre style="white-space:pre-wrap;background:#f6f6f8;padding:12px;border-radius:8px">' . htmlspecialchars($message, ENT_QUOTES) . '</pre>'
              . '<p>Set <code>APP_DEBUG=false</code> in .env again once this is fixed.</p>'
            : '<p>The details were written to <code>storage/logs/boot-error.log</code> in the application folder.</p>')
        . '</div>';
};
register_shutdown_function(static function () use ($bootFailed): void {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !defined('COMMUNE_BOOTED')) {
        $bootFailed(sprintf('PHP fatal error: %s in %s:%d', $e['message'], $e['file'], $e['line']));
    }
});

if (!is_file($basePath . '/vendor/autoload.php')) {
    $bootFailed('Dependencies missing: vendor/autoload.php not found (deploy copies deploy/vendor, or run "composer install").');
    exit(1);
}

try {
    require $basePath . '/vendor/autoload.php';
    $app = App\Core\App::boot($basePath);
    $app->registerErrorHandler();
    define('COMMUNE_BOOTED', true);
} catch (Throwable $e) {
    $bootFailed(sprintf('%s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    exit(1);
}

$app->handle(App\Core\Request::capture())->send();
