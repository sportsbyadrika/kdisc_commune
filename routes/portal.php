<?php

/**
 * Visitor portal routes (site session, 'visitor' guard). Controllers: app/Controllers/Portal.
 * Everything under /my requires auth.visitor and is scoped to the signed-in visitor's own profile.
 *
 * @var App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Portal\AuthController;
use App\Controllers\Portal\DashboardController;
use App\Controllers\Portal\DocumentController;
use App\Controllers\Portal\WizardController;
use App\Core\Router;

$router->group(['as' => 'portal.'], function (Router $r): void {
    $r->group(['middleware' => ['guest:visitor']], function (Router $r): void {
        $r->get('/login', [AuthController::class, 'showLogin'])->name('login');
        $r->post('/login', [AuthController::class, 'login'])->name('login.attempt');
        $r->get('/register', [AuthController::class, 'showRegister'])->name('register');
        $r->post('/register', [AuthController::class, 'register'])->name('register.store');
        $r->get('/register/check-email', [AuthController::class, 'registered'])->name('register.sent');
        $r->get('/password/forgot', [AuthController::class, 'showForgot'])->name('password.forgot');
        $r->post('/password/forgot', [AuthController::class, 'sendReset'])->name('password.email');
        $r->post('/password/resend', [AuthController::class, 'resend'])->name('password.resend');
        $r->get('/password/check-email', [AuthController::class, 'linkSent'])->name('password.sent');
    });

    // Single-use links from emails (work whether or not someone is signed in).
    $r->get('/password/set/{token}', [AuthController::class, 'showSetPassword'])->name('password.set');
    $r->post('/password/set/{token}', [AuthController::class, 'setPassword'])->name('password.set.store');
    $r->get('/password/reset/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    $r->post('/password/reset/{token}', [AuthController::class, 'resetPassword'])->name('password.reset.store');

    $r->post('/logout', [AuthController::class, 'logout'])->name('logout');

    $r->group(['prefix' => '/my', 'middleware' => ['auth.visitor']], function (Router $r): void {
        $r->get('/', [DashboardController::class, 'index'])->name('dashboard');
        $r->get('/profile', [DashboardController::class, 'profile'])->name('profile');
        $r->get('/profile/wizard', [WizardController::class, 'start'])->name('wizard.start');
        $r->get('/profile/wizard/{step:[1-4]}', [WizardController::class, 'show'])->name('wizard');
        $r->post('/profile/wizard/{step:[1-4]}', [WizardController::class, 'save'])->name('wizard.save');
        $r->get('/documents', [DocumentController::class, 'index'])->name('documents');
        $r->post('/documents', [DocumentController::class, 'store'])->name('documents.store');
        $r->delete('/documents/{id:\d+}', [DocumentController::class, 'destroy'])->name('documents.destroy');
        $r->get('/documents/{id:\d+}/file', [DocumentController::class, 'file'])->name('documents.file');
    });
});
