<?php

declare(strict_types=1);

namespace App\Services\Imports;

/**
 * One column of an import template.
 *
 *   type       text | date (dd-mm-yyyy or a real Excel date) | time (HH:MM) | number | id (kept as text: mobile,
 *              Aadhaar, PIN — the template formats these cells as Text so Excel does not mangle them)
 *   options    allowed values → a dropdown (data validation) in the template; values or labels are accepted
 *   sensitive  masked in the preview and the error report (Aadhaar)
 */
final class ImportColumn
{
    /** @param list<string> $options */
    public function __construct(
        public readonly string $key,
        public readonly string $header,
        public readonly bool $required = false,
        public readonly string $example = '',
        public readonly string $help = '',
        public readonly array $options = [],
        public readonly string $type = 'text',
        public readonly bool $sensitive = false,
        public readonly int $width = 20,
    ) {
    }

    /** Header text as written in the template: required columns end with " *". */
    public function label(): string
    {
        return $this->header . ($this->required ? ' *' : '');
    }

    /** Normalised header for matching an uploaded sheet ("Mobile *" / "mobile" / "MOBILE (required)"). */
    public static function normalizeHeader(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = (string) preg_replace('/\((required|optional)\)|\*/u', '', $h);
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $h));
    }
}
