<?php

/**
 * Space Explorer JSON API (spec 5). The same endpoints are mounted twice so each audience keeps its
 * own session cookie (the staff cookie is scoped to /staff):
 *   /api/space/*        public + visitor guard  (names api.space.*)       — guests read-only
 *   /staff/api/space/*  staff guard             (names staff.api.space.*) — receptionist mode
 * Controller: App\Controllers\Api\SpaceController. Mutations need the X-CSRF-TOKEN header.
 *
 * @var App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Api\SpaceController;
use App\Core\Router;

$mount = static function (Router $r): void {
    $r->get('/building', [SpaceController::class, 'building'])->name('building');
    $r->get('/floors/{floor:[a-z0-9-]+}/map', [SpaceController::class, 'map'])->name('map');
    $r->get('/availability', [SpaceController::class, 'availability'])->name('availability');
    $r->get('/holds', [SpaceController::class, 'current'])->name('holds');
    $r->post('/holds', [SpaceController::class, 'hold'])->name('holds.store');
    $r->post('/holds/renew', [SpaceController::class, 'renew'])->name('holds.renew');
    $r->delete('/holds/{seat:\d+}', [SpaceController::class, 'release'])->name('holds.destroy');
    $r->delete('/holds', [SpaceController::class, 'releaseAll'])->name('holds.clear');
    $r->post('/quote', [SpaceController::class, 'quote'])->name('quote');
};

$router->group(['prefix' => '/api/space', 'as' => 'api.space.'], $mount);

$router->group(['prefix' => '/staff/api/space', 'as' => 'staff.api.space.', 'middleware' => ['auth.staff', 'can:space.explore']], function (Router $r) use ($mount): void {
    $mount($r);
    $r->get('/customers', [SpaceController::class, 'customers'])->name('customers')->middleware('can:visitors.view');
    $r->post('/book', [SpaceController::class, 'book'])->name('book')->middleware('can:bookings.create');
});
