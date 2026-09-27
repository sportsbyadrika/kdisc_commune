<?php

/**
 * Public website routes (site session). Controllers: app/Controllers/Site.
 *
 * @var App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Site\CheckoutController;
use App\Controllers\Site\ContactController;
use App\Controllers\Site\ExplorerController;
use App\Controllers\Site\HomeController;
use App\Controllers\Site\PageController;
use App\Controllers\Site\SpaceController;

$router->get('/', [HomeController::class, 'index'])->name('home');
$router->get('/spaces', [SpaceController::class, 'index'])->name('spaces');

// Space Explorer (spec 5): building → floor → seats; checkout needs a signed-in visitor.
$router->get('/spaces/explore', [ExplorerController::class, 'building'])->name('spaces.explore');
$router->get('/spaces/explore/{floor:[a-z0-9-]+}', [ExplorerController::class, 'floor'])->name('spaces.floor');
$router->group(['middleware' => ['auth.visitor']], function (App\Core\Router $r): void {
    $r->post('/spaces/checkout', [CheckoutController::class, 'start'])->name('spaces.checkout.start');
    $r->get('/spaces/checkout', [CheckoutController::class, 'show'])->name('spaces.checkout');
    $r->post('/spaces/checkout/confirm', [CheckoutController::class, 'submit'])->name('spaces.checkout.submit');
    $r->get('/spaces/checkout/done/{no:[A-Za-z0-9-]+}', [CheckoutController::class, 'done'])->name('spaces.checkout.done');
});
$router->get('/facilities', [PageController::class, 'facilities'])->name('facilities');
$router->get('/pricing', [PageController::class, 'pricing'])->name('pricing');
$router->get('/about', [PageController::class, 'about'])->name('about');
$router->get('/privacy', [PageController::class, 'privacy'])->name('privacy');
$router->get('/terms', [PageController::class, 'terms'])->name('terms');
$router->get('/contact', [ContactController::class, 'show'])->name('contact');
$router->post('/contact', [ContactController::class, 'submit'])->name('contact.submit');
