<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Enums\BookingStatus;
use App\Enums\CreditNoteReason;
use App\Enums\PaymentKind;
use App\Enums\PaymentMode;
use App\Services\Payments\PaymentLedger;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Read-only finance figures for the Finance dashboard and the registers (spec 6.4) — the reusable query layer for
 * the reporting / XLSX batch. Every method takes an explicit date range (use FinancialYear::range()).
 *
 *   collected       money received (logged + verified payments) by payment date; `verified` = Finance-checked part
 *   due             money that fell due: advance bookings (grand total) and deposits on their pay-by date (or start
 *                   date), rent periods on their due date — for approved / confirmed / active / completed bookings
 *   revenue         taxable value invoiced, net of credit notes — by seat category and by add-on (facility)
 *   gst             CGST / SGST / IGST on invoices minus credit notes (by document date)
 *   registers       invoice / receipt / credit-note / deposit / outstanding — rows + column spec + totals, rendered
 *                   by staff/finance/registers (HTML) and pdf/register (PDF)
 */
final class FinanceReportService
{
    private const LIVE = ['approved', 'confirmed', 'active', 'completed'];

    public function __construct(
        private readonly Database $db,
        private readonly PaymentLedger $ledger,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{total: float, verified: float, deposits: float, count: int} */
    public function collected(string $from, string $to): array
    {
        $r = (array) $this->db->first(
            "SELECT COALESCE(SUM(amount), 0) AS total, COALESCE(SUM(CASE WHEN status = 'verified' THEN amount END), 0) AS verified,
                    COALESCE(SUM(CASE WHEN kind = 'deposit' THEN amount END), 0) AS deposits, COUNT(*) AS n
             FROM payments WHERE status IN ('logged', 'verified') AND paid_on BETWEEN ? AND ?",
            [$from, $to],
        );
        return ['total' => round((float) $r['total'], 2), 'verified' => round((float) $r['verified'], 2), 'deposits' => round((float) $r['deposits'], 2), 'count' => (int) $r['n']];
    }

    public function due(string $from, string $to): float
    {
        $in = implode(',', array_fill(0, count(self::LIVE), '?'));
        $advance = (float) $this->db->scalar(
            "SELECT COALESCE(SUM(grand_total), 0) FROM bookings WHERE payment_rule = 'advance' AND status IN ({$in}) AND COALESCE(payment_due_by, start_date) BETWEEN ? AND ?",
            [...self::LIVE, $from, $to],
        );
        $deposits = (float) $this->db->scalar(
            "SELECT COALESCE(SUM(deposit_amount), 0) FROM bookings WHERE payment_rule = 'security_deposit' AND status IN ({$in}) AND COALESCE(payment_due_by, start_date) BETWEEN ? AND ?",
            [...self::LIVE, $from, $to],
        );
        $rent = (float) $this->db->scalar(
            "SELECT COALESCE(SUM(r.amount), 0) FROM rent_schedules r JOIN bookings b ON b.id = r.booking_id
             WHERE r.status = 'open' AND b.status IN ({$in}) AND r.due_on BETWEEN ? AND ?",
            [...self::LIVE, $from, $to],
        );
        return round($advance + $deposits + $rent, 2);
    }

    /**
     * Month-by-month due vs collected for a financial year (up to two months ahead, so upcoming dues show).
     *
     * @return list<array{month: string, label: string, due: float, collected: float, verified: float}>
     */
    public function monthly(string $fy): array
    {
        [$start, $end] = FinancialYear::range($fy);
        $today = $this->clock->today();
        $out = [];
        for ($m = new DateTimeImmutable($start); $m->format('Y-m-d') <= $end; $m = $m->modify('first day of next month')) {
            if ($m->format('Y-m-d') > (new DateTimeImmutable($today))->modify('first day of +2 months')->format('Y-m-d')) {
                break;
            }
            $from = $m->format('Y-m-01');
            $to = $m->format('Y-m-t');
            $c = $this->collected($from, $to);
            $out[] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'due' => $this->due($from, $to), 'collected' => $c['total'], 'verified' => $c['verified']];
        }
        return $out;
    }

