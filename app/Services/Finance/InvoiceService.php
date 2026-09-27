<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Enums\PaymentRule;
use App\Enums\PaymentStatus;
use App\Services\AuditLog;
use App\Services\Bookings\Actor;
use App\Services\Payments\ConfirmationRule;
use App\Services\Payments\DuesCalculator;
use App\Services\Payments\PaymentLedger;
use App\Support\Clock;
use App\Support\IndianStates;

/**
 * GST tax invoices KDISC/CMN/{FY}/{0001} (spec 6.4, 7.2, 7.3). THE invoicing rules:
 *
 *   advance bookings (≤ 6 months, hourly)   ONE invoice for the booking's charges (seats + add-ons) once VERIFIED
 *                                           payments cover the whole booking amount (source_key "advance")
 *   security-deposit bookings (> 6 months)  ONE invoice per rent_schedules period once verified payments cover that
 *                                           period — allocation as DuesCalculator (deposit first, then periods in
 *                                           order) but counting verified payments only (source_key "rent:{n}")
 *   security deposit                        never invoiced (not a supply until adjusted) — receipt only
 *
 * The "invoice queue" = those eligible sources without an invoice yet (queue()). issue() runs in ONE transaction:
 * lock the booking → re-check eligibility and uniqueness (uq_invoices_source) → build lines from the booking's
 * price snapshot (InvoiceBuilder) → allocate the number under the number_sequences row lock → insert. Invoices are
 * immutable once issued: nothing here updates them except credited_total/status via CreditNoteService (a full
 * credit note marks the invoice cancelled). Place of supply = the customer's state code at issue time.
 */
