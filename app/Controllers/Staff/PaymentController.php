<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditLog;
use App\Services\Bookings\Actor;
use App\Services\Bookings\BookingDirectory;
use App\Services\Bookings\WorkflowException;
use App\Services\Kyc\DocumentStore;
use App\Services\Payments\PaymentService;

/**
 * Payment logging at the front desk (payments.log), voiding (payments.void, Centre Manager) and the proof file
 * viewer. Rules: PaymentService; confirmation: ConfirmationRule via BookingWorkflow::confirmIfReady().
 */
final class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments, private readonly BookingDirectory $bookings)
    {
    }

    public function store(Request $request, string $no): Response
    {
        $booking = $this->bookings->findByNo($no) ?? throw new NotFoundException('Booking not found.');
        $data = $this->validate($request, PaymentService::rules(), [], ['reference_no' => 'reference', 'paid_on' => 'payment date']);
        $back = url('staff.bookings.show', ['no' => $no]);
        try {
            $r = $this->payments->log($booking, $data, Actor::staff((array) staff()), $request->file('proof'));
        } catch (WorkflowException $e) {
            return redirect($back)->with('error', $e->getMessage())->withInput();
        }
        $msg = sprintf('Payment of %s logged (%s).', money($r['payment']['amount'], 2), strtoupper((string) $r['payment']['mode']) === 'CASH' ? 'cash' : ($r['payment']['reference_no'] ?? ''));
        if ($r['confirmed']) {
            $msg .= ' The confirmation requirement is met — booking CONFIRMED and seats allotted.';
        } elseif ($booking['status'] === 'approved' && $r['dues']['confirmation_met'] && ($booking['kyc_status'] ?? '') !== 'verified') {
            $msg .= ' Payment complete, but KYC is not verified yet — confirm the booking once KYC is approved.';
        } elseif ($booking['status'] === 'approved') {
            $left = max(0.0, $r['dues']['requirement']['total'] - $r['dues']['paid']);
            $msg .= sprintf(' %s more is needed to confirm.', money($left, 2));
        }
        return redirect($back)->with('success', $msg);
    }

    public function void(Request $request, int $id): Response
    {
        $payment = db()->first('SELECT p.*, b.booking_no FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ?', [$id]) ?? throw new NotFoundException();
        $back = url('staff.bookings.show', ['no' => $payment['booking_no']]);
        try {
            $this->payments->void($id, Actor::staff((array) staff()), $request->string('reason'));
        } catch (WorkflowException $e) {
            return redirect($back)->with('error', $e->getMessage());
        }
        return redirect($back)->with('success', sprintf('Payment of %s voided. It stays on record with the reason.', money($payment['amount'], 2)));
    }

    public function proof(int $id, AuditLog $audit): Response
    {
        $payment = db()->first('SELECT * FROM payments WHERE id = ? AND proof_path IS NOT NULL', [$id]) ?? throw new NotFoundException();
        $audit->record('payment.proof.view', 'booking', (int) $payment['booking_id'], null, ['payment_id' => $id]);
        return Response::file($this->payments->proofPath($payment), 'payment-' . $id . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $payment['proof_name']), (string) $payment['proof_mime'])
            ->header('Cache-Control', 'private, no-store')->header('Content-Security-Policy', DocumentStore::FILE_CSP);
    }
}
