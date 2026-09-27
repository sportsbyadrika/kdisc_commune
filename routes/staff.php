<?php

/**
 * Staff console routes (staff session cookie, 'staff' guard). Controllers: app/Controllers/Staff.
 * Every authenticated route must sit inside the auth.staff group and declare a
 * role:/can: middleware for its feature.
 *
 * @var App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Staff\AuditController;
use App\Controllers\Staff\AuthController;
use App\Controllers\Staff\BookingController;
use App\Controllers\Staff\BookingDocumentController;
use App\Controllers\Staff\CheckinController;
use App\Controllers\Staff\DashboardController;
use App\Controllers\Staff\ExplorerController;
use App\Controllers\Staff\FacilityController;
use App\Controllers\Staff\ImportController;
use App\Controllers\Staff\Finance\DepositRefundController;
use App\Controllers\Staff\Finance\FinanceController;
use App\Controllers\Staff\Finance\FinanceDocumentController;
use App\Controllers\Staff\Finance\FinanceSettingsController;
use App\Controllers\Staff\Finance\InvoiceController;
use App\Controllers\Staff\Finance\PaymentVerificationController;
use App\Controllers\Staff\Finance\RegisterController;
use App\Controllers\Staff\KycController;
use App\Controllers\Staff\LayoutApiController;
use App\Controllers\Staff\LayoutController;
use App\Controllers\Staff\NotificationController;
use App\Controllers\Staff\PaymentController;
use App\Controllers\Staff\ReportController;
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

        // Bookings console + lifecycle (batch 5). Rules: BookingWorkflow / PaymentService / CheckinService /
        // SeatTransferService / RenewalService. {no} = booking number BK-YYYY-NNNNNN.
        $r->get('/bookings', [BookingController::class, 'index'])->name('bookings.index')->middleware('can:bookings.view');
        $r->get('/bookings/{no:[A-Za-z0-9-]+}', [BookingController::class, 'show'])->name('bookings.show')->middleware('can:bookings.view');
        $r->post('/bookings/{no:[A-Za-z0-9-]+}/approve', [BookingController::class, 'approve'])->name('bookings.approve')->middleware('can:bookings.approve');
        $r->post('/bookings/{no:[A-Za-z0-9-]+}/reject', [BookingController::class, 'reject'])->name('bookings.reject')->middleware('can:bookings.approve');
        $r->post('/bookings/{no:[A-Za-z0-9-]+}/confirm', [BookingController::class, 'confirm'])->name('bookings.confirm')->middleware('can:payments.log');
        $r->post('/bookings/{no:[A-Za-z0-9-]+}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel')->middleware('can:bookings.cancel');
        $r->post('/bookings/{no:[A-Za-z0-9-]+}/early-exit', [BookingController::class, 'earlyExit'])->name('bookings.early_exit')->middleware('can:bookings.cancel');
        $r->post('/bookings/{no:[A-Za-z0-9-]+}/check-in', [BookingController::class, 'checkIn'])->name('bookings.checkin')->middleware('can:checkins.manage');
        $r->post('/bookings/{no:[A-Za-z0-9-]+}/check-out', [BookingController::class, 'checkOut'])->name('bookings.checkout')->middleware('can:checkins.manage');
        $r->get('/bookings/{no:[A-Za-z0-9-]+}/handover', [BookingController::class, 'handover'])->name('bookings.handover')->middleware('can:seats.handover');
        $r->get('/bookings/{no:[A-Za-z0-9-]+}/handover/quote', [BookingController::class, 'handoverQuote'])->name('bookings.handover.quote')->middleware('can:seats.handover');
        $r->post('/bookings/{no:[A-Za-z0-9-]+}/handover', [BookingController::class, 'handoverStore'])->name('bookings.handover.store')->middleware('can:seats.handover');
        $r->get('/bookings/{no:[A-Za-z0-9-]+}/extend', [BookingController::class, 'extend'])->name('bookings.extend')->middleware('can:bookings.extend');
        $r->post('/bookings/{no:[A-Za-z0-9-]+}/extend', [BookingController::class, 'extendStore'])->name('bookings.extend.store')->middleware('can:bookings.extend');

        // Payments: logged at the desk, voided by the Centre Manager; Finance verifies in batch 6.
        $r->post('/bookings/{no:[A-Za-z0-9-]+}/payments', [PaymentController::class, 'store'])->name('payments.store')->middleware('can:payments.log');
        $r->post('/payments/{id:\d+}/void', [PaymentController::class, 'void'])->name('payments.void')->middleware('can:payments.void');
        $r->get('/payments/{id:\d+}/proof', [PaymentController::class, 'proof'])->name('payments.proof')->middleware('can:bookings.view');

        // Check-in desk (QR / Unique ID) + the explorer seat popover endpoint.
        $r->get('/checkin', [CheckinController::class, 'index'])->name('checkins.index')->middleware('can:checkins.manage');
        $r->post('/checkin/seat', [CheckinController::class, 'seat'])->name('checkins.seat')->middleware('can:checkins.manage');

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

        // Booking / visitor PDFs (generated on demand): allotment letter (confirmed+), visitor ID card.
        $r->get('/bookings/{no:[A-Za-z0-9-]+}/allotment-letter.pdf', [BookingDocumentController::class, 'allotment'])->name('bookings.allotment')->middleware('can:bookings.view');
        $r->get('/visitors/{ref:[A-Za-z0-9-]+}/id-card.pdf', [BookingDocumentController::class, 'idCard'])->name('visitors.id_card')->middleware('can:visitors.view');

        // Finance (batch 6, spec 6.4): dashboard, payment verification, invoices / receipts / credit notes / deposit
        // refunds, registers, settings. Rules: app/Services/Finance. {type} = invoice|receipt|credit-note|deposit-refund.
        $r->get('/finance', [FinanceController::class, 'index'])->name('finance.dashboard')->middleware('can:reports.finance');
        $r->get('/finance/settings', [FinanceSettingsController::class, 'edit'])->name('finance.settings')->middleware('can:finance.settings');
        $r->put('/finance/settings', [FinanceSettingsController::class, 'update'])->name('finance.settings.update')->middleware('can:finance.settings');
        $r->get('/finance/payments', [PaymentVerificationController::class, 'index'])->name('payments.index')->middleware('can:payments.view');
        $r->post('/finance/payments/verify', [PaymentVerificationController::class, 'bulk'])->name('payments.bulk_verify')->middleware('can:payments.verify');
        $r->post('/finance/payments/{id:\d+}/verify', [PaymentVerificationController::class, 'verify'])->name('payments.verify')->middleware('can:payments.verify');
        $r->post('/finance/payments/{id:\d+}/query', [PaymentVerificationController::class, 'query'])->name('payments.query')->middleware('can:payments.verify');
        $r->post('/payments/{id:\d+}/reply', [PaymentVerificationController::class, 'reply'])->name('payments.reply')->middleware('can:payments.log');
        $r->get('/finance/invoices', [InvoiceController::class, 'index'])->name('invoices.index')->middleware('can:invoices.view');
        $r->post('/finance/invoices', [InvoiceController::class, 'store'])->name('invoices.store')->middleware('can:invoices.manage');
        $r->get('/finance/invoices/{id:\d+}', [InvoiceController::class, 'show'])->name('invoices.show')->middleware('can:invoices.view');
        $r->post('/finance/invoices/{id:\d+}/credit-notes', [InvoiceController::class, 'creditNote'])->name('credit_notes.store')->middleware('can:credit_notes.manage');
        $r->get('/finance/deposits/{no:[A-Za-z0-9-]+}/refund', [DepositRefundController::class, 'create'])->name('deposits.create')->middleware('can:deposits.refund');
        $r->post('/finance/deposits/{no:[A-Za-z0-9-]+}/refund', [DepositRefundController::class, 'store'])->name('deposits.store')->middleware('can:deposits.refund');
        $r->get('/finance/documents/{type:invoice|receipt|credit-note|deposit-refund}/{id:\d+}/{slug:[A-Za-z0-9-]+}.pdf', [FinanceDocumentController::class, 'pdf'])->name('finance.documents.pdf')->middleware('can:invoices.view');
        $r->post('/finance/documents/{type:invoice|receipt|credit-note|deposit-refund}/{id:\d+}/reprint', [FinanceDocumentController::class, 'reprint'])->name('finance.documents.reprint')->middleware('can:invoices.manage');
        $r->get('/finance/registers', [RegisterController::class, 'index'])->name('registers.index')->middleware('can:reports.finance');
        $r->get('/finance/registers/{type:[a-z-]+}.pdf', [RegisterController::class, 'pdf'])->name('registers.pdf')->middleware('can:reports.finance');

        // Reports hub + every report page / XLSX / PDF (batch 7). Each report checks its own ability
        // (Reports\Report::ability()); list exports (visitors, bookings-list, payments, invoices) mirror their pages.
        $r->get('/reports', [ReportController::class, 'index'])->name('reports.index')->middleware('can:reports.view');
        $r->get('/reports/{key:[a-z0-9-]+}.xlsx', [ReportController::class, 'xlsx'])->name('reports.xlsx')->middleware('can:dashboard.view');
        $r->get('/reports/{key:[a-z0-9-]+}.pdf', [ReportController::class, 'pdf'])->name('reports.pdf')->middleware('can:dashboard.view');
        $r->get('/reports/{key:[a-z0-9-]+}', [ReportController::class, 'show'])->name('reports.show')->middleware('can:dashboard.view');

        // XLSX bulk import (spec 10): templates, upload → validate → masked preview → confirm, error reports, history.
        $r->get('/imports', [ImportController::class, 'index'])->name('imports.index')->middleware('can:imports.manage');
        $r->get('/imports/templates/{type:[a-z]+}.xlsx', [ImportController::class, 'template'])->name('imports.template')->middleware('can:imports.manage');
        $r->post('/imports', [ImportController::class, 'upload'])->name('imports.upload')->middleware('can:imports.manage');
        $r->get('/imports/{id:\d+}', [ImportController::class, 'show'])->name('imports.show')->middleware('can:imports.manage');
        $r->post('/imports/{id:\d+}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm')->middleware('can:imports.manage');
        $r->post('/imports/{id:\d+}/discard', [ImportController::class, 'discard'])->name('imports.discard')->middleware('can:imports.manage');
        $r->get('/imports/{id:\d+}/errors.xlsx', [ImportController::class, 'errors'])->name('imports.errors')->middleware('can:imports.manage');

        // Audit log viewer (Centre Manager / State Admin).
        $r->get('/audit', [AuditController::class, 'index'])->name('audit.index')->middleware('can:audit.view');
        $r->get('/audit/{id:\d+}', [AuditController::class, 'show'])->name('audit.show')->middleware('can:audit.view');

        // Staff notification inbox (header bell + page).
        $r->get('/notifications', [NotificationController::class, 'index'])->name('notifications.index')->middleware('can:dashboard.view');
        $r->post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read_all')->middleware('can:dashboard.view');
        $r->post('/notifications/{id:\d+}/read', [NotificationController::class, 'read'])->name('notifications.read')->middleware('can:dashboard.view');
        $r->get('/notifications/{id:\d+}/open', [NotificationController::class, 'open'])->name('notifications.open')->middleware('can:dashboard.view');
    });
});