    /** @return array{cgst: float, sgst: float, igst: float, total: float, taxable: float, invoices: int} */
    public function gst(string $from, string $to): array
    {
        $i = (array) $this->db->first('SELECT COALESCE(SUM(taxable_value),0) t, COALESCE(SUM(cgst),0) c, COALESCE(SUM(sgst),0) s, COALESCE(SUM(igst),0) g, COUNT(*) n FROM invoices WHERE invoice_date BETWEEN ? AND ?', [$from, $to]);
        $n = (array) $this->db->first('SELECT COALESCE(SUM(taxable_value),0) t, COALESCE(SUM(cgst),0) c, COALESCE(SUM(sgst),0) s, COALESCE(SUM(igst),0) g FROM credit_notes WHERE note_date BETWEEN ? AND ?', [$from, $to]);
        $cgst = round((float) $i['c'] - (float) $n['c'], 2);
        $sgst = round((float) $i['s'] - (float) $n['s'], 2);
        $igst = round((float) $i['g'] - (float) $n['g'], 2);
        return ['cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'total' => round($cgst + $sgst + $igst, 2), 'taxable' => round((float) $i['t'] - (float) $n['t'], 2), 'invoices' => (int) $i['n']];
    }

    /**
     * Net taxable revenue by seat category (seat lines) for invoices dated in the range.
     *
     * @return list<array{label: string, amount: float}>
     */
    public function revenueByCategory(string $from, string $to): array
    {
        return $this->netRevenue(
            "SELECT sc.name AS label, SUM(ii.taxable_value) AS gross,
                    COALESCE(SUM((SELECT SUM(cni.taxable_value) FROM credit_note_items cni WHERE cni.invoice_item_id = ii.id)), 0) AS credited
             FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id JOIN bookings b ON b.id = i.booking_id JOIN seat_categories sc ON sc.id = b.seat_category_id
             WHERE ii.kind = 'seat' AND i.invoice_date BETWEEN ? AND ? GROUP BY sc.id, sc.name, sc.sort_order ORDER BY sc.sort_order",
            [$from, $to],
        );
    }

    /**
     * Net taxable add-on revenue by facility.
     *
     * @return list<array{label: string, amount: float}>
     */
    public function revenueByFacility(string $from, string $to): array
    {
        return $this->netRevenue(
            "SELECT REPLACE(ii.description, 'Add-on: ', '') AS label, SUM(ii.taxable_value) AS gross,
                    COALESCE(SUM((SELECT SUM(cni.taxable_value) FROM credit_note_items cni WHERE cni.invoice_item_id = ii.id)), 0) AS credited
             FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id
             WHERE ii.kind = 'addon' AND i.invoice_date BETWEEN ? AND ? GROUP BY label ORDER BY gross DESC",
            [$from, $to],
        );
    }

    /** Verified security deposits not yet settled by a refund voucher. */
    public function depositsHeld(): float
    {
        return round((float) $this->db->scalar(
            "SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.kind = 'deposit' AND p.status = 'verified'
               AND NOT EXISTS (SELECT 1 FROM deposit_refunds d WHERE d.booking_id = p.booking_id)",
        ), 2);
    }

    /** @return array{due_now: float, balance: float, customers: int} */
    public function outstandingTotals(): array
    {
        $rows = $this->outstandingRows();
        return [
            'due_now' => round(array_sum(array_column($rows, 'due_now')), 2),
            'balance' => round(array_sum(array_column($rows, 'balance')), 2),
            'customers' => count(array_unique(array_column($rows, 'customer_id'))),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recentInvoices(int $limit = 8): array
    {
        return $this->db->select('SELECT * FROM invoices ORDER BY id DESC LIMIT ' . max(1, $limit));
    }

    // ------------------------------------------------------------------ registers

    public const REGISTERS = [
        'invoices' => ['Invoice register', 'receipt-indian-rupee'],
        'receipts' => ['Receipt register', 'receipt'],
        'credit-notes' => ['Credit note register', 'file-minus'],
        'deposits' => ['Deposit register', 'piggy-bank'],
        'outstanding' => ['Outstanding dues', 'hourglass'],
    ];

    /**
     * @return array{title: string, subtitle: string, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function register(string $type, string $from, string $to): array
    {
        $range = format_date($from) . ' – ' . format_date($to);
        switch ($type) {
            case 'invoices':
                $rows = $this->db->select('SELECT * FROM invoices WHERE invoice_date BETWEEN ? AND ? ORDER BY fy, seq', [$from, $to]);
                foreach ($rows as &$r) {
                    $r['status_label'] = $r['status'] === 'cancelled' ? 'Cancelled (credited)' : ((float) $r['credited_total'] > 0 ? 'Part credited' : 'Issued');
                    $r['gstin'] = $r['customer_gstin'] ?: 'B2C';
                }
                unset($r);
                return $this->spec('Invoice register', $range, [
                    ['invoice_no', 'Invoice no.', 'mono'], ['invoice_date', 'Date', 'date'], ['customer_name', 'Recipient', 'text'], ['gstin', 'GSTIN', 'mono'],
                    ['place_of_supply', 'POS', 'text'], ['booking_no', 'Booking', 'mono'], ['taxable_value', 'Taxable', 'money'], ['cgst', 'CGST', 'money'],
                    ['sgst', 'SGST', 'money'], ['igst', 'IGST', 'money'], ['total', 'Total', 'money'], ['credited_total', 'Credited', 'money'], ['status_label', 'Status', 'text'],
                ], $rows);
            case 'receipts':
                $rows = $this->db->select('SELECT r.*, b.booking_no FROM receipts r LEFT JOIN bookings b ON b.id = r.booking_id WHERE r.receipt_date BETWEEN ? AND ? ORDER BY r.fy, r.seq', [$from, $to]);
                foreach ($rows as &$r) {
                    $r['mode_label'] = PaymentMode::tryFrom((string) $r['mode'])?->label() ?? (string) $r['mode'];
                    $r['kind_label'] = $r['kind'] === 'deposit' ? PaymentKind::Deposit->label() : 'Payment';
                }
                unset($r);
                return $this->spec('Receipt register', $range, [
                    ['receipt_no', 'Receipt no.', 'mono'], ['receipt_date', 'Date', 'date'], ['customer_name', 'Received from', 'text'], ['booking_no', 'Booking', 'mono'],
                    ['kind_label', 'For', 'text'], ['mode_label', 'Mode', 'text'], ['reference_no', 'Reference', 'mono'], ['paid_on', 'Paid on', 'date'], ['amount', 'Amount', 'money'],
                ], $rows);
            case 'credit-notes':
                $rows = $this->db->select('SELECT cn.*, i.invoice_no FROM credit_notes cn JOIN invoices i ON i.id = cn.invoice_id WHERE cn.note_date BETWEEN ? AND ? ORDER BY cn.fy, cn.seq', [$from, $to]);
                foreach ($rows as &$r) {
                    $r['reason_label'] = CreditNoteReason::tryFrom((string) $r['reason_code'])?->label() ?? (string) $r['reason_code'];
                }
                unset($r);
                return $this->spec('Credit note register', $range, [
                    ['credit_note_no', 'Credit note no.', 'mono'], ['note_date', 'Date', 'date'], ['invoice_no', 'Against invoice', 'mono'], ['customer_name', 'Recipient', 'text'],
                    ['reason_label', 'Reason', 'text'], ['taxable_value', 'Taxable', 'money'], ['cgst', 'CGST', 'money'], ['sgst', 'SGST', 'money'], ['igst', 'IGST', 'money'], ['total', 'Total', 'money'],
                ], $rows);
            case 'deposits':
                $rows = $this->db->select(
                    "SELECT b.booking_no, b.status, b.start_date, b.end_date, b.deposit_amount, c.name AS customer_name, c.unique_id,
                            (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.booking_id = b.id AND p.kind = 'deposit' AND p.status = 'verified') AS received,
                            (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.booking_id = b.id AND p.kind = 'deposit' AND p.status = 'logged') AS unverified,
                            d.voucher_no, d.adjustments_total, d.refund_amount
                     FROM bookings b JOIN customers c ON c.id = b.customer_id LEFT JOIN deposit_refunds d ON d.booking_id = b.id
                     WHERE b.deposit_amount > 0 AND b.status NOT IN ('requested', 'rejected') AND b.start_date <= ? AND b.end_date >= ?
                     ORDER BY b.start_date, b.id",
                    [$to, $from],
                );
                foreach ($rows as &$r) {
                    $r['held'] = $r['voucher_no'] !== null ? 0.0 : (float) $r['received'];
                    $r['status_label'] = BookingStatus::from((string) $r['status'])->label();
                }
                unset($r);
                return $this->spec('Deposit register', $range, [
                    ['booking_no', 'Booking', 'mono'], ['customer_name', 'Visitor', 'text'], ['start_date', 'From', 'date'], ['end_date', 'To', 'date'], ['status_label', 'Status', 'text'],
                    ['deposit_amount', 'Deposit due', 'money'], ['received', 'Received', 'money'], ['unverified', 'Unverified', 'money'], ['adjustments_total', 'Adjusted', 'money'],
                    ['refund_amount', 'Refunded', 'money'], ['held', 'Held', 'money'], ['voucher_no', 'Voucher', 'mono'],
                ], $rows);
            case 'outstanding':
                return $this->spec('Outstanding dues', 'As of ' . format_date($this->clock->today()), [
                    ['booking_no', 'Booking', 'mono'], ['customer_name', 'Visitor', 'text'], ['unique_id', 'Unique ID', 'mono'], ['status_label', 'Status', 'text'],
                    ['total', 'Billable', 'money'], ['paid', 'Paid', 'money'], ['due_now', 'Due now', 'money'], ['balance', 'Balance', 'money'], ['next_due', 'Next due', 'date'],
                ], $this->outstandingRows());
        }
        throw new FinanceException('Unknown register.', 'input');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function outstandingRows(): array
    {
        $st = BookingStatus::billableValues();
        $in = implode(',', array_fill(0, count($st), '?'));
        $out = [];
        $bookings = $this->db->select(
            "SELECT b.*, c.name AS customer_name, c.unique_id FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.status IN ({$in}) ORDER BY b.id",
            $st,
        );
        $all = $this->ledger->duesMany($bookings);
        foreach ($bookings as $b) {
            $d = $all[(int) $b['id']];
            if ($d['balance'] <= 0 && $d['due_now'] <= 0) {
                continue;
            }
            $out[] = [
                'booking_no' => $b['booking_no'], 'customer_id' => (int) $b['customer_id'], 'customer_name' => $b['customer_name'], 'unique_id' => $b['unique_id'],
                'status_label' => BookingStatus::from((string) $b['status'])->label(), 'total' => $d['total'], 'paid' => $d['paid'],
                'due_now' => $d['due_now'], 'balance' => $d['balance'], 'next_due' => $d['next_due']['date'] ?? null,
            ];
        }
        usort($out, static fn (array $a, array $b) => $b['due_now'] <=> $a['due_now']);
        return $out;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $columns
     * @param list<array<string, mixed>> $rows
     * @return array{title: string, subtitle: string, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    private function spec(string $title, string $subtitle, array $columns, array $rows): array
    {
        $cols = array_map(static fn (array $c) => ['key' => $c[0], 'label' => $c[1], 'type' => $c[2]], $columns);
        $totals = [];
        foreach ($cols as $c) {
            if ($c['type'] === 'money') {
                $totals[$c['key']] = round(array_sum(array_map(static fn (array $r) => (float) ($r[$c['key']] ?? 0), $rows)), 2);
            }
        }
        return ['title' => $title, 'subtitle' => $subtitle, 'columns' => $cols, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param list<mixed> $bind
     * @return list<array{label: string, amount: float}>
     */
    private function netRevenue(string $sql, array $bind): array
    {
        return array_map(static fn (array $r) => ['label' => (string) $r['label'], 'amount' => round((float) $r['gross'] - (float) $r['credited'], 2)], $this->db->select($sql, $bind));
    }
}
