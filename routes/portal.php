<?php

/**
 * Visitor portal routes (site session, 'visitor' guard). Controllers: app/Controllers/Portal.
 * Batch 2 implements registration, set-password, login and the /my/* area:
 *
 *   $router->group(['prefix' => '/my', 'as' => 'portal.', 'middleware' => ['auth.visitor']], function (Router $r) {
 *       $r->get('/profile', [ProfileController::class, 'show'])->name('profile');
 *   });
 *
 * @var App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Portal\AuthController;
use App\Core\Router;

$router->group(['as' => 'portal.', 'middleware' => ['guest:visitor']], function (Router $r): void {
    $r->get('/login', [AuthController::class, 'showLogin'])->name('login');
    $r->get('/register', [AuthController::class, 'showRegister'])->name('register');
});