final class InvoiceService
{
    public function __construct(
        private readonly Database $db,
        private readonly PaymentLedger $ledger,
        private readonly NumberSequence $numbers,
        private readonly FinanceSettings $settings,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Verified-but-not-invoiced sources, oldest verification first.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(?int $bookingId = null): array
    {
        $sql = "SELECT b.*, c.name AS customer_name, c.unique_id, c.state_code AS customer_state, sc.name AS category_name
                FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN seat_categories sc ON sc.id = b.seat_category_id
                WHERE b.status NOT IN ('requested', 'rejected')
                  AND b.id IN (SELECT booking_id FROM payments WHERE status = 'verified')";
        $bind = [];
        if ($bookingId !== null) {
            $sql .= ' AND b.id = ?';
            $bind[] = $bookingId;
        }
        $out = [];
        foreach ($this->db->select($sql . ' ORDER BY b.id', $bind) as $b) {
            foreach ($this->candidates($b) as $c) {
                if (!$c['invoiced']) {
                    $out[] = $c;
                }
            }
        }
        usort($out, static fn (array $a, array $b) => [$a['verified_at'], $a['booking_no'], $a['sort']] <=> [$b['verified_at'], $b['booking_no'], $b['sort']]);
        return $out;
    }

    public function queueCount(): int
    {
        return count($this->queue());
    }

    /**
     * Every invoiceable source of one booking (invoiced or not) — also used by the booking page.
     *
     * @param array<string, mixed> $b bookings row
     * @return list<array<string, mixed>>
     */
    public function candidates(array $b): array
    {
        $bookingId = (int) $b['id'];
        $payments = $this->ledger->payments($bookingId);
        $verified = array_values(array_filter($payments, static fn (array $p) => $p['status'] === PaymentStatus::Verified->value));
        if ($verified === []) {
            return [];
        }
        $lastVerified = max(array_map(static fn (array $p) => (string) $p['verified_at'], $verified));
        $invoiced = [];
        foreach ($this->db->select('SELECT id, source_key, invoice_no FROM invoices WHERE booking_id = ?', [$bookingId]) as $r) {
            $invoiced[(string) $r['source_key']] = $r;
        }
        $schedule = $this->ledger->schedule($b);
        $dues = DuesCalculator::calculate($b + ['status' => 'confirmed'], $schedule, $verified, $this->clock->today());
        $base = [
            'booking_id' => $bookingId,
            'booking_no' => (string) $b['booking_no'],
            'customer_id' => (int) $b['customer_id'],
            'customer_name' => (string) ($b['customer_name'] ?? ''),
            'unique_id' => $b['unique_id'] ?? null,
            'category_name' => (string) ($b['category_name'] ?? ''),
            'booking_status' => (string) $b['status'],
            'inter' => $this->interState($b['customer_state'] ?? null),
            'verified_at' => $lastVerified,
        ];
        $out = [];
        if (($b['payment_rule'] ?? '') !== PaymentRule::SecurityDeposit->value) {
            $total = round((float) $b['grand_total'], 2);
            if ($total > 0 && $dues['rent']['paid'] + ConfirmationRule::TOLERANCE >= $total) {
                $out[] = $base + [
                    'source_key' => 'advance', 'kind' => 'advance', 'sort' => 0,
                    'label' => 'Booking charges (advance)',
                    'period_start' => (string) $b['start_date'], 'period_end' => (string) (($b['original_end_date'] ?? '') ?: $b['end_date']),
                    'taxable' => round((float) $b['subtotal'] + (float) $b['facilities_total'], 2), 'amount' => $total,
                    'rent_schedule_id' => null,
                    'invoiced' => isset($invoiced['advance']), 'invoice' => $invoiced['advance'] ?? null,
                ];
            }
            return $out;
        }
        $count = count($schedule);
        foreach ($dues['schedule'] as $p) {
            if ($p['state'] !== 'paid') {
                continue;
            }
            $key = 'rent:' . (int) $p['period_no'];
            $out[] = $base + [
                'source_key' => $key, 'kind' => 'rent', 'sort' => (int) $p['period_no'],
                'label' => sprintf('Rent period %d of %d', (int) $p['period_no'], $count),
                'period_start' => (string) $p['period_start'], 'period_end' => (string) $p['period_end'],
                'taxable' => round((float) $p['taxable'], 2), 'amount' => round((float) $p['amount'], 2),
                'rent_schedule_id' => (int) $p['id'], 'period_no' => (int) $p['period_no'], 'period_count' => $count,
                'invoiced' => isset($invoiced[$key]), 'invoice' => $invoiced[$key] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Issue the invoice for one eligible source of a booking.
     *
     * @return array<string, mixed> invoices row
     */
    public function issue(int $bookingId, string $sourceKey, Actor $actor): array
    {
        if (!$actor->can('invoices.manage')) {
            throw new FinanceException('Your role cannot issue invoices.', 'permission');
        }
        $invoice = $this->db->transaction(function (Database $db) use ($bookingId, $sourceKey, $actor): array {
            $db->select('SELECT id FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            $b = $db->first(
                'SELECT b.*, c.name AS customer_name, c.unique_id, c.state_code AS customer_state, c.address, c.city, c.pincode, c.gstin, c.pan, c.email AS customer_email,
                        sc.name AS category_name
                 FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN seat_categories sc ON sc.id = b.seat_category_id WHERE b.id = ?',
                [$bookingId],
            ) ?? throw new FinanceException('Booking not found.', 'input');
            $existing = $db->scalar('SELECT invoice_no FROM invoices WHERE booking_id = ? AND source_key = ?', [$bookingId, $sourceKey]);
            if ($existing !== null && $existing !== false) {
                throw new FinanceException(sprintf('Already invoiced — %s.', $existing));
            }
            $source = null;
            foreach ($this->candidates($b) as $c) {
                if ($c['source_key'] === $sourceKey) {
                    $source = $c;
                }
            }
            if ($source === null) {
                throw new FinanceException('Nothing to invoice yet — the payment for this item is not verified in full.');
            }
            $quote = json_decode((string) ($b['quote_json'] ?? ''), true);
            if (!is_array($quote)) {
                throw new FinanceException('This booking has no price snapshot to invoice from.');
            }
            $home = $this->settings->homeState();
            $pos = (string) (($b['customer_state'] ?? '') ?: $home);
            $inter = $pos !== $home;
            $sac = $this->settings->sac();
            $codes = (string) $db->scalar(
                "SELECT GROUP_CONCAT(s.code ORDER BY s.code SEPARATOR ', ') FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id WHERE bs.booking_id = ? AND bs.transferred_to_id IS NULL",
                [$bookingId],
            );
            $lines = $source['kind'] === 'rent'
                ? InvoiceBuilder::rent($quote, ['period_no' => $source['period_no'], 'period_start' => $source['period_start'], 'period_end' => $source['period_end'], 'taxable' => $source['taxable']], (int) $source['period_count'], $inter, $sac, $codes)
                : InvoiceBuilder::advance($quote, $inter, $sac, $codes);
            try {
                $t = InvoiceBuilder::finish($lines, (float) $source['amount']);
            } catch (\RuntimeException $e) {
                throw new FinanceException($e->getMessage() . ' Check the booking before invoicing.');
            }
            $date = $this->clock->today();
            $n = $this->numbers->next(NumberSequence::INVOICE, FinancialYear::of($date));
            $paymentId = $db->scalar("SELECT id FROM payments WHERE booking_id = ? AND status = 'verified' ORDER BY verified_at DESC, id DESC LIMIT 1", [$bookingId]);
            $id = $db->insert('invoices', [
                'invoice_no' => $n['no'],
                'fy' => $n['fy'],
                'seq' => $n['seq'],
                'kind' => $source['kind'],
                'source_key' => $sourceKey,
                'centre_id' => (int) $b['centre_id'],
                'customer_id' => (int) $b['customer_id'],
                'booking_id' => $bookingId,
                'payment_id' => $paymentId !== null && $paymentId !== false ? (int) $paymentId : null,
                'rent_schedule_id' => $source['rent_schedule_id'],
                'invoice_date' => $date,
                'period_start' => $source['period_start'],
                'period_end' => $source['period_end'],
                'booking_no' => (string) $b['booking_no'],
                'customer_name' => mb_substr((string) $b['customer_name'], 0, 190),
                'customer_unique_id' => $b['unique_id'],
                'customer_email' => $b['customer_email'],
                'customer_address' => self::address($b),
                'customer_gstin' => ($b['gstin'] ?? '') !== '' ? strtoupper((string) $b['gstin']) : null,
                'customer_pan' => ($b['pan'] ?? '') !== '' ? strtoupper((string) $b['pan']) : null,
                'customer_state_code' => $pos,
                'place_of_supply' => $pos,
                'taxable_value' => $t['taxable'],
                'cgst' => $t['cgst'],
                'sgst' => $t['sgst'],
                'igst' => $t['igst'],
                'round_off' => $t['round_off'],
                'total' => $t['total'],
                'supplier_json' => json_encode($this->settings->supplier(), JSON_UNESCAPED_UNICODE),
                'amount_words' => AmountInWords::rupees($t['total']),
                'status' => 'issued',
                'issued_by' => $actor->staffId(),
            ]);
            foreach ($lines as $l) {
                $db->insert('invoice_items', ['invoice_id' => $id] + array_intersect_key($l, array_flip(['kind', 'description', 'detail', 'sac', 'qty', 'unit', 'rate', 'taxable_value', 'gst_rate', 'cgst', 'sgst', 'igst', 'total', 'sort_order'])));
            }
            if ($source['kind'] === 'advance') {
                $db->execute("UPDATE receipts SET invoice_id = ? WHERE booking_id = ? AND invoice_id IS NULL AND kind = 'payment'", [$id, $bookingId]);
            }
            $this->audit->record('invoice.issue', 'booking', $bookingId, null, [
                'invoice_id' => $id, 'invoice_no' => $n['no'], 'source' => $sourceKey, 'taxable' => $t['taxable'], 'total' => $t['total'], 'place_of_supply' => $pos,
            ], null, $actor->isStaff() ? 'staff' : 'system', $actor->id);
            return (array) $db->first('SELECT * FROM invoices WHERE id = ?', [$id]);
        });
        logger()->info('Invoice {no} issued for booking {booking}', ['no' => $invoice['invoice_no'], 'booking' => $invoice['booking_no']]);
        return $invoice;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT i.*, s.name AS issued_by_name FROM invoices i LEFT JOIN staff_users s ON s.id = i.issued_by WHERE i.id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function items(int $invoiceId): array
    {
        return $this->db->select('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id', [$invoiceId]);
    }

    public function interState(?string $customerState): bool
    {
        $home = $this->settings->homeState();
        return ($customerState ?? '') !== '' && $customerState !== $home;
    }

    /** @param array<string, mixed> $c customer columns address/city/pincode/customer_state */
    public static function address(array $c): string
    {
        $line2 = trim(implode(' – ', array_filter([(string) ($c['city'] ?? ''), (string) ($c['pincode'] ?? '')])));
        $state = IndianStates::name((string) ($c['customer_state'] ?? $c['state_code'] ?? ''));
        return mb_substr(implode("\n", array_filter([trim((string) ($c['address'] ?? '')), trim($line2 . ($state !== '' ? ', ' . $state : ''), ', ')])), 0, 500);
    }
}
