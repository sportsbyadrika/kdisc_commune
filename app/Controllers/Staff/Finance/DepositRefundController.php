<?php

declare(strict_types=1);

namespace App\Controllers\Staff\Finance;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Enums\PaymentMode;
use App\Services\Bookings\Actor;
use App\Services\Bookings\BookingDirectory;
use App\Services\Finance\DepositRefundService;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\FinanceException;
use App\Services\Payments\PaymentLedger;

/** Security deposit refund voucher for an ended > 6-month booking (DepositRefundService). */
final class DepositRefundController extends Controller
{
    public function __construct(
        private readonly DepositRefundService $refunds,
        private readonly BookingDirectory $bookings,
        private readonly PaymentLedger $ledger,
        private readonly FinanceDocuments $docs,
    ) {
    }

    public function create(string $no): Response
    {
        $booking = $this->bookings->findByNo($no) ?? throw new NotFoundException('Booking not found.');
        $dues = $this->ledger->dues($booking);
        return $this->view('staff/finance/deposit-refund', [
            'title' => 'Deposit refund · ' . $no,
            'booking' => $booking,
            'held' => $this->refunds->held((int) $booking['id']),
            'dues' => $dues,
            'existing' => db()->first('SELECT * FROM deposit_refunds WHERE booking_id = ?', [(int) $booking['id']]),
            'modes' => PaymentMode::options(),
        ]);
    }

    public function store(Request $request, string $no): Response
    {
        $booking = $this->bookings->findByNo($no) ?? throw new NotFoundException('Booking not found.');
        $labels = (array) $request->input('adj_label', []);
        $amounts = (array) $request->input('adj_amount', []);
        $adjustments = [];
        foreach ($labels as $i => $label) {
            $adjustments[] = ['label' => (string) $label, 'amount' => (string) ($amounts[$i] ?? '0')];
        }
        try {
            $row = $this->refunds->issue((int) $booking['id'], $adjustments, [
                'mode' => $request->string('mode'), 'reference_no' => $request->string('reference_no'), 'notes' => $request->string('notes'),
            ], Actor::staff((array) staff()));
        } catch (FinanceException $e) {
            return redirect(url('staff.deposits.create', ['no' => $no]))->with('error', $e->getMessage())->withInput();
        }
        $this->docs->issued('deposit_refund', (int) $row['id']);
        return redirect(url('staff.invoices.index', ['tab' => 'deposits']))->with('success', sprintf('Deposit refund voucher %s recorded — %s refunded.', $row['voucher_no'], money($row['refund_amount'], 2)));
    }
}
