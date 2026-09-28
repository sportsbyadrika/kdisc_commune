<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Enums\PaymentKind;
use App\Services\AuditLog;
use App\Support\Clock;

/**
 * Payment receipts RCPT/{FY}/{0001} (spec 7.3) — exactly one per VERIFIED payment (unique payment_id), including
 * security deposits (kind "deposit" → printed as a deposit receipt). Issued by PaymentVerificationService inside
 * the verification transaction, so a verified payment never lacks a receipt and numbers have no gaps.
 * The receipt date is the issue date (FY of the number); the payment date is printed separately.
 */
final class ReceiptService
{
    public function __construct(
        private readonly Database $db,
        private readonly NumberSequence $numbers,
        private readonly FinanceSettings $settings,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Issue (or return the existing) receipt of a verified payment. Must run inside a transaction.
     *
     * @return array<string, mixed> receipts row
     */
    public function issueForPayment(int $paymentId, ?int $staffId): array
    {
        $existing = $this->db->first('SELECT * FROM receipts WHERE payment_id = ?', [$paymentId]);
        if ($existing !== null) {
            return $existing;
        }
        $p = $this->db->first(
            'SELECT p.*, c.name AS customer_name FROM payments p JOIN customers c ON c.id = p.customer_id WHERE p.id = ?',
            [$paymentId],
        ) ?? throw new FinanceException('Payment not found.', 'input');
        if ($p['status'] !== 'verified') {
            throw new FinanceException('Receipts are issued for verified payments only.');
        }
        $date = $this->clock->today();
        $n = $this->numbers->next(NumberSequence::RECEIPT, FinancialYear::of($date));
        $invoiceId = $p['kind'] === PaymentKind::Deposit->value ? null
            : $this->db->scalar("SELECT id FROM invoices WHERE booking_id = ? AND source_key = 'advance'", [(int) $p['booking_id']]);
        $id = $this->db->insert('receipts', [
            'receipt_no' => $n['no'],
            'fy' => $n['fy'],
            'seq' => $n['seq'],
            'payment_id' => $paymentId,
            'booking_id' => (int) $p['booking_id'],
            'customer_id' => (int) $p['customer_id'],
            'customer_name' => (string) $p['customer_name'],
            'invoice_id' => $invoiceId !== null && $invoiceId !== false ? (int) $invoiceId : null,
            'kind' => $p['kind'] === PaymentKind::Deposit->value ? 'deposit' : 'payment',
            'amount' => (float) $p['amount'],
            'mode' => (string) $p['mode'],
            'reference_no' => $p['reference_no'],
            'paid_on' => (string) $p['paid_on'],
            'amount_words' => AmountInWords::rupees((float) $p['amount']),
            'supplier_json' => json_encode($this->settings->supplier(), JSON_UNESCAPED_UNICODE),
            'receipt_date' => $date,
            'issued_by' => $staffId,
        ]);
        $this->audit->record('receipt.issue', 'booking', (int) $p['booking_id'], null, ['receipt_id' => $id, 'receipt_no' => $n['no'], 'payment_id' => $paymentId, 'amount' => (float) $p['amount']], null, $staffId !== null ? 'staff' : 'system', $staffId);
        return (array) $this->db->first('SELECT * FROM receipts WHERE id = ?', [$id]);
    }
}
