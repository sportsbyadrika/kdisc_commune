<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * One column of a report definition — consumed by the HTML table, the PDF and the XLSX exporter alike, so the three
 * outputs cannot drift apart.
 *
 *   type   text | mono | money | int | number | pct (value is a FRACTION 0..1) | date | datetime
 *   total  sum the column in the totals row (money / int / number); pct totals are supplied by the report
 *   width  XLSX column width in characters (auto from the type when null)
 */
final class Column
{
    public const NUMERIC = ['money', 'int', 'number', 'pct'];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $total = false,
        public readonly ?int $width = null,
    ) {
    }

    public static function text(string $key, string $label, ?int $width = null): self
    {
        return new self($key, $label, 'text', false, $width);
    }

    public static function mono(string $key, string $label, ?int $width = null): self
    {
        return new self($key, $label, 'mono', false, $width);
    }

    public static function money(string $key, string $label, bool $total = true): self
    {
        return new self($key, $label, 'money', $total);
    }

    public static function int(string $key, string $label, bool $total = true): self
    {
        return new self($key, $label, 'int', $total);
    }

    public static function number(string $key, string $label, bool $total = true): self
    {
        return new self($key, $label, 'number', $total);
    }

    public static function pct(string $key, string $label): self
    {
        return new self($key, $label, 'pct');
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, 'date');
    }

    public static function datetime(string $key, string $label): self
    {
        return new self($key, $label, 'datetime');
    }

    public function numeric(): bool
    {
        return in_array($this->type, self::NUMERIC, true);
    }

    /** XLSX width in characters. */
    public function xlsxWidth(): int
    {
        return $this->width ?? match ($this->type) {
            'money' => 15,
            'int', 'pct' => 11,
            'number' => 12,
            'date' => 12,
            'datetime' => 17,
            'mono' => max(14, min(28, mb_strlen($this->label) + 4)),
            default => max(14, min(36, mb_strlen($this->label) + 8)),
        };
    }

    /** Display text for HTML / PDF (XLSX writes typed values instead). */
    public function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return $this->numeric() ? '0' : '—';
        }
        return match ($this->type) {
            'money' => number_format((float) $value, 2),
            'int' => number_format((float) $value, 0),
            'number' => rtrim(rtrim(number_format((float) $value, 2), '0'), '.'),
            'pct' => number_format((float) $value * 100, 1) . '%',
            'date' => format_date((string) $value, 'd M Y'),
            'datetime' => format_date((string) $value, 'd M Y, H:i'),
            default => (string) $value,
        };
    }
}
