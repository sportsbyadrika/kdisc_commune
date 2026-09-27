<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Core\Response;
use App\Models\Customer;
use App\Models\CustomerSignatory;
use App\Services\Bookings\BookingDirectory;
use App\Services\Payments\PaymentLedger;
use App\Support\Clock;
use App\Services\Visitors\ProfileService;
use App\Services\Visitors\QrCodeRenderer;

/** Visitor portal home (/my) and profile view (/my/profile). */
final class DashboardController extends PortalController
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly QrCodeRenderer $qr,
        private readonly BookingDirectory $bookings,
        private readonly PaymentLedger $ledger,
        private readonly Clock $clock,
    )
    {
    }

    public function index(): Response
    {
        $customer = $this->customer();
        $uniqueId = (string) ($customer['unique_id'] ?? '');
        return $this->view('portal/dashboard', [
            'title' => 'My dashboard',
            'customer' => Customer::safe($customer),
            'account' => $this->account(),
            'type' => Customer::type($customer),
            'kyc' => Customer::kyc($customer),
            'qr' => $uniqueId !== '' ? $this->qr->dataUri($uniqueId) : null,
            'nextStep' => ProfileService::nextStep($customer),
            'missing' => $this->profiles->missing($customer),
            'bookingCount' => array_sum($this->bookings->statusCounts((int) $customer['id'])),
            'current' => array_values(array_filter(
                $this->bookings->forCustomer((int) $customer['id']),
                static fn (array $b) => in_array($b['status'], ['approved', 'confirmed', 'active'], true),
            )),
            'outstanding' => $this->ledger->customerOutstanding((int) $customer['id']),
            'today' => $this->clock->today(),
            'documentCount' => (int) db()->scalar(
                'SELECT (SELECT COUNT(*) FROM invoices WHERE customer_id = ?) + (SELECT COUNT(*) FROM receipts WHERE customer_id = ?) + (SELECT COUNT(*) FROM credit_notes WHERE customer_id = ?) + (SELECT COUNT(*) FROM deposit_refunds WHERE customer_id = ?)',
                array_fill(0, 4, (int) $customer['id']),
            ),
        ]);
    }

    public function profile(): Response
    {
        $customer = $this->customer();
        return $this->view('portal/profile', [
            'title' => 'My profile',
            'customer' => Customer::safe($customer),
            'type' => Customer::type($customer),
            'kyc' => Customer::kyc($customer),
            'signatory' => CustomerSignatory::primaryFor((int) $customer['id']),
            'checklist' => $this->profiles->documentChecklist($customer),
        ]);
    }
}
