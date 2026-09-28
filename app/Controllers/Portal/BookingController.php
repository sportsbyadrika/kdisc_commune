<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Enums\BookingStatus;
use App\Models\Customer;
use App\Services\Bookings\Actor;
use App\Services\Bookings\BookingDirectory;
use App\Services\Bookings\BookingWorkflow;
use App\Services\Bookings\RenewalService;
use App\Services\Bookings\WorkflowException;
use App\Services\Finance\FinanceDocuments;
use App\Services\Payments\PaymentLedger;
use App\Services\Pdf\BookingDocuments;
use App\Support\Clock;

/**
 * /my/bookings — the visitor's own bookings: status timeline, dues + payment history, rent schedule,
 * "Cancel request" (requested / approved only) and "Renew" (reopens the Space Explorer with the same seats and
 * the next dates preselected: /spaces/explore/{floor}?renew={bookingNo}).
 */
final class BookingController extends PortalController
{
    public function __construct(
        private readonly BookingDirectory $bookings,
        private readonly PaymentLedger $ledger,
        private readonly RenewalService $renewals,
        private readonly Clock $clock,
    ) {
    }

    public function index(): Response
    {
        $customer = $this->customer();
        $rows = $this->bookings->forCustomer((int) $customer['id']);
        $dues = $this->ledger->duesMany(array_values(array_filter($rows, static fn (array $b) => BookingStatus::from((string) $b['status'])->billable())));
        return $this->view('portal/bookings/index', [
            'title' => 'My bookings',
            'customer' => Customer::safe($customer),
            'bookings' => $rows,
            'dues' => $dues,
            'outstanding' => $this->ledger->customerOutstanding((int) $customer['id']),
        ]);
    }

    public function show(string $no, FinanceDocuments $documents): Response
    {
        $customer = $this->customer();
        $booking = $this->bookings->findByNo($no, (int) $customer['id']) ?? throw new NotFoundException();
        $status = BookingStatus::from((string) $booking['status']);
        return $this->view('portal/bookings/show', [
            'title' => 'Booking ' . $booking['booking_no'],
            'customer' => Customer::safe($customer),
            'booking' => $booking,
            'seats' => array_values(array_filter($this->bookings->seats((int) $booking['id']), static fn (array $s) => $s['transferred_to_id'] === null)),
            'facilities' => $this->bookings->facilities((int) $booking['id']),
            'timeline' => BookingDirectory::timeline($booking),
            'dues' => $status->billable() ? $this->ledger->dues($booking) : null,
            'payments' => $this->ledger->payments((int) $booking['id']),
            'renewal' => $this->renewals->renewalOf((int) $booking['id']),
            'renewedFrom' => $booking['renewed_from_id'] !== null ? db()->first('SELECT booking_no FROM bookings WHERE id = ? AND customer_id = ?', [(int) $booking['renewed_from_id'], (int) $customer['id']]) : null,
            'canRenew' => in_array($status, [BookingStatus::Confirmed, BookingStatus::Active, BookingStatus::Completed], true) && $booking['start_time'] === null,
            'documents' => $documents->forBooking((int) $booking['id']),
            'allotmentUrl' => BookingDocuments::allotmentAvailable($booking) ? url('portal.bookings.allotment', ['no' => $booking['booking_no']]) : null,
            'today' => $this->clock->today(),
        ]);
    }

    public function cancel(Request $request, string $no, BookingWorkflow $workflow): Response
    {
        $customer = $this->customer();
        $booking = $this->bookings->findByNo($no, (int) $customer['id']) ?? throw new NotFoundException();
        $back = url('portal.bookings.show', ['no' => $no]);
        try {
            $workflow->cancel((int) $booking['id'], Actor::visitor($this->accountId(), (int) $customer['id'], (string) $customer['name']), mb_substr($request->string('reason') ?: 'Cancelled by the visitor', 0, 500));
        } catch (WorkflowException $e) {
            return redirect($back)->with('error', $e->getMessage());
        }
        return redirect($back)->with('success', 'Your booking was cancelled and the seats released.');
    }

    /** Reopen the Space Explorer with this booking's seats and the next dates preselected. */
    public function renew(string $no): Response
    {
        $customer = $this->customer();
        $booking = $this->bookings->findByNo($no, (int) $customer['id']) ?? throw new NotFoundException();
        if ($booking['start_time'] !== null) {
            return redirect(url('spaces.explore'))->with('info', 'Pick a new conference slot on the map.');
        }
        $p = $this->renewals->explorerPreset($booking);
        if ($p['floor'] === null) {
            return redirect(url('spaces.explore'))->with('warning', 'Those seats are no longer in the layout — please pick new ones.');
        }
        return redirect(url('spaces.floor', ['floor' => $p['floor']]) . '?' . http_build_query(['from' => $p['from'], 'to' => $p['to'], 'type' => $p['category'], 'seats' => max(1, count($p['seat_ids'])), 'renew' => $p['booking_no']]));
    }
}
