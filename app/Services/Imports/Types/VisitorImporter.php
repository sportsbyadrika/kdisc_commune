<?php

declare(strict_types=1);

namespace App\Services\Imports\Types;

use App\Core\Exceptions\ValidationException;
use App\Enums\CustomerSubCategory;
use App\Enums\CustomerType;
use App\Services\Imports\ImportContext;
use App\Services\Imports\Importer;
use App\Services\Kyc\AadhaarVault;
use App\Services\Kyc\IdValidator;
use App\Services\Visitors\DuplicateFinder;
use App\Services\Visitors\VisitorRegistration;
use App\Support\IndianStates;

/**
 * Shared by the Individuals and Institutions imports: each row becomes the same form input the assisted
 * registration posts, is validated by VisitorRegistration::validate() (ProfileService rules: Aadhaar Verhoeff, PAN,
 * GSTIN checksum + PAN + state match, TAN, mobile, email…), checked by DuplicateFinder against the database AND
 * against earlier rows of the file, and imported with VisitorRegistration::register() (Aadhaar encrypted by
 * AadhaarVault, Unique Visitor ID issued, KYC pending — documents are collected later). Optional portal invites.
 */
abstract class VisitorImporter extends Importer
{
    public function __construct(
        protected readonly VisitorRegistration $registration,
        protected readonly DuplicateFinder $duplicates,
        protected readonly AadhaarVault $vault,
    ) {
    }

    abstract protected function type(): CustomerType;

    /**
     * Row → form input (field names of the registration form).
     *
     * @param array<string, string> $row
     * @return array<string, mixed>
     */
    abstract protected function input(array $row): array;

    /** @return array<string, string> form field => column key (for error messages) */
    abstract protected function fieldMap(): array;

    public function invites(): bool
    {
        return true;
    }

    /** @return list<string> state names for the dropdown */
    protected static function stateNames(): array
    {
        return array_values(IndianStates::options());
    }

    protected static function stateCode(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^\d{1,2}$/', $raw)) {
            return str_pad($raw, 2, '0', STR_PAD_LEFT);
        }
        foreach (IndianStates::options() as $code => $name) {
            if (strcasecmp($name, $raw) === 0) {
                return (string) $code;
            }
        }
        return $raw === '' ? '' : 'xx';
    }

    /** @return array<string, string> */
    protected function subCategories(): array
    {
        return CustomerSubCategory::optionsFor($this->type());
    }

    public function validate(array $row, ImportContext $ctx): array
    {
        $input = $this->input($row);
        $errors = [];
        if (($row['sub_category'] ?? '') !== '' && $input['sub_category'] === null) {
            $errors['sub_category'] = 'Pick one of: ' . implode(', ', $this->subCategories()) . '.';
        }
        if (($input['state_code'] ?? '') === 'xx') {
            $errors['state'] = 'Unknown state — pick it from the list.';
        }
        try {
            $this->registration->validate($this->type(), $input);
        } catch (ValidationException $e) {
            $errors += self::errorsFrom($e, $this->fieldMap());
        }

        // duplicates: the database …
        $criteria = $this->duplicates->criteriaFromInput($input);
        foreach ($this->duplicates->find($criteria) as $m) {
            $col = match ($m['fields'][0] ?? '') {
                'email' => 'email',
                'mobile' => 'mobile',
                'aadhaar' => $this->type() === CustomerType::Individual ? 'aadhaar' : 'sig_aadhaar',
                'pan' => 'pan',
                default => 'gstin',
            };
            $errors[$col] ??= sprintf('Already registered: %s (%s) — same %s.', $m['customer']['name'], $m['customer']['unique_id'] ?? 'not submitted', implode(' + ', $m['labels']));
            break;
        }
        // … and earlier rows of this file
        $labels = ['email' => 'email', 'mobile' => 'mobile', 'aadhaar_hash' => 'Aadhaar', 'pan' => 'PAN', 'gstin' => 'GSTIN'];
        foreach ($criteria as $kind => $value) {
            $earlier = $ctx->claim('visitor.' . $kind, $value, (int) ($row['_row'] ?? 0));
            if ($earlier !== null) {
                $col = match ($kind) {
                    'aadhaar_hash' => $this->type() === CustomerType::Individual ? 'aadhaar' : 'sig_aadhaar',
                    default => $kind,
                };
                $errors[$col] ??= sprintf('Same %s as row %d of this file.', $labels[$kind], $earlier);
            }
        }
        $sub = $input['sub_category'] !== null ? ($this->subCategories()[$input['sub_category']] ?? '') : '';
        return ['data' => $input, 'errors' => $errors, 'note' => trim($sub . ' · KYC pending after import', ' ·')];
    }

    public function import(array $data, ImportContext $ctx): string
    {
        $r = $this->registration->register($this->type(), $data, $ctx->staff, 'reception');
        $ctx->state['created_customers'][] = $r['id']; // portal invites go out after the transaction commits
        return $r['unique_id'];
    }

    protected static function digits(string $v): string
    {
        return IdValidator::normalize($v);
    }
}
