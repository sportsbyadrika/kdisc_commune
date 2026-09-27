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
use App\Controllers\Staff\BookingController;
use App\Controllers\Staff\DashboardController;
use App\Controllers\Staff\ExplorerController;
use App\Controllers\Staff\FacilityController;
use App\Controllers\Staff\KycController;
use App\Controllers\Staff\LayoutApiController;
use App\Controllers\Staff\LayoutController;
use App\Controllers\Staff\VisitorController;
use App\Controllers\Staff\VisitorDocumentController;
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


        // Visitors & assisted registration (spec 4.2). {ref} = Unique Visitor ID, or numeric id before submission.
        $r->get('/visitors', [VisitorController::class, 'index'])->name('visitors.index')->middleware('can:visitors.view');
        $r->get('/visitors/new', [VisitorController::class, 'create'])->name('visitors.create')->middleware('can:visitors.register');
        $r->post('/visitors', [VisitorController::class, 'store'])->name('visitors.store')->middleware('can:visitors.register');
        $r->post('/visitors/duplicates', [VisitorController::class, 'duplicates'])->name('visitors.duplicates')->middleware('can:visitors.register');
        $r->get('/visitors/{ref:[A-Za-z0-9-]+}', [VisitorController::class, 'show'])->name('visitors.show')->middleware('can:visitors.view');
        $r->get('/visitors/{ref:[A-Za-z0-9-]+}/edit', [VisitorController::class, 'edit'])->name('visitors.edit')->middleware('can:visitors.register');
        $r->put('/visitors/{ref:[A-Za-z0-9-]+}', [VisitorController::class, 'update'])->name('visitors.update')->middleware('can:visitors.register');
        $r->post('/visitors/{ref:[A-Za-z0-9-]+}/invite', [VisitorController::class, 'invite'])->name('visitors.invite')->middleware('can:visitors.register');
        $r->post('/visitors/{ref:[A-Za-z0-9-]+}/documents', [VisitorDocumentController::class, 'store'])->name('visitors.documents.store')->middleware('can:documents.upload');
        $r->delete('/visitors/{ref:[A-Za-z0-9-]+}/documents/{id:\d+}', [VisitorDocumentController::class, 'destroy'])->name('visitors.documents.destroy')->middleware('can:documents.upload');
        $r->get('/documents/{id:\d+}', [VisitorDocumentController::class, 'file'])->name('documents.file')->middleware('can:documents.view');

        // KYC verification queue (Centre Manager).
        $r->get('/kyc', [KycController::class, 'index'])->name('kyc.index')->middleware('can:kyc.verify');
        $r->get('/kyc/{id:\d+}', [KycController::class, 'show'])->name('kyc.show')->middleware('can:kyc.verify');
        $r->post('/kyc/{id:\d+}/approve', [KycController::class, 'approve'])->name('kyc.approve')->middleware('can:kyc.verify');
        $r->post('/kyc/{id:\d+}/reject', [KycController::class, 'reject'])->name('kyc.reject')->middleware('can:kyc.verify');

        // Space Explorer — receptionist mode (spec 5.2); JSON API under /staff/api/space (routes/api.php).
        $r->get('/spaces', [ExplorerController::class, 'index'])->name('explorer')->middleware('can:space.explore');

        // Bookings (read-only in batch 3; approvals, payments, check-in in batch 4).
        $r->get('/bookings', [BookingController::class, 'index'])->name('bookings.index')->middleware('can:bookings.view');
        $r->get('/bookings/{no:[A-Za-z0-9-]+}', [BookingController::class, 'show'])->name('bookings.show')->middleware('can:bookings.view');

        // Layout & Pricing Designer (spec 5.4) — Centre Manager. Pages + the canvas JSON API (/staff/layout/api).
        $r->get('/layout', [LayoutController::class, 'index'])->name('layout.index')->middleware('can:layout.design');
        $r->get('/layout/rates', [LayoutController::class, 'rates'])->name('layout.rates')->middleware('can:pricing.manage');
        $r->post('/layout/rates', [LayoutController::class, 'storeRate'])->name('layout.rates.store')->middleware('can:pricing.manage');
        $r->get('/layout/building', [LayoutController::class, 'building'])->name('layout.building')->middleware('can:layout.design');
        $r->post('/layout/building/photo', [LayoutController::class, 'buildingPhoto'])->name('layout.building.photo')->middleware('can:layout.design');
        $r->put('/layout/building/hotspots', [LayoutController::class, 'hotspots'])->name('layout.hotspots')->middleware('can:layout.design');
        $r->post('/layout/floors', [LayoutController::class, 'storeFloor'])->name('layout.floors.store')->middleware('can:layout.design');
        $r->post('/layout/floors/{floor:\d+}/photo', [LayoutController::class, 'floorPhoto'])->name('layout.floors.photo')->middleware('can:layout.design');
        $r->delete('/layout/floors/{floor:\d+}', [LayoutController::class, 'destroyFloor'])->name('layout.floors.destroy')->middleware('can:layout.design');
        $r->get('/layout/floors/{floor:[a-z0-9-]+}', [LayoutController::class, 'designer'])->name('layout.floor')->middleware('can:layout.design');
        $r->get('/layout/floors/{floor:[a-z0-9-]+}/preview', [LayoutController::class, 'preview'])->name('layout.preview')->middleware('can:layout.design');
        $r->get('/layout/floors/{floor:[a-z0-9-]+}/history', [LayoutController::class, 'history'])->name('layout.history')->middleware('can:layout.design');
        $r->post('/layout/floors/{floor:[a-z0-9-]+}/restore/{version:\d+}', [LayoutController::class, 'restore'])->name('layout.restore')->middleware('can:layout.design');

        $r->group(['prefix' => '/layout/api', 'as' => 'layout.api.', 'middleware' => ['can:layout.design']], function (Router $r): void {
            $r->post('/floors/{floor:\d+}/draft', [LayoutApiController::class, 'createDraft'])->name('draft');
            $r->get('/versions/{version:\d+}', [LayoutApiController::class, 'show'])->name('show');
            $r->put('/versions/{version:\d+}', [LayoutApiController::class, 'save'])->name('save');
            $r->delete('/versions/{version:\d+}', [LayoutApiController::class, 'discard'])->name('discard');
            $r->get('/versions/{version:\d+}/check', [LayoutApiController::class, 'check'])->name('check');
            $r->post('/versions/{version:\d+}/publish', [LayoutApiController::class, 'publish'])->name('publish');
            $r->get('/versions/{version:\d+}/pricing', [LayoutApiController::class, 'pricing'])->name('pricing');
            $r->post('/versions/{version:\d+}/rates', [LayoutApiController::class, 'setRates'])->name('rates')->middleware('can:pricing.manage');
            $r->post('/versions/{version:\d+}/rates/clear', [LayoutApiController::class, 'clearRates'])->name('rates.clear')->middleware('can:pricing.manage');
        });

        // Facility master (spec 5.3).
        $r->get('/facilities', [FacilityController::class, 'index'])->name('facilities.index')->middleware('can:facilities.manage');
        $r->get('/facilities/new', [FacilityController::class, 'create'])->name('facilities.create')->middleware('can:facilities.manage');
        $r->post('/facilities', [FacilityController::class, 'store'])->name('facilities.store')->middleware('can:facilities.manage');
        $r->get('/facilities/{id:\d+}/edit', [FacilityController::class, 'edit'])->name('facilities.edit')->middleware('can:facilities.manage');
        $r->put('/facilities/{id:\d+}', [FacilityController::class, 'update'])->name('facilities.update')->middleware('can:facilities.manage');
        $r->post('/facilities/{id:\d+}/toggle', [FacilityController::class, 'toggle'])->name('facilities.toggle')->middleware('can:facilities.manage');
        $r->delete('/facilities/{id:\d+}', [FacilityController::class, 'destroy'])->name('facilities.destroy')->middleware('can:facilities.manage');

        // Batch 5+: bookings approval, payments, check-in, finance ...
    });
});
