<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Enums\PaymentMode;
use App\Services\AuditLog;
use App\Services\Bookings\Actor;
use App\Support\Clock;

/**
 * Security deposit refund vouchers DRV/{FY}/{0001} (spec 7.2/7.3) — recorded when a > 6-month booking ends
 * (completed, or cancelled after the deposit was paid). Deposit held = VERIFIED deposit-kind payments;
 * adjustments (unpaid dues, damages, key/locker) are listed with amounts and deducted; the rest is refunded.
 * One voucher per booking (uq_deposit_refunds_booking). Deposits are never invoiced; if an adjustment is a
 * taxable supply (e.g. damages), Finance invoices it separately.
 */
final class DepositRefundService
{
    public function __construct(
        private readonly Database $db,
        private readonly NumberSequence $numbers,
        private readonly FinanceSettings $settings,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /** Verified deposit money held for a booking. */
    public function held(int $bookingId): float
    {
        return round((float) $this->db->scalar("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = ? AND kind = 'deposit' AND status = 'verified'", [$bookingId]), 2);
    }

    /**
     * Bookings whose deposit can be refunded now (ended, deposit held, no voucher yet).
     *
     * @return list<array<string, mixed>>
     */
    public function eligible(): array
    {
        return $this->db->select(
            "SELECT b.id, b.booking_no, b.status, b.end_date, b.deposit_amount, c.name AS customer_name, c.unique_id,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.booking_id = b.id AND p.kind = 'deposit' AND p.status = 'verified') AS held
             FROM bookings b JOIN customers c ON c.id = b.customer_id
             WHERE b.status IN ('completed', 'cancelled') AND b.deposit_amount > 0
               AND NOT EXISTS (SELECT 1 FROM deposit_refunds r WHERE r.booking_id = b.id)
             HAVING held > 0 ORDER BY b.end_date",
        );
    }

    /**
     * @param list<array{label: string, amount: float|string}> $adjustments
     * @param array{mode?: ?string, reference_no?: ?string, notes?: ?string} $payout
     * @return array<string, mixed> deposit_refunds row
     */
    public function issue(int $bookingId, array $adjustments, array $payout, Actor $actor): array
    {
        if (!$actor->can('deposits.refund')) {
            throw new FinanceException('Only Finance can record deposit refunds.', 'permission');
        }
        $clean = [];
        foreach ($adjustments as $a) {
            $label = trim((string) ($a['label'] ?? ''));
            $amount = round((float) ($a['amount'] ?? 0), 2);
            if ($label === '' && $amount == 0.0) {
                continue;
            }
            if ($label === '' || $amount <= 0) {
                throw new FinanceException('Each adjustment needs a description and an amount greater than zero.', 'input');
            }
            $clean[] = ['label' => mb_substr($label, 0, 120), 'amount' => $amount];
        }
        $mode = (string) ($payout['mode'] ?? '');
        if ($mode !== '' && PaymentMode::tryFrom($mode) === null) {
            throw new FinanceException('Choose a valid refund mode.', 'input');
        }
        $row = $this->db->transaction(function (Database $db) use ($bookingId, $clean, $payout, $mode, $actor): array {
            $b = $db->first('SELECT b.*, c.name AS customer_name FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.id = ? FOR UPDATE', [$bookingId])
                ?? throw new FinanceException('Booking not found.', 'input');
            if (!in_array($b['status'], ['completed', 'cancelled'], true)) {
                throw new FinanceException('Deposits are refunded when the booking has ended (completed or cancelled).');
            }
            if ($db->scalar('SELECT id FROM deposit_refunds WHERE booking_id = ?', [$bookingId])) {
                throw new FinanceException('A deposit refund voucher already exists for this booking.');
            }
            $held = $this->held($bookingId);
            if ($held <= 0) {
                throw new FinanceException('No verified security deposit is held for this booking.');
            }
            $adjTotal = round(array_sum(array_column($clean, 'amount')), 2);
            if ($adjTotal > $held + 0.005) {
                throw new FinanceException(sprintf('Adjustments (%s) cannot exceed the deposit held (%s).', money($adjTotal, 2), money($held, 2)), 'input');
            }
            $refund = round($held - $adjTotal, 2);
            if ($refund > 0 && $mode === '') {
                throw new FinanceException('Choose how the refund is paid.', 'input');
            }
            $date = $this->clock->today();
            $n = $this->numbers->next(NumberSequence::REFUND_VOUCHER, FinancialYear::of($date));
            $id = $db->insert('deposit_refunds', [
                'voucher_no' => $n['no'],
                'fy' => $n['fy'],
                'seq' => $n['seq'],
                'booking_id' => $bookingId,
                'customer_id' => (int) $b['customer_id'],
                'customer_name' => (string) $b['customer_name'],
                'voucher_date' => $date,
                'deposit_held' => $held,
                'adjustments_json' => json_encode($clean, JSON_UNESCAPED_UNICODE),
                'adjustments_total' => $adjTotal,
                'refund_amount' => $refund,
                'mode' => $refund > 0 ? $mode : null,
                'reference_no' => ($payout['reference_no'] ?? '') !== '' ? mb_substr((string) $payout['reference_no'], 0, 100) : null,
                'notes' => ($payout['notes'] ?? '') !== '' ? mb_substr((string) $payout['notes'], 0, 500) : null,
                'amount_words' => AmountInWords::rupees($refund),
                'supplier_json' => json_encode($this->settings->supplier(), JSON_UNESCAPED_UNICODE),
                'issued_by' => $actor->staffId(),
            ]);
            $this->audit->record('deposit.refund', 'booking', $bookingId, null, [
                'voucher_id' => $id, 'voucher_no' => $n['no'], 'held' => $held, 'adjustments' => $adjTotal, 'refund' => $refund,
            ], null, 'staff', $actor->id);
            return (array) $db->first('SELECT * FROM deposit_refunds WHERE id = ?', [$id]);
        });
        return $row;
    }
}
