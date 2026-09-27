<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Enums\CreditNoteReason;
use App\Services\AuditLog;
use App\Services\Bookings\Actor;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Credit notes CN/{FY}/{0001} against a GST invoice (spec 6.4) — the ONLY way to reduce or cancel an issued
 * invoice (invoices are immutable). Reasons: cancellation, early exit, handover price difference, discount.
 *
 *   full     every remaining line is reversed exactly (taxable + the tax actually charged, + round-off) — the
 *            invoice is then marked cancelled.
 *   partial  a taxable amount is spread over the invoice lines in proportion to what is still creditable
 *            (GstMath::allocate) and GST is reversed at each line's rate for the invoice's place of supply
 *            (same CGST/SGST vs IGST split as the invoice).
 *
 * Limits: the credited taxable value can never exceed the invoice's remaining taxable value, and the credited
 * total can never exceed the invoice total (checked under a row lock on the invoice). Numbers are allocated in
 * the same transaction (NumberSequence). suggest() proposes the early-exit amount (unused days of the period).
 */
final class CreditNoteService
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
     * What is left to credit on an invoice.
     *
     * @return array{taxable: float, total: float, items: list<array<string, mixed>>}
     */
    public function remaining(int $invoiceId): array
    {
        $inv = $this->db->first('SELECT * FROM invoices WHERE id = ?', [$invoiceId]) ?? throw new FinanceException('Invoice not found.', 'input');
        $credited = [];
        foreach ($this->db->select(
            'SELECT cni.invoice_item_id, SUM(cni.taxable_value) AS taxable, SUM(cni.cgst) AS cgst, SUM(cni.sgst) AS sgst, SUM(cni.igst) AS igst
             FROM credit_note_items cni JOIN credit_notes cn ON cn.id = cni.credit_note_id WHERE cn.invoice_id = ? GROUP BY cni.invoice_item_id',
            [$invoiceId],
        ) as $r) {
            $credited[(int) $r['invoice_item_id']] = $r;
        }
        $items = [];
        foreach ($this->db->select('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id', [$invoiceId]) as $it) {
            $c = $credited[(int) $it['id']] ?? ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
            $it['left_taxable'] = round((float) $it['taxable_value'] - (float) $c['taxable'], 2);
            foreach (['cgst', 'sgst', 'igst'] as $k) {
                $it['left_' . $k] = round((float) $it[$k] - (float) $c[$k], 2);
            }
            $items[] = $it;
        }
        return [
            'taxable' => round(array_sum(array_column($items, 'left_taxable')), 2),
            'total' => round((float) $inv['total'] - (float) $inv['credited_total'], 2),
            'items' => $items,
        ];
    }

    /**
     * @param float|null $taxable taxable value to credit; null = everything that is left (full credit note)
     * @return array<string, mixed> credit_notes row
     */
    public function issue(int $invoiceId, CreditNoteReason $reason, string $note, ?float $taxable, Actor $actor): array
    {
        if (!$actor->can('credit_notes.manage')) {
            throw new FinanceException('Your role cannot issue credit notes.', 'permission');
        }
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new FinanceException('Describe the reason for the credit note (at least 5 characters).', 'input');
        }
        $cn = $this->db->transaction(function (Database $db) use ($invoiceId, $reason, $note, $taxable, $actor): array {
            $inv = $db->first('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]) ?? throw new FinanceException('Invoice not found.', 'input');
            $left = $this->remaining($invoiceId);
            if ($left['taxable'] <= 0.004 || $left['total'] <= 0.004) {
                throw new FinanceException(sprintf('Invoice %s is already fully credited.', $inv['invoice_no']));
            }
            $full = $taxable === null || abs($taxable - $left['taxable']) < 0.005;
            if (!$full) {
                $taxable = round((float) $taxable, 2);
                if ($taxable <= 0) {
                    throw new FinanceException('Enter a taxable amount greater than zero.', 'input');
                }
                if ($taxable > $left['taxable']) {
                    throw new FinanceException(sprintf('A credit note cannot exceed the invoice: at most %s taxable value is left to credit on %s.', money($left['taxable'], 2), $inv['invoice_no']), 'input');
                }
            }
            $supplier = json_decode((string) ($inv['supplier_json'] ?? ''), true);
            $home = is_array($supplier) && !empty($supplier['state_code']) ? (string) $supplier['state_code'] : $this->settings->homeState();
            $inter = (string) $inv['place_of_supply'] !== $home;
            $items = array_values(array_filter($left['items'], static fn (array $it) => $it['left_taxable'] > 0.004));
            $shares = $full ? array_column($items, 'left_taxable') : GstMath::allocate(array_map(static fn (array $it) => (float) $it['left_taxable'], $items), (float) $taxable);
            $lines = [];
            foreach ($items as $i => $it) {
                $share = round((float) $shares[$i], 2);
                if ($share <= 0) {
                    continue;
                }
                if ($full) {
                    $tax = ['cgst' => $it['left_cgst'], 'sgst' => $it['left_sgst'], 'igst' => $it['left_igst']];
                } else {
                    $t = GstMath::split($share, (float) $it['gst_rate'], $inter);
                    $tax = ['cgst' => min($t['cgst'], $it['left_cgst']), 'sgst' => min($t['sgst'], $it['left_sgst']), 'igst' => min($t['igst'], $it['left_igst'])];
                }
                $lines[] = [
                    'invoice_item_id' => (int) $it['id'],
                    'description' => mb_substr((string) $it['description'], 0, 255),
                    'sac' => (string) $it['sac'],
                    'taxable_value' => $share,
                    'gst_rate' => (float) $it['gst_rate'],
                    'cgst' => round((float) $tax['cgst'], 2),
                    'sgst' => round((float) $tax['sgst'], 2),
                    'igst' => round((float) $tax['igst'], 2),
                    'total' => round($share + (float) $tax['cgst'] + (float) $tax['sgst'] + (float) $tax['igst'], 2),
                    'sort_order' => $i,
                ];
            }
            $t = GstMath::totals($lines);
            $roundOff = 0.0;
            if ($full) {
                $creditedRound = (float) $db->scalar('SELECT COALESCE(SUM(round_off), 0) FROM credit_notes WHERE invoice_id = ?', [$invoiceId]);
                $roundOff = round((float) $inv['round_off'] - $creditedRound, 2);
            }
            $total = round($t['total'] + $roundOff, 2);
            if ($total > $left['total'] + 0.005) {
                throw new FinanceException(sprintf('A credit note cannot exceed the invoice: at most %s is left on %s.', money($left['total'], 2), $inv['invoice_no']), 'input');
            }
            $date = $this->clock->today();
            $n = $this->numbers->next(NumberSequence::CREDIT_NOTE, FinancialYear::of($date));
            $id = $db->insert('credit_notes', [
                'credit_note_no' => $n['no'],
                'fy' => $n['fy'],
                'seq' => $n['seq'],
                'invoice_id' => $invoiceId,
                'booking_id' => $inv['booking_id'],
                'customer_id' => (int) $inv['customer_id'],
                'customer_name' => (string) $inv['customer_name'],
                'note_date' => $date,
                'reason_code' => $reason->value,
                'reason' => mb_substr($note, 0, 500),
                'place_of_supply' => (string) $inv['place_of_supply'],
                'taxable_value' => $t['taxable'],
                'cgst' => $t['cgst'],
                'sgst' => $t['sgst'],
                'igst' => $t['igst'],
                'round_off' => $roundOff,
                'total' => $total,
                'amount_words' => AmountInWords::rupees($total),
                'supplier_json' => json_encode($this->settings->supplier(), JSON_UNESCAPED_UNICODE),
                'issued_by' => $actor->staffId(),
            ]);
            foreach ($lines as $l) {
                $db->insert('credit_note_items', ['credit_note_id' => $id] + $l);
            }
            $credited = round((float) $inv['credited_total'] + $total, 2);
            $db->update('invoices', [
                'credited_total' => $credited,
                'status' => $credited + 0.005 >= (float) $inv['total'] ? 'cancelled' : (string) $inv['status'],
            ], ['id' => $invoiceId]);
            $this->audit->record('credit_note.issue', 'booking', $inv['booking_id'] !== null ? (int) $inv['booking_id'] : null, ['invoice_no' => $inv['invoice_no'], 'credited_total' => (float) $inv['credited_total']], [
                'credit_note_id' => $id, 'credit_note_no' => $n['no'], 'reason' => $reason->value, 'taxable' => $t['taxable'], 'total' => $total, 'full' => $full,
            ], $note, $actor->isStaff() ? 'staff' : 'system', $actor->id);
            return (array) $db->first('SELECT * FROM credit_notes WHERE id = ?', [$id]);
        });
        logger()->info('Credit note {no} issued against invoice #{invoice}', ['no' => $cn['credit_note_no'], 'invoice' => $invoiceId]);
        return $cn;
    }

    /**
     * Suggested credit for an invoice: early exit → the unused days of the invoiced period (pro-rata taxable);
     * cancelled booking → everything left.
     *
     * @param array<string, mixed> $invoice
     * @return array{reason: string, taxable: float, explain: string, note: string}|null
     */
    public function suggest(array $invoice): ?array
    {
        if ($invoice['booking_id'] === null) {
            return null;
        }
        $b = $this->db->first('SELECT * FROM bookings WHERE id = ?', [(int) $invoice['booking_id']]);
        $left = $this->remaining((int) $invoice['id']);
        if ($b === null || $left['taxable'] <= 0) {
            return null;
        }
        if ($b['status'] === 'cancelled') {
            return ['reason' => CreditNoteReason::Cancellation->value, 'taxable' => $left['taxable'], 'explain' => 'The booking was cancelled — credit everything that is left on this invoice.', 'note' => 'Booking ' . $b['booking_no'] . ' cancelled' . ($b['cancel_reason'] ? ': ' . $b['cancel_reason'] : '') . '.'];
        }
        if (empty($b['original_end_date']) || empty($invoice['period_start']) || empty($invoice['period_end'])) {
            return null;
        }
        $start = new DateTimeImmutable((string) $invoice['period_start']);
        $end = new DateTimeImmutable((string) $invoice['period_end']);
        $last = new DateTimeImmutable((string) $b['end_date']);
        if ($last >= $end) {
            return null;
        }
        $days = (int) $start->diff($end)->days + 1;
        $unused = $last < $start ? $days : (int) $last->diff($end)->days;
        $taxable = min($left['taxable'], round((float) $invoice['taxable_value'] * $unused / max(1, $days), 2));
        return [
            'reason' => CreditNoteReason::EarlyExit->value,
            'taxable' => $taxable,
            'explain' => sprintf('Early exit: the booking now ends on %s — %d of the %d invoiced days (%s – %s) are unused, pro-rata taxable value %s.',
                format_date((string) $b['end_date']), $unused, $days, format_date((string) $invoice['period_start']), format_date((string) $invoice['period_end']), money($taxable, 2)),
            'note' => sprintf('Early exit — booking %s now ends on %s; %d of %d invoiced days unused (%s – %s) credited pro rata.',
                $b['booking_no'], format_date((string) $b['end_date']), $unused, $days, format_date((new DateTimeImmutable((string) $b['end_date']))->modify('+1 day')->format('Y-m-d')), format_date((string) $invoice['period_end'])),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function items(int $creditNoteId): array
    {
        return $this->db->select('SELECT * FROM credit_note_items WHERE credit_note_id = ? ORDER BY sort_order, id', [$creditNoteId]);
    }
}
