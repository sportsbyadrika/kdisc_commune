<?php

/**
 * Public website routes (site session). Controllers: app/Controllers/Site.
 *
 * @var App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Site\ContactController;
use App\Controllers\Site\HomeController;
use App\Controllers\Site\PageController;
use App\Controllers\Site\SpaceController;

$router->get('/', [HomeController::class, 'index'])->name('home');
$router->get('/spaces', [SpaceController::class, 'index'])->name('spaces');
$router->get('/facilities', [PageController::class, 'facilities'])->name('facilities');
$router->get('/pricing', [PageController::class, 'pricing'])->name('pricing');
$router->get('/about', [PageController::class, 'about'])->name('about');
$router->get('/privacy', [PageController::class, 'privacy'])->name('privacy');
$router->get('/terms', [PageController::class, 'terms'])->name('terms');
$router->get('/contact', [ContactController::class, 'show'])->name('contact');
$router->post('/contact', [ContactController::class, 'submit'])->name('contact.submit');
