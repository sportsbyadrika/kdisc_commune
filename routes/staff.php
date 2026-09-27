<?php

/**
 * Staff console routes (staff session cookie, 'staff' guard). Controllers: app/Controllers/Staff.
 * Every authenticated route must sit inside the auth.staff group and declare a
 * role:/can: middleware for its feature.
 *
 * @var App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Staff\AuthController;
use App\Controllers\Staff\DashboardController;
use App\Core\Router;

$router->group(['prefix' => '/staff', 'as' => 'staff.'], function (Router $r): void {
    $r->get('/', fn () => redirect(url('staff.dashboard')))->name('home');

    $r->group(['middleware' => ['guest:staff']], function (Router $r): void {
        $r->get('/login', [AuthController::class, 'showLogin'])->name('login');
        $r->post('/login', [AuthController::class, 'login'])->name('login.attempt');
    });

    $r->post('/logout', [AuthController::class, 'logout'])->name('logout');

    $r->group(['middleware' => ['auth.staff']], function (Router $r): void {
        $r->get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware('can:dashboard.view');

        // Batch 2+: visitors, KYC, bookings, layout designer, finance ... e.g.
        // $r->get('/visitors/new', [VisitorController::class, 'create'])->name('visitors.create')->middleware('can:visitors.register');
    });
});
