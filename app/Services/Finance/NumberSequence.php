<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Core\Database;
use LogicException;

/**
 * Gap-free document numbers per Indian financial year (spec 7.3), shared by invoices, receipts, credit notes and
 * deposit refund vouchers:
 *
 *   KDISC/CMN/2026-27/0001   RCPT/2026-27/0001   CN/2026-27/0001   DRV/2026-27/0001
 *
 * next() MUST run inside the caller's transaction, together with the INSERT of the document: the counter row
 * (number_sequences name/period) is locked with SELECT … FOR UPDATE, so concurrent issuers queue on it, and a
 * failed insert rolls the counter back too — no gaps, no duplicates. Prefixes come from settings.
 */
final class NumberSequence
{
    public const INVOICE = 'invoice';
    public const RECEIPT = 'receipt';
    public const CREDIT_NOTE = 'credit_note';
    public const REFUND_VOUCHER = 'refund_voucher';

    private const PREFIX = [
        self::INVOICE => ['invoice_prefix', 'KDISC/CMN'],
        self::RECEIPT => ['receipt_prefix', 'RCPT'],
        self::CREDIT_NOTE => ['credit_note_prefix', 'CN'],
        self::REFUND_VOUCHER => ['refund_voucher_prefix', 'DRV'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Allocate the next number of $name in $fy. Call inside a transaction.
     *
     * @return array{no: string, fy: string, seq: int}
     */
    public function next(string $name, string $fy): array
    {
        if (!isset(self::PREFIX[$name])) {
            throw new LogicException("Unknown document sequence [{$name}].");
        }
        if (!$this->db->pdo()->inTransaction()) {
            throw new LogicException('NumberSequence::next() must run inside the transaction that stores the document.');
        }
        // ON DUPLICATE KEY UPDATE takes an exclusive lock straight away (INSERT IGNORE takes a shared one, and two
        // issuers upgrading shared locks to FOR UPDATE deadlock each other).
        $this->db->execute('INSERT INTO number_sequences (name, period, last_value) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE last_value = last_value', [$name, $fy]);
        $last = (int) $this->db->scalar('SELECT last_value FROM number_sequences WHERE name = ? AND period = ? FOR UPDATE', [$name, $fy]);
        $seq = $last + 1;
        $this->db->execute('UPDATE number_sequences SET last_value = ? WHERE name = ? AND period = ?', [$seq, $name, $fy]);
        return ['no' => self::format($name, $fy, $seq), 'fy' => $fy, 'seq' => $seq];
    }

    public static function format(string $name, string $fy, int $seq, ?string $prefix = null): string
    {
        [$key, $default] = self::PREFIX[$name] ?? throw new LogicException("Unknown document sequence [{$name}].");
        if ($prefix === null) {
            try {
                $prefix = (string) setting($key, $default);
            } catch (\Throwable) {
                $prefix = $default;
            }
        }
        $prefix = trim($prefix, " /");
        return sprintf('%s/%s/%04d', $prefix !== '' ? $prefix : $default, $fy, $seq);
    }

    /** URL-safe slug of a document number: "KDISC/CMN/2026-27/0001" → "KDISC-CMN-2026-27-0001". */
    public static function slug(string $number): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $number), '-');
    }
}
