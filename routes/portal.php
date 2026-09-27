<?php

/**
 * Visitor portal routes (site session, 'visitor' guard). Controllers: app/Controllers/Portal.
 * Everything under /my requires auth.visitor and is scoped to the signed-in visitor's own profile.
 *
 * @var App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Portal\AuthController;
use App\Controllers\Portal\BookingController;
use App\Controllers\Portal\DashboardController;
use App\Controllers\Portal\DocumentController;
use App\Controllers\Portal\FinanceController;
use App\Controllers\Portal\WizardController;
use App\Core\Router;

$router->group(['as' => 'portal.'], function (Router $r): void {
    $r->group(['middleware' => ['guest:visitor']], function (Router $r): void {
        $r->get('/login', [AuthController::class, 'showLogin'])->name('login');
        $r->post('/login', [AuthController::class, 'login'])->name('login.attempt')->middleware('throttle:login,30,10');
        $r->get('/register', [AuthController::class, 'showRegister'])->name('register');
        $r->post('/register', [AuthController::class, 'register'])->name('register.store')->middleware('throttle:register,10,60');
        $r->get('/register/check-email', [AuthController::class, 'registered'])->name('register.sent');
        $r->get('/password/forgot', [AuthController::class, 'showForgot'])->name('password.forgot');
        $r->post('/password/forgot', [AuthController::class, 'sendReset'])->name('password.email')->middleware('throttle:pwlink,10,60');
        $r->post('/password/resend', [AuthController::class, 'resend'])->name('password.resend')->middleware('throttle:pwlink,10,60');
        $r->get('/password/check-email', [AuthController::class, 'linkSent'])->name('password.sent');
    });

    // Single-use links from emails (work whether or not someone is signed in).
    $r->get('/password/set/{token}', [AuthController::class, 'showSetPassword'])->name('password.set');
    $r->post('/password/set/{token}', [AuthController::class, 'setPassword'])->name('password.set.store')->middleware('throttle:pwset,20,10');
    $r->get('/password/reset/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    $r->post('/password/reset/{token}', [AuthController::class, 'resetPassword'])->name('password.reset.store')->middleware('throttle:pwset,20,10');

    $r->post('/logout', [AuthController::class, 'logout'])->name('logout');

    $r->group(['prefix' => '/my', 'middleware' => ['auth.visitor']], function (Router $r): void {
        $r->get('/', [DashboardController::class, 'index'])->name('dashboard');
        $r->get('/profile', [DashboardController::class, 'profile'])->name('profile');
        $r->get('/profile/wizard', [WizardController::class, 'start'])->name('wizard.start');
        $r->get('/profile/wizard/{step:[1-4]}', [WizardController::class, 'show'])->name('wizard');
        $r->post('/profile/wizard/{step:[1-4]}', [WizardController::class, 'save'])->name('wizard.save')->middleware('throttle:upload,60,10');
        $r->get('/documents', [DocumentController::class, 'index'])->name('documents');
        $r->post('/documents', [DocumentController::class, 'store'])->name('documents.store')->middleware('throttle:upload,30,10');
        $r->delete('/documents/{id:\d+}', [DocumentController::class, 'destroy'])->name('documents.destroy');
        $r->get('/documents/{id:\d+}/file', [DocumentController::class, 'file'])->name('documents.file');
        $r->get('/bookings', [BookingController::class, 'index'])->name('bookings');
        $r->get('/bookings/{no:[A-Za-z0-9-]+}', [BookingController::class, 'show'])->name('bookings.show');
        $r->post('/bookings/{no:[A-Za-z0-9-]+}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        $r->get('/bookings/{no:[A-Za-z0-9-]+}/renew', [BookingController::class, 'renew'])->name('bookings.renew');
        $r->get('/bookings/{no:[A-Za-z0-9-]+}/allotment-letter.pdf', [FinanceController::class, 'allotment'])->name('bookings.allotment')->middleware('throttle:pdf,30,5');
        // Invoices, receipts, credit notes, deposit refunds — {id} + number slug (numbers contain slashes).
        $r->get('/invoices', [FinanceController::class, 'index'])->name('invoices');
        $r->get('/invoices/{type:invoice|receipt|credit-note|deposit-refund}/{id:\d+}/{slug:[A-Za-z0-9-]+}.pdf', [FinanceController::class, 'pdf'])->name('invoices.pdf')->middleware('throttle:pdf,30,5');
        $r->get('/id-card.pdf', [FinanceController::class, 'idCard'])->name('id_card')->middleware('throttle:pdf,30,5');
    });
});
