<?php

declare(strict_types=1);

namespace App\Controllers\Staff\Finance;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Enums\PaymentKind;
use App\Enums\PaymentMode;
use App\Services\Bookings\Actor;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\FinanceException;
use App\Services\Finance\PaymentVerificationService;

/**
 * Finance payment verification queue (/staff/finance/payments): filters, proof viewer, verify (issues the receipt),
 * bulk verify, query back to the front desk; the front desk answers a query from the booking page (reply).
 * Void stays with the Centre Manager (PaymentController::void).
 */
final class PaymentVerificationController extends Controller
{
    public function __construct(private readonly PaymentVerificationService $verification, private readonly FinanceDocuments $docs)
    {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'status' => in_array($request->string('status'), ['pending', 'queried', 'verified', 'void', 'all'], true) ? $request->string('status') : 'pending',
            'mode' => array_key_exists($request->string('mode'), PaymentMode::options()) ? $request->string('mode') : '',
            'kind' => array_key_exists($request->string('kind'), PaymentKind::options()) ? $request->string('kind') : '',
            'q' => mb_substr($request->string('q'), 0, 100),
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->string('from')) ? $request->string('from') : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->string('to')) ? $request->string('to') : '',
            'sort' => array_key_exists($request->string('sort'), PaymentVerificationService::SORTS) ? $request->string('sort') : 'oldest',
        ];
        $user = (array) staff();
        return $this->view('staff/finance/payments', [
            'title' => 'Payment verification',
            'filters' => $filters,
            'result' => $this->verification->queue($filters, max(1, $request->int('page', 1))),
            'counts' => $this->verification->counts(),
            'canVerify' => Actor::staff($user)->can('payments.verify'),
            'canVoid' => Actor::staff($user)->can('payments.void'),
        ]);
    }

    public function verify(Request $request, int $id): Response
    {
        try {
            $r = $this->verification->verify($id, Actor::staff((array) staff()), $request->string('note'));
        } catch (FinanceException $e) {
            return back(url('staff.payments.index'))->with('error', $e->getMessage());
        }
        $this->docs->issued('receipt', (int) $r['receipt']['id']);
        return back(url('staff.payments.index'))->with('success', sprintf('Payment of %s verified — receipt %s issued%s.', money($r['payment']['amount'], 2), $r['receipt']['receipt_no'], setting('finance_email_documents', true) ? ' and emailed' : ''));
    }

    public function bulk(Request $request): Response
    {
        $ids = array_map('intval', (array) $request->input('ids', []));
        if ($ids === []) {
            return back(url('staff.payments.index'))->with('warning', 'Tick the payments to verify first.');
        }
        $r = $this->verification->bulkVerify($ids, Actor::staff((array) staff()));
        foreach ($r['verified'] as $v) {
            $this->docs->issued('receipt', (int) $v['receipt']['id']);
        }
        $msg = sprintf('%d payment%s verified and receipted.', count($r['verified']), count($r['verified']) === 1 ? '' : 's');
        if ($r['failed'] !== []) {
            return back(url('staff.payments.index'))->with('warning', $msg . ' Skipped: ' . implode(' ', array_map(static fn ($id, $m) => "#{$id}: {$m}", array_keys($r['failed']), $r['failed'])));
        }
        return back(url('staff.payments.index'))->with('success', $msg);
    }

    public function query(Request $request, int $id): Response
    {
        try {
            $this->verification->query($id, Actor::staff((array) staff()), $request->string('note'));
        } catch (FinanceException $e) {
            return back(url('staff.payments.index'))->with('error', $e->getMessage());
        }
        return back(url('staff.payments.index'))->with('success', 'Query sent to the front desk. The payment stays in the queue, marked “queried”.');
    }

    public function reply(Request $request, int $id): Response
    {
        $no = (string) db()->scalar('SELECT b.booking_no FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ?', [$id]);
        $back = $no !== '' ? url('staff.bookings.show', ['no' => $no]) . '#payments' : url('staff.dashboard');
        try {
            $this->verification->resolve($id, Actor::staff((array) staff()), $request->string('reply'));
        } catch (FinanceException $e) {
            return redirect($back)->with('error', $e->getMessage());
        }
        return redirect($back)->with('success', 'Reply sent to Finance.');
    }
}
