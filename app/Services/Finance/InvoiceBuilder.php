<?php

declare(strict_types=1);

namespace App\Services\Finance;

use RuntimeException;

/**
 * Builds GST invoice lines from a booking's stored price snapshot (bookings.quote_json, QuoteCalculator lines) —
 * pure maths, no database. Two sources (spec 7.2):
 *
 *   advance()  ≤ 6-month / hourly bookings: one invoice for the whole booking — each quote line (seat groups,
 *              add-ons) becomes an invoice line at its quoted taxable value.
 *   rent()     > 6-month bookings: one invoice per rent_schedules period — the period's taxable amount is spread
 *              over the quote lines in proportion to their value (seats + add-ons for that period).
 *
 * Tax is recomputed per line for the place of supply (customer state code; IGST when it is not the supplier's
 * state) with GstMath::split(). finish() compares the computed total with the amount that was actually billed
 * (booking grand total / rent period amount, which the visitor paid) and records any paise difference as
 * round_off — so the invoice total always equals the money received. A difference of a rupee or more means the
 * snapshot and the bill disagree and is refused.
 */
final class InvoiceBuilder
{
    public const MAX_ROUND_OFF = 1.0;

    /**
     * @param array<string, mixed> $quote decoded bookings.quote_json
     * @param string|null $seatCodes current seat codes (after a handover) — replaces the codes on a single seat line
     * @return list<array<string, mixed>>
     */
    public static function advance(array $quote, bool $interState, string $sac, ?string $seatCodes = null): array
    {
        $lines = [];
        foreach (self::quoteLines($quote, $seatCodes) as $i => $l) {
            $lines[] = self::line($l, (float) $l['amount'], $interState, $sac, $i);
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $quote
     * @param array{period_no: int|string, period_start: string, period_end: string, taxable: float|string} $period rent_schedules row
     * @return list<array<string, mixed>>
     */
    public static function rent(array $quote, array $period, int $periodCount, bool $interState, string $sac, ?string $seatCodes = null): array
    {
        $source = self::quoteLines($quote, $seatCodes);
        $shares = GstMath::allocate(array_map(static fn (array $l) => (float) $l['amount'], $source), round((float) $period['taxable'], 2));
        $range = format_date((string) $period['period_start']) . ' – ' . format_date((string) $period['period_end']);
        $lines = [];
        foreach ($source as $i => $l) {
            $l['detail'] = sprintf('Rent period %d of %d · %s', (int) $period['period_no'], $periodCount, $range);
            $lines[] = self::line($l, $shares[$i], $interState, $sac, $i);
        }
        return $lines;
    }

    /**
     * Totals + round-off against the billed amount.
     *
     * @param list<array<string, mixed>> $lines
     * @return array{taxable: float, cgst: float, sgst: float, igst: float, tax: float, round_off: float, total: float}
     */
    public static function finish(array $lines, ?float $billed = null): array
    {
        $t = GstMath::totals($lines);
        $roundOff = 0.0;
        if ($billed !== null) {
            $roundOff = round($billed - $t['total'], 2);
            if (abs($roundOff) >= self::MAX_ROUND_OFF) {
                throw new RuntimeException(sprintf('Invoice lines (%.2f) do not match the billed amount (%.2f).', $t['total'], $billed));
            }
        }
        return [
            'taxable' => $t['taxable'], 'cgst' => $t['cgst'], 'sgst' => $t['sgst'], 'igst' => $t['igst'], 'tax' => $t['tax'],
            'round_off' => $roundOff, 'total' => round($t['total'] + $roundOff, 2),
        ];
    }

    /**
     * @param array<string, mixed> $quote
     * @return list<array<string, mixed>>
     */
    private static function quoteLines(array $quote, ?string $seatCodes): array
    {
        $lines = array_values(array_filter((array) ($quote['lines'] ?? []), static fn ($l) => is_array($l) && (float) ($l['amount'] ?? 0) > 0));
        if ($lines === []) {
            throw new RuntimeException('The booking has no priced lines to invoice.');
        }
        $category = (string) ($quote['category']['code'] ?? '');
        $unit = match ($category) {
            'CABIN' => 'cabin',
            'CONF' => 'room',
            default => 'seat',
        };
        $seatLines = array_filter($lines, static fn (array $l) => ($l['kind'] ?? 'seat') === 'seat');
        $out = [];
        foreach ($lines as $l) {
            $kind = ($l['kind'] ?? 'seat') === 'addon' ? 'addon' : 'seat';
            $label = (string) ($l['label'] ?? 'Workspace');
            if ($kind === 'seat' && count($seatLines) === 1 && $seatCodes !== null && $seatCodes !== '' && str_contains($label, ' · ')) {
                $label = explode(' · ', $label, 2)[0] . ' · ' . $seatCodes;
            }
            $qty = max(1.0, (float) ($l['qty'] ?? 1));
            $out[] = [
                'kind' => $kind,
                'description' => $kind === 'seat' ? $label : 'Add-on: ' . $label,
                'detail' => trim(implode(' · ', array_filter([(string) ($quote['period']['label'] ?? ''), (string) ($l['detail'] ?? '')]))),
                'qty' => $qty,
                'unit' => $kind === 'seat' ? $unit : 'nos',
                'amount' => round((float) $l['amount'], 2),
                'gst_rate' => (float) ($l['gst_rate'] ?? 18),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $l
     * @return array<string, mixed>
     */
    private static function line(array $l, float $taxable, bool $interState, string $sac, int $i): array
    {
        $tax = GstMath::split($taxable, (float) $l['gst_rate'], $interState);
        return [
            'kind' => $l['kind'],
            'description' => mb_substr((string) $l['description'], 0, 255),
            'detail' => mb_substr((string) $l['detail'], 0, 255),
            'sac' => $sac,
            'qty' => round((float) $l['qty'], 2),
            'unit' => $l['unit'],
            'rate' => round($taxable / max(0.01, (float) $l['qty']), 2),
            'taxable_value' => round($taxable, 2),
            'gst_rate' => round((float) $l['gst_rate'], 2),
            'cgst' => $tax['cgst'],
            'sgst' => $tax['sgst'],
            'igst' => $tax['igst'],
            'total' => $tax['total'],
            'sort_order' => $i,
        ];
    }
}
