<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use App\Core\Exceptions\NotFoundException;
use App\Enums\PaymentMode;
use App\Services\AuditLog;
use App\Services\Notify\Mailer;
use App\Services\Pdf\PdfService;
use App\Services\Visitors\QrCodeRenderer;

/**
 * Issued finance documents as PDFs + email (spec 9): invoice, receipt, credit_note, deposit_refund.
 *
 *   pdf($type, $row)            the ORIGINAL is generated once (on issue, or on first download) and stored under
 *                               storage/pdf/{type}s/{fy}/{slug}.pdf; later downloads stream that stored file.
 *   pdf($type, $row, true)      reprint: re-generated with a "DUPLICATE COPY" watermark (print_count++, audited)
 *                               — also used when the stored original has gone missing.
 *   issued($type, $id)          after an issue: store the original and email it to the visitor as an attachment
 *                               (setting finance_email_documents; Mailer never throws).
 *   forCustomer() / forBooking()   document lists for the portal and the booking page.
 *
 * Numbers contain slashes, so URLs use the row id + NumberSequence::slug() (KDISC-CMN-2026-27-0001).
 */
final class FinanceDocuments
{
    /** type => [table, number column, date column, storage dir, title] */
    public const TYPES = [
        'invoice' => ['invoices', 'invoice_no', 'invoice_date', 'invoices', 'Tax invoice'],
        'receipt' => ['receipts', 'receipt_no', 'receipt_date', 'receipts', 'Receipt'],
        'credit_note' => ['credit_notes', 'credit_note_no', 'note_date', 'credit-notes', 'Credit note'],
        'deposit_refund' => ['deposit_refunds', 'voucher_no', 'voucher_date', 'deposit-refunds', 'Deposit refund voucher'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly PdfService $pdf,
        private readonly FinanceSettings $settings,
        private readonly QrCodeRenderer $qr,
        private readonly Mailer $mailer,
        private readonly AuditLog $audit,
    ) {
    }

    /** URL segment of a type: credit_note → credit-note. */
    public static function urlType(string $type): string
    {
        return str_replace('_', '-', $type);
    }

    public static function fromUrlType(string $segment): string
    {
        $type = str_replace('-', '_', $segment);
        return isset(self::TYPES[$type]) ? $type : throw new NotFoundException();
    }

    /**
     * Download link (staff console or visitor portal).
     *
     * @param array<string, mixed> $row
     */
    public static function url(string $type, array $row, bool $portal = false): string
    {
        return url($portal ? 'portal.invoices.pdf' : 'staff.finance.documents.pdf', ['type' => self::urlType($type), 'id' => (int) $row['id'], 'slug' => self::slug($type, $row)]);
    }

    /** @return array<string, mixed>|null */
    public function find(string $type, int $id, ?int $customerId = null): ?array
    {
        [$table] = self::TYPES[$type] ?? throw new NotFoundException();
        $sql = "SELECT * FROM {$table} WHERE id = ?";
        $bind = [$id];
        if ($customerId !== null) {
            $sql .= ' AND customer_id = ?';
            $bind[] = $customerId;
        }
        return $this->db->first($sql, $bind);
    }

    /** @param array<string, mixed> $row */
    public static function number(string $type, array $row): string
    {
        return (string) $row[self::TYPES[$type][1]];
    }

    /** @param array<string, mixed> $row */
    public static function slug(string $type, array $row): string
    {
        return NumberSequence::slug(self::number($type, $row));
    }

    /** @param array<string, mixed> $row */
    public static function filename(string $type, array $row): string
    {
        return self::slug($type, $row) . '.pdf';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{bytes: string, filename: string, duplicate: bool}
     */
    public function pdf(string $type, array $row, bool $reprint = false, ?int $staffId = null): array
    {
        [$table, , , $dir] = self::TYPES[$type];
        $stored = $this->pdf->path($row['pdf_path'] ?? null);
        if (!$reprint && $stored !== null) {
            return ['bytes' => (string) file_get_contents($stored), 'filename' => self::filename($type, $row), 'duplicate' => false];
        }
        if (!$reprint && empty($row['pdf_path'])) {
            $bytes = $this->render($type, $row, false);
            $relative = $this->pdf->store(sprintf('%s/%s/%s', $dir, $row['fy'], self::filename($type, $row)), $bytes);
            $this->db->update($table, ['pdf_path' => $relative], ['id' => (int) $row['id']]);
            return ['bytes' => $bytes, 'filename' => self::filename($type, $row), 'duplicate' => false];
        }
        $bytes = $this->render($type, $row, true);
        $this->db->execute("UPDATE {$table} SET print_count = print_count + 1 WHERE id = ?", [(int) $row['id']]);
        $this->audit->record($type . '.reprint', $type, (int) $row['id'], null, ['no' => self::number($type, $row)], null, $staffId !== null ? 'staff' : null, $staffId);
        return ['bytes' => $bytes, 'filename' => self::slug($type, $row) . '-DUPLICATE.pdf', 'duplicate' => true];
    }

    /** Store the original PDF and email it to the visitor. Never throws (logged). */
    public function issued(string $type, int $id): void
    {
        try {
            $row = $this->find($type, $id) ?? throw new NotFoundException();
            $doc = $this->pdf($type, $row);
            if (!$this->settings->emailDocuments()) {
                return;
            }
            $to = $this->recipient((int) $row['customer_id']);
            if ($to === null) {
                return;
            }
            $title = self::TYPES[$type][4];
            $number = self::number($type, $row);
            $amount = (float) ($row['total'] ?? $row['amount'] ?? $row['refund_amount'] ?? 0);
            $sent = $this->mailer->send([$to['email'], $to['name']], sprintf('%s %s — Commune Kottarakara', $title, $number), 'finance-document', [
                'name' => $to['name'],
                'title' => $title,
                'number' => $number,
                'rows' => $this->emailRows($type, $row),
                'amount' => $amount,
                'url' => absolute_url('portal.invoices'),
            ], [['name' => $doc['filename'], 'content' => $doc['bytes'], 'mime' => 'application/pdf']]);
            if ($sent && in_array($type, ['invoice', 'receipt', 'credit_note'], true)) {
                $this->db->execute('UPDATE ' . self::TYPES[$type][0] . ' SET emailed_at = NOW() WHERE id = ?', [$id]);
            }
            $this->db->insert('notifications', [
                'recipient_type' => 'customer', 'recipient_id' => (int) $row['customer_id'], 'type' => $type . '.issued',
                'title' => sprintf('%s %s issued', $title, $number), 'body' => sprintf('%s %s for %s is available in your portal.', $title, $number, money($amount, 2)),
                'data' => json_encode(['type' => $type, 'id' => $id], JSON_UNESCAPED_UNICODE), 'channels' => 'database,email',
            ]);
        } catch (\Throwable $e) {
            logger()->error('Could not store/email {type} #{id}: {error}', ['type' => $type, 'id' => $id, 'error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------ lists

    /**
     * Every document of a visitor, newest first, grouped by type.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function forCustomer(int $customerId): array
    {
        return $this->lists('customer_id = ?', [$customerId]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function forBooking(int $bookingId): array
    {
        return $this->lists('booking_id = ?', [$bookingId]);
    }

    /**
     * @param list<mixed> $bind
     * @return array<string, list<array<string, mixed>>>
     */
    private function lists(string $where, array $bind): array
    {
        return [
            'invoice' => $this->db->select("SELECT * FROM invoices WHERE {$where} ORDER BY invoice_date DESC, id DESC", $bind),
            'receipt' => $this->db->select("SELECT r.*, b.booking_no FROM receipts r LEFT JOIN bookings b ON b.id = r.booking_id WHERE r.{$where} ORDER BY r.receipt_date DESC, r.id DESC", $bind),
            'credit_note' => $this->db->select("SELECT cn.*, i.invoice_no FROM credit_notes cn JOIN invoices i ON i.id = cn.invoice_id WHERE cn.{$where} ORDER BY cn.note_date DESC, cn.id DESC", $bind),
            'deposit_refund' => $this->db->select("SELECT d.*, b.booking_no FROM deposit_refunds d JOIN bookings b ON b.id = d.booking_id WHERE d.{$where} ORDER BY d.voucher_date DESC, d.id DESC", $bind),
        ];
    }

    // ------------------------------------------------------------------ rendering

    /** @param array<string, mixed> $row */
    public function render(string $type, array $row, bool $duplicate): string
    {
        $supplier = json_decode((string) ($row['supplier_json'] ?? ''), true);
        $supplier = is_array($supplier) ? $supplier : $this->settings->supplier();
        $common = [
            'supplier' => $supplier,
            'duplicate' => $duplicate,
            'logo' => PdfService::imageDataUri($this->settings->imagePath((string) ($supplier['logo_path'] ?? '')), 240),
            'signature' => PdfService::imageDataUri($this->settings->imagePath((string) ($supplier['signature_path'] ?? '')), 400),
            'seal' => PdfService::imageDataUri($this->settings->imagePath((string) ($supplier['seal_path'] ?? '')), 240),
        ];
        return match ($type) {
            'invoice' => $this->pdf->render('pdf/invoice', $common + $this->invoiceData($row, $supplier)),
            'receipt' => $this->pdf->render('pdf/receipt', $common + $this->receiptData($row)),
            'credit_note' => $this->pdf->render('pdf/credit-note', $common + $this->creditNoteData($row, $supplier)),
            'deposit_refund' => $this->pdf->render('pdf/deposit-refund', $common + $this->refundData($row)),
            default => throw new NotFoundException(),
        };
    }

    /**
     * @param array<string, mixed> $inv
     * @param array<string, mixed> $supplier
     * @return array<string, mixed>
     */
    private function invoiceData(array $inv, array $supplier): array
    {
        $items = $this->db->select('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id', [(int) $inv['id']]);
        $payments = $inv['kind'] === 'advance' && $inv['booking_id'] !== null
            ? $this->db->select("SELECT amount, paid_on, mode, reference_no FROM payments WHERE booking_id = ? AND status = 'verified' AND kind <> 'deposit' ORDER BY paid_on, id", [(int) $inv['booking_id']])
            : [];
        return [
            'invoice' => $inv,
            'items' => $items,
            'summary' => GstMath::summary($items),
            'inter' => (float) $inv['igst'] > 0 || (string) $inv['place_of_supply'] !== (string) ($supplier['state_code'] ?? '32'),
            'payments' => $payments,
            'qr' => $this->qr->pngDataUri(sprintf(
                'GST INVOICE|No:%s|Date:%s|Supplier GSTIN:%s|Recipient:%s|Taxable:%s|Tax:%s|Total:%s',
                $inv['invoice_no'], $inv['invoice_date'], ($supplier['gstin'] ?? '') ?: 'NA', ($inv['customer_gstin'] ?? '') ?: ($inv['customer_unique_id'] ?? ''),
                number_format((float) $inv['taxable_value'], 2, '.', ''), number_format((float) $inv['cgst'] + (float) $inv['sgst'] + (float) $inv['igst'], 2, '.', ''),
                number_format((float) $inv['total'], 2, '.', ''),
            ), 5),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function receiptData(array $r): array
    {
        $b = $r['booking_id'] !== null ? $this->booking((int) $r['booking_id']) : null;
        return [
            'receipt' => $r,
            'booking' => $b,
            'customer' => $this->db->first('SELECT * FROM customers WHERE id = ?', [(int) $r['customer_id']]) ?? [],
            'payment' => $this->db->first('SELECT p.*, v.name AS verified_by_name FROM payments p LEFT JOIN staff_users v ON v.id = p.verified_by WHERE p.id = ?', [(int) $r['payment_id']]) ?? [],
            'invoiceNo' => $r['invoice_id'] !== null ? $this->db->scalar('SELECT invoice_no FROM invoices WHERE id = ?', [(int) $r['invoice_id']]) : null,
            'modeLabel' => PaymentMode::tryFrom((string) $r['mode'])?->label() ?? (string) $r['mode'],
            'qr' => $this->qr->pngDataUri(sprintf('RECEIPT|No:%s|Date:%s|Amount:%s|Booking:%s', $r['receipt_no'], $r['receipt_date'], number_format((float) $r['amount'], 2, '.', ''), $b['booking_no'] ?? ''), 5),
        ];
    }

    /**
     * @param array<string, mixed> $cn
     * @param array<string, mixed> $supplier
     * @return array<string, mixed>
     */
    private function creditNoteData(array $cn, array $supplier): array
    {
        $inv = $this->db->first('SELECT * FROM invoices WHERE id = ?', [(int) $cn['invoice_id']]) ?? [];
        $items = $this->db->select('SELECT * FROM credit_note_items WHERE credit_note_id = ? ORDER BY sort_order, id', [(int) $cn['id']]);
        return [
            'note' => $cn,
            'invoice' => $inv,
            'items' => $items,
            'summary' => GstMath::summary($items),
            'inter' => (float) $cn['igst'] > 0 || (string) $cn['place_of_supply'] !== (string) ($supplier['state_code'] ?? '32'),
            'qr' => $this->qr->pngDataUri(sprintf('CREDIT NOTE|No:%s|Date:%s|Against:%s|Total:%s', $cn['credit_note_no'], $cn['note_date'], $inv['invoice_no'] ?? '', number_format((float) $cn['total'], 2, '.', '')), 5),
        ];
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function refundData(array $d): array
    {
        $adj = json_decode((string) ($d['adjustments_json'] ?? '[]'), true);
        return [
            'voucher' => $d,
            'booking' => $this->booking((int) $d['booking_id']),
            'customer' => $this->db->first('SELECT * FROM customers WHERE id = ?', [(int) $d['customer_id']]) ?? [],
            'adjustments' => is_array($adj) ? $adj : [],
            'deposits' => $this->db->select("SELECT p.*, r.receipt_no FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.booking_id = ? AND p.kind = 'deposit' AND p.status = 'verified' ORDER BY p.paid_on", [(int) $d['booking_id']]),
            'modeLabel' => $d['mode'] !== null ? (PaymentMode::tryFrom((string) $d['mode'])?->label() ?? (string) $d['mode']) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function booking(int $id): ?array
    {
        return $this->db->first(
            "SELECT b.*, sc.name AS category_name,
                    (SELECT GROUP_CONCAT(s.code ORDER BY s.code SEPARATOR ', ') FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id WHERE bs.booking_id = b.id AND bs.transferred_to_id IS NULL) AS seat_codes
             FROM bookings b JOIN seat_categories sc ON sc.id = b.seat_category_id WHERE b.id = ?",
            [$id],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{0: string, 1: string}>
     */
    private function emailRows(string $type, array $row): array
    {
        $date = (string) $row[self::TYPES[$type][2]];
        return match ($type) {
            'invoice' => [['Invoice no.', (string) $row['invoice_no']], ['Date', format_date($date)], ['Booking', (string) $row['booking_no']], ['Period', format_date((string) $row['period_start']) . ' – ' . format_date((string) $row['period_end'])], ['Taxable value', money($row['taxable_value'], 2)], ['GST', money((float) $row['cgst'] + (float) $row['sgst'] + (float) $row['igst'], 2)], ['Total', money($row['total'], 2)]],
            'receipt' => [['Receipt no.', (string) $row['receipt_no']], ['Date', format_date($date)], ['For', $row['kind'] === 'deposit' ? 'Security deposit' : 'Booking payment'], ['Paid on', format_date((string) $row['paid_on'])], ['Amount', money($row['amount'], 2)]],
            'credit_note' => [['Credit note no.', (string) $row['credit_note_no']], ['Date', format_date($date)], ['Reason', (string) $row['reason']], ['Amount', money($row['total'], 2)]],
            default => [['Voucher no.', (string) $row['voucher_no']], ['Date', format_date($date)], ['Deposit held', money($row['deposit_held'], 2)], ['Adjustments', money($row['adjustments_total'], 2)], ['Refund', money($row['refund_amount'], 2)]],
        };
    }

    /** @return array{email: string, name: string}|null */
    private function recipient(int $customerId): ?array
    {
        $r = $this->db->first('SELECT c.name, COALESCE(NULLIF(c.email, \'\'), a.email) AS email FROM customers c LEFT JOIN accounts a ON a.id = c.account_id WHERE c.id = ?', [$customerId]);
        return $r !== null && ($r['email'] ?? '') !== '' ? ['email' => (string) $r['email'], 'name' => (string) $r['name']] : null;
    }
}
