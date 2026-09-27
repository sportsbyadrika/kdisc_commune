<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * A report filter. Kinds:
 *   range    date range: query params `range` (preset) + `from` / `to` (custom); resolved by ReportFilters
 *   select   one of $options (value => label); '' = "all" when $default is ''
 *   search   free text (max 100 chars)
 *   date     one optional Y-m-d date (list exports that mirror a page's own from / to fields)
 */
final class Filter
{
    /** Presets offered by a range filter. */
    public const PRESETS = [
        'month' => 'This month',
        'last-month' => 'Last month',
        '3m' => 'Last 3 months',
        '6m' => 'Last 6 months',
        'fy' => 'This financial year',
        'last-fy' => 'Last financial year',
        'custom' => 'Custom dates',
    ];

    /** @param array<int|string, string> $options */
    private function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly string $label,
        public readonly array $options = [],
        public readonly string $default = '',
    ) {
    }

    public static function range(string $default = 'month', string $label = 'Period'): self
    {
        return new self('range', 'range', $label, self::PRESETS, $default);
    }

    /** @param array<int|string, string> $options */
    public static function select(string $name, string $label, array $options, string $default = ''): self
    {
        return new self('select', $name, $label, $options, $default);
    }

    public static function date(string $name, string $label): self
    {
        return new self('date', $name, $label);
    }

    public static function search(string $name = 'q', string $label = 'Search'): self
    {
        return new self('search', $name, $label);
    }
}
