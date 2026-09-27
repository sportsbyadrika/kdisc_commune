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
use App\Controllers\Staff\KycController;
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

        // Batch 4+: layout designer, payments, check-in, finance ...
    });
});
