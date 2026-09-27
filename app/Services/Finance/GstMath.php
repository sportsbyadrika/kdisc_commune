<?php

declare(strict_types=1);

namespace App\Services\Finance;

/**
 * GST arithmetic for finance documents — pure functions, rounded to paise at LINE level, totals are sums of the
 * rounded lines (same convention as QuoteCalculator::taxLine(), so an invoice for a booking reproduces the
 * booking's quote exactly).
 *
 *   intra-state (place of supply = supplier state 32)  CGST = SGST = taxable × rate / 2 each
 *   inter-state                                        IGST = taxable × rate
 */
final class GstMath
{
    /** @return array{cgst: float, sgst: float, igst: float, tax: float, total: float} */
    public static function split(float $taxable, float $rate, bool $interState): array
    {
        $taxable = round($taxable, 2);
        if ($interState) {
            $cgst = $sgst = 0.0;
            $igst = round($taxable * $rate / 100, 2);
        } else {
            $cgst = round($taxable * $rate / 200, 2);
            $sgst = round($taxable * $rate / 200, 2);
            $igst = 0.0;
        }
        $tax = round($cgst + $sgst + $igst, 2);
        return ['cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'tax' => $tax, 'total' => round($taxable + $tax, 2)];
    }

    /**
     * Split $total in proportion to $weights, rounded to paise; the rounding remainder goes to the largest share
     * so the parts always add up to $total exactly.
     *
     * @param list<float> $weights
     * @return list<float>
     */
    public static function allocate(array $weights, float $total): array
    {
        $n = count($weights);
        if ($n === 0) {
            return [];
        }
        $sum = array_sum($weights);
        if ($sum <= 0) {
            $weights = array_fill(0, $n, 1.0);
            $sum = (float) $n;
        }
        $parts = [];
        foreach ($weights as $w) {
            $parts[] = round($total * $w / $sum, 2);
        }
        $diff = round($total - array_sum($parts), 2);
        if ($diff !== 0.0) {
            $max = array_keys($parts, max($parts))[0];
            $parts[$max] = round($parts[$max] + $diff, 2);
        }
        return $parts;
    }

    /**
     * Totals of document lines (each with taxable_value, cgst, sgst, igst, total).
     *
     * @param list<array<string, mixed>> $lines
     * @return array{taxable: float, cgst: float, sgst: float, igst: float, tax: float, total: float}
     */
    public static function totals(array $lines): array
    {
        $t = ['taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0];
        foreach ($lines as $l) {
            $t['taxable'] += (float) $l['taxable_value'];
            $t['cgst'] += (float) $l['cgst'];
            $t['sgst'] += (float) $l['sgst'];
            $t['igst'] += (float) $l['igst'];
        }
        $t = array_map(static fn (float $v) => round($v, 2), $t);
        $tax = round($t['cgst'] + $t['sgst'] + $t['igst'], 2);
        return $t + ['tax' => $tax, 'total' => round($t['taxable'] + $tax, 2)];
    }

    /**
     * Tax summary grouped by GST rate (the "HSN/SAC-wise summary" block of a GST invoice).
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array{sac: string, rate: float, taxable: float, cgst: float, sgst: float, igst: float, tax: float}>
     */
    public static function summary(array $lines): array
    {
        $by = [];
        foreach ($lines as $l) {
            $rate = round((float) $l['gst_rate'], 2);
            $key = ($l['sac'] ?? '') . '|' . $rate;
            $by[$key] ??= ['sac' => (string) ($l['sac'] ?? ''), 'rate' => $rate, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'tax' => 0.0];
            foreach (['taxable' => 'taxable_value', 'cgst' => 'cgst', 'sgst' => 'sgst', 'igst' => 'igst'] as $k => $src) {
                $by[$key][$k] = round($by[$key][$k] + (float) $l[$src], 2);
            }
            $by[$key]['tax'] = round($by[$key]['cgst'] + $by[$key]['sgst'] + $by[$key]['igst'], 2);
        }
        ksort($by);
        return array_values($by);
    }

    /** "18%" / "12.5%" */
    public static function rateLabel(float|string $rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.') . '%';
    }
}
