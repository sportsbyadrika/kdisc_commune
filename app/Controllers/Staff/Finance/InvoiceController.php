<?php

declare(strict_types=1);

namespace App\Controllers\Staff\Finance;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Enums\CreditNoteReason;
use App\Services\Bookings\Actor;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\DepositRefundService;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\FinanceException;
use App\Services\Finance\FinancialYear;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentVerificationService;
use App\Support\Clock;

/**
 * Finance documents hub (/staff/finance/invoices?tab=queue|invoices|receipts|credit-notes|deposits): the invoice
 * queue (verified, not yet invoiced) with issue / issue all, issued invoices, receipts, credit notes and deposit
 * refunds; invoice detail with its credit notes and the credit-note form. Rules live in the Finance services.
 */
final class InvoiceController extends Controller
{
    public const TABS = [
        'queue' => ['Invoice queue', 'inbox'],
        'invoices' => ['Invoices', 'receipt-indian-rupee'],
        'receipts' => ['Receipts', 'receipt'],
        'credit-notes' => ['Credit notes', 'file-minus'],
        'deposits' => ['Deposits', 'piggy-bank'],
    ];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly CreditNoteService $creditNotes,
        private readonly DepositRefundService $refunds,
        private readonly FinanceDocuments $docs,
        private readonly PaymentVerificationService $verification,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        $tab = array_key_exists($request->string('tab'), self::TABS) ? $request->string('tab') : 'queue';
        $today = $this->clock->today();
        $fys = FinancialYear::recent($today, 4);
        $fy = in_array($request->string('fy'), $fys, true) ? $request->string('fy') : $fys[0];
        $q = mb_substr(trim($request->string('q')), 0, 100);
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $search = static fn (string $cols) => $q === '' ? '' : ' AND (' . implode(' OR ', array_map(static fn (string $c) => "{$c} LIKE ?", explode(',', $cols))) . ')';
        $bind = static fn (int $n) => $q === '' ? [] : array_fill(0, $n, $like);
        $queue = $this->invoices->queue();
        $rows = match ($tab) {
            'queue' => $queue,
            'invoices' => db()->select('SELECT * FROM invoices WHERE fy = ?' . $search('invoice_no,customer_name,booking_no,customer_gstin') . ' ORDER BY seq DESC', [$fy, ...$bind(4)]),
            'receipts' => db()->select('SELECT r.*, b.booking_no FROM receipts r LEFT JOIN bookings b ON b.id = r.booking_id WHERE r.fy = ?' . $search('r.receipt_no,r.customer_name,b.booking_no,r.reference_no') . ' ORDER BY r.seq DESC', [$fy, ...$bind(4)]),
            'credit-notes' => db()->select('SELECT cn.*, i.invoice_no FROM credit_notes cn JOIN invoices i ON i.id = cn.invoice_id WHERE cn.fy = ?' . $search('cn.credit_note_no,cn.customer_name,i.invoice_no') . ' ORDER BY cn.seq DESC', [$fy, ...$bind(3)]),
            default => db()->select('SELECT d.*, b.booking_no FROM deposit_refunds d JOIN bookings b ON b.id = d.booking_id WHERE d.fy = ?' . $search('d.voucher_no,d.customer_name,b.booking_no') . ' ORDER BY d.seq DESC', [$fy, ...$bind(3)]),
        };
        return $this->view('staff/finance/invoices', [
            'title' => 'Invoices & receipts',
            'tab' => $tab,
            'fy' => $fy,
            'fys' => $fys,
            'q' => $q,
            'rows' => $rows,
            'queueCount' => count($queue),
            'eligibleRefunds' => $tab === 'deposits' ? $this->refunds->eligible() : [],
            'pendingPayments' => $this->verification->counts()['pending'],
            'canManage' => Actor::staff((array) staff())->can('invoices.manage'),
            'canRefund' => Actor::staff((array) staff())->can('deposits.refund'),
        ]);
    }

    /** Issue one queued invoice (booking_id + source_key) or every queued one (all=1). */
    public function store(Request $request): Response
    {
        $actor = Actor::staff((array) staff());
        $back = url('staff.invoices.index', ['tab' => 'queue']);
        $targets = $request->bool('all')
            ? array_map(static fn (array $c) => [$c['booking_id'], $c['source_key']], $this->invoices->queue())
            : [[$request->int('booking_id'), $request->string('source_key')]];
        $issued = [];
        $errors = [];
        foreach ($targets as [$bookingId, $key]) {
            try {
                $inv = $this->invoices->issue((int) $bookingId, (string) $key, $actor);
                $this->docs->issued('invoice', (int) $inv['id']);
                $issued[] = $inv;
            } catch (FinanceException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($issued === [] && $errors !== []) {
            return redirect($back)->with('error', implode(' ', $errors));
        }
        if (count($issued) === 1 && !$request->bool('all')) {
            return redirect(url('staff.invoices.show', ['id' => $issued[0]['id']]))->with('success', sprintf('Invoice %s issued for %s%s.', $issued[0]['invoice_no'], money($issued[0]['total'], 2), setting('finance_email_documents', true) ? ' and emailed to the visitor' : ''));
        }
        $msg = sprintf('%d invoice%s issued (%s).', count($issued), count($issued) === 1 ? '' : 's', implode(', ', array_column($issued, 'invoice_no')));
        return redirect($back)->with($errors === [] ? 'success' : 'warning', $msg . ($errors !== [] ? ' ' . implode(' ', $errors) : ''));
    }

    public function show(int $id): Response
    {
        $invoice = $this->invoices->find($id) ?? throw new NotFoundException('Invoice not found.');
        return $this->view('staff/finance/invoice', [
            'title' => 'Invoice ' . $invoice['invoice_no'],
            'invoice' => $invoice,
            'items' => $this->invoices->items($id),
            'creditNotes' => db()->select('SELECT cn.*, s.name AS issued_by_name FROM credit_notes cn LEFT JOIN staff_users s ON s.id = cn.issued_by WHERE cn.invoice_id = ? ORDER BY cn.id', [$id]),
            'receipts' => $invoice['booking_id'] !== null ? db()->select('SELECT * FROM receipts WHERE booking_id = ? ORDER BY id', [(int) $invoice['booking_id']]) : [],
            'remaining' => $this->creditNotes->remaining($id),
            'suggestion' => $this->creditNotes->suggest($invoice),
            'reasons' => CreditNoteReason::options(),
            'canCredit' => Actor::staff((array) staff())->can('credit_notes.manage'),
            'canManage' => Actor::staff((array) staff())->can('invoices.manage'),
        ]);
    }

    public function creditNote(Request $request, int $id): Response
    {
        $back = url('staff.invoices.show', ['id' => $id]);
        $data = $this->validate($request, [
            'reason_code' => ['required', CreditNoteReason::rule()],
            'reason' => 'required|string|min:5|max:500',
            'scope' => 'required|in:full,partial',
            'taxable' => 'nullable|numeric|min:0.01|max:99999999',
        ], [], ['reason_code' => 'reason', 'reason' => 'explanation', 'taxable' => 'taxable amount']);
        try {
            $cn = $this->creditNotes->issue($id, CreditNoteReason::from((string) $data['reason_code']), (string) $data['reason'], $data['scope'] === 'full' ? null : (float) ($data['taxable'] ?? 0), Actor::staff((array) staff()));
        } catch (FinanceException $e) {
            return redirect($back)->with('error', $e->getMessage())->withInput();
        }
        $this->docs->issued('credit_note', (int) $cn['id']);
        return redirect($back)->with('success', sprintf('Credit note %s issued for %s.', $cn['credit_note_no'], money($cn['total'], 2)));
    }
}
