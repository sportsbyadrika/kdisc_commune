<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Core\Exceptions\ValidationException;
use App\Services\Kyc\AadhaarVault;

/**
 * One bulk-import type (spec 10). A type declares its template columns and validates / imports ONE row at a time
 * through the same services and rules the UI uses — never its own SQL writes.
 *
 *   validate($row, $ctx)  → ['data' => normalised input, 'errors' => [column key => message], 'note' => preview text]
 *   import($data, $ctx)   → reference of what was created (Unique ID, booking no, payment #…); throws on failure
 */
abstract class Importer
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    abstract public function icon(): string;

    /** @return list<ImportColumn> */
    abstract public function columns(): array;

    /**
     * @param array<string, string> $row column key => raw cell text (dates already Y-m-d, times H:i)
     * @return array{data: array<string, mixed>, errors: array<string, string>, note: string}
     */
    abstract public function validate(array $row, ImportContext $ctx): array;

    /** @param array<string, mixed> $data validate()['data'] */
    abstract public function import(array $data, ImportContext $ctx): string;

    /**
     * Extra lines for the template's Instructions sheet.
     *
     * @return list<string>
     */
    public function instructions(): array
    {
        return [];
    }

    /** Can imported rows receive a portal invite? */
    public function invites(): bool
    {
        return false;
    }

    /** Display value for the preview / error report (sensitive columns masked). */
    public function display(ImportColumn $col, string $value): string
    {
        if ($col->sensitive && $value !== '') {
            $digits = (string) preg_replace('/\D+/', '', $value);
            return $digits !== '' ? AadhaarVault::mask(substr($digits, -4)) : '••••';
        }
        if ($col->type === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}"; // shown (and written to the error report) as typed in the template
        }
        return $value;
    }

    public function column(string $key): ?ImportColumn
    {
        foreach ($this->columns() as $c) {
            if ($c->key === $key) {
                return $c;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ helpers for subclasses

    /**
     * Map a dropdown cell (value or label, any case) to its value.
     *
     * @param array<string, string> $options value => label
     */
    protected static function option(string $raw, array $options): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        foreach ($options as $value => $label) {
            if (strcasecmp((string) $value, $raw) === 0 || strcasecmp($label, $raw) === 0) {
                return (string) $value;
            }
        }
        return null;
    }

    protected static function yes(string $raw): bool
    {
        return in_array(mb_strtolower(trim($raw)), ['y', 'yes', '1', 'true', 'x', '✓'], true);
    }

    /**
     * Turn a ValidationException into column errors (first message per field), mapping form fields to columns.
     *
     * @param array<string, string> $fieldToColumn
     * @return array<string, string>
     */
    protected static function errorsFrom(ValidationException $e, array $fieldToColumn = []): array
    {
        $out = [];
        foreach ($e->errors() as $field => $messages) {
            $col = $fieldToColumn[$field] ?? $field;
            $out[$col] ??= (string) ($messages[0] ?? 'Invalid value.');
        }
        return $out;
    }
}
