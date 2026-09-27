<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Finance\FinancialYear;
use DateTimeImmutable;

/**
 * Normalised filter values for one report run — built from the query string against the report's Filter list, so
 * the HTML page, the PDF and the XLSX export of the same URL always see identical values.
 *
 *   $f = ReportFilters::make($report, $request->all(), $clock->today());
 *   $f->from(); $f->to();          // resolved date range (range filter)
 *   $f->get('category');           // select / search value ('' = all)
 *   $f->query();                   // params to rebuild the URL (export links, pagination)
 *   $f->describe();                // [['Period', '01 Sep 2026 – 30 Sep 2026'], ['Category', 'All']] for exports
 */
final class ReportFilters
{
    /**
     * @param array<string, string> $values
     * @param list<Filter> $filters
     */
    private function __construct(
        private readonly array $values,
        private readonly array $filters,
        private readonly string $from,
        private readonly string $to,
        public readonly string $today,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function make(Report $report, array $input, string $today): self
    {
        $values = [];
        $from = $to = $today;
        $filters = $report->filters();
        foreach ($filters as $f) {
            $raw = is_scalar($input[$f->name] ?? null) ? trim((string) $input[$f->name]) : '';
            switch ($f->kind) {
                case 'range':
                    $preset = array_key_exists($raw, Filter::PRESETS) ? $raw : $f->default;
                    $cf = is_scalar($input['from'] ?? null) ? (string) $input['from'] : '';
                    $ct = is_scalar($input['to'] ?? null) ? (string) $input['to'] : '';
                    if ($preset === 'custom' && self::isDate($cf) && self::isDate($ct) && $cf <= $ct) {
                        [$from, $to] = [$cf, $ct];
                    } else {
                        if ($preset === 'custom') {
                            $preset = $f->default === 'custom' ? 'month' : $f->default;
                        }
                        [$from, $to] = self::preset($preset, $today);
                    }
                    $values['range'] = $preset;
                    $values['from'] = $from;
                    $values['to'] = $to;
                    break;
                case 'select':
                    $values[$f->name] = array_key_exists($raw, $f->options) ? $raw : $f->default;
                    break;
                case 'date':
                    $values[$f->name] = self::isDate($raw) ? $raw : '';
                    break;
                default:
                    $values[$f->name] = mb_substr($raw, 0, 100);
            }
        }
        return new self($values, $filters, $from, $to, $today);
    }

    /** @return array{0: string, 1: string} */
    public static function preset(string $preset, string $today): array
    {
        $d = new DateTimeImmutable($today);
        return match ($preset) {
            'last-month' => [$d->modify('first day of last month')->format('Y-m-d'), $d->modify('last day of last month')->format('Y-m-d')],
            '3m' => [$d->modify('first day of -2 months')->format('Y-m-d'), $d->format('Y-m-t')],
            '6m' => [$d->modify('first day of -5 months')->format('Y-m-d'), $d->format('Y-m-t')],
            'fy' => FinancialYear::range(FinancialYear::of($today)),
            'last-fy' => FinancialYear::range(FinancialYear::previous(FinancialYear::of($today))),
            'next-30' => [$today, $d->modify('+30 days')->format('Y-m-d')],
            default => [$d->format('Y-m-01'), $d->format('Y-m-t')],
        };
    }

    public static function isDate(string $v): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false;
    }

    public function get(string $name, string $default = ''): string
    {
        return $this->values[$name] ?? $default;
    }

    public function from(): string
    {
        return $this->from;
    }

    public function to(): string
    {
        return $this->to;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Query parameters that reproduce this run (from / to only for a custom range).
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        $q = [];
        foreach ($this->values as $k => $v) {
            if ($v === '' || (($k === 'from' || $k === 'to') && isset($this->values['range']) && $this->values['range'] !== 'custom')) {
                continue;
            }
            $q[$k] = $v;
        }
        return $q;
    }

    /**
     * Human-readable filter lines for exports and page subtitles.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function describe(): array
    {
        $out = [];
        foreach ($this->filters as $f) {
            $v = $this->values[$f->name] ?? '';
            if ($f->kind === 'range') {
                $label = $v !== 'custom' ? (Filter::PRESETS[$v] ?? '') . ' · ' : '';
                $out[] = [$f->label, $label . format_date($this->from, 'd M Y') . ' – ' . format_date($this->to, 'd M Y')];
            } elseif ($f->kind === 'select') {
                $out[] = [$f->label, $f->options[$v] ?? 'All'];
            } elseif ($f->kind === 'date' && $v !== '') {
                $out[] = [$f->label, format_date($v, 'd M Y')];
            } elseif ($f->kind === 'search' && $v !== '') {
                $out[] = [$f->label, '“' . $v . '”'];
            }
        }
        return $out;
    }

    /** One line: "Period: … · Category: All". */
    public function summary(): string
    {
        return implode(' · ', array_map(static fn (array $l) => $l[0] . ': ' . $l[1], $this->describe()));
    }

    /** Days in the resolved range (inclusive). */
    public function days(): int
    {
        return (int) (new DateTimeImmutable($this->from))->diff(new DateTimeImmutable($this->to))->days + 1;
    }
}
