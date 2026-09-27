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

if (!is_file($basePath . '/vendor/autoload.php')) {
    http_response_code(500);
    echo 'Dependencies missing: run "composer install".';
    exit(1);
}

require $basePath . '/vendor/autoload.php';

$app = App\Core\App::boot($basePath);
$app->registerErrorHandler();
$app->handle(App\Core\Request::capture())->send();
