<?php

declare(strict_types=1);

namespace App\Services\Imports\Types;

use App\Enums\CustomerType;
use App\Services\Imports\ImportColumn;

/** Bulk registration of individual visitors (template: individuals.xlsx). */
final class IndividualsImporter extends VisitorImporter
{
    public function key(): string
    {
        return 'individuals';
    }

    public function label(): string
    {
        return 'Individuals';
    }

    public function description(): string
    {
        return 'Remote workers, freelancers, students, self-employed — with Aadhaar or passport.';
    }

    public function icon(): string
    {
        return 'user-round';
    }

    protected function type(): CustomerType
    {
        return CustomerType::Individual;
    }

    public function columns(): array
    {
        return [
            new ImportColumn('sub_category', 'Category', true, 'Freelancer', 'Individual category.', array_values($this->subCategories()), width: 16),
            new ImportColumn('name', 'Full name', true, 'Anjali Pillai', 'As on the ID document.', width: 24),
            new ImportColumn('email', 'Email', true, 'anjali@example.com', 'Unique — used for the portal account.', width: 28),
            new ImportColumn('mobile', 'Mobile', true, '9847012345', '10-digit Indian mobile (with or without +91).', type: 'id', width: 15),
            new ImportColumn('address', 'Address', true, 'TC 14/2210, Temple Road', '', width: 30),
            new ImportColumn('city', 'City', true, 'Kottarakara', '', width: 16),
            new ImportColumn('pincode', 'PIN code', true, '691506', '6 digits.', type: 'id', width: 10),
            new ImportColumn('state', 'State', true, 'Kerala', 'Home state (GST state code or name).', self::stateNames(), width: 18),
            new ImportColumn('profile', 'Professional summary', true, 'Freelance UX designer working with startups across Kerala.', 'At least 30 characters.', width: 40),
            new ImportColumn('nationality', 'Nationality', true, 'Indian', '"Indian" (Aadhaar required) or "Foreign" (passport required).', ['Indian', 'Foreign'], width: 12),
            new ImportColumn('country', 'Country (foreign nationals)', false, '', 'Required when Nationality = Foreign.', width: 18),
            new ImportColumn('passport_no', 'Passport no. (foreign nationals)', false, '', 'Required when Nationality = Foreign.', type: 'id', width: 16),
            new ImportColumn('aadhaar', 'Aadhaar number', false, '2341 2341 2346', 'Required for Indian nationals. 12 digits, Verhoeff checksum. Stored encrypted; never shown again.', type: 'id', sensitive: true, width: 17),
            new ImportColumn('aadhaar_consent', 'Aadhaar consent', false, 'Yes', '"Yes" = the visitor consented to store the Aadhaar number (required with an Aadhaar).', ['Yes', 'No'], width: 12),
            new ImportColumn('pan', 'PAN', false, 'ABCPE1234F', 'Optional.', type: 'id', width: 13),
            new ImportColumn('gstin', 'GSTIN', false, '', 'Optional — must match the PAN and the state.', type: 'id', width: 18),
        ];
    }

    protected function input(array $row): array
    {
        $foreign = strcasecmp(trim($row['nationality'] ?? ''), 'foreign') === 0;
        return [
            'type' => 'individual',
            'sub_category' => self::option($row['sub_category'] ?? '', $this->subCategories()),
            'name' => trim($row['name'] ?? ''),
            'email' => trim($row['email'] ?? ''),
            'mobile' => trim($row['mobile'] ?? ''),
            'address' => trim($row['address'] ?? ''),
            'city' => trim($row['city'] ?? ''),
            'pincode' => trim($row['pincode'] ?? ''),
            'state_code' => self::stateCode($row['state'] ?? ''),
            'profile' => trim($row['profile'] ?? ''),
            'nationality_type' => $foreign ? 'foreign' : (trim($row['nationality'] ?? '') === '' || strcasecmp(trim($row['nationality']), 'indian') === 0 ? 'indian' : 'invalid'),
            'country' => trim($row['country'] ?? ''),
            'passport_no' => self::digits($row['passport_no'] ?? ''),
            'aadhaar' => (string) preg_replace('/\D+/', '', $row['aadhaar'] ?? ''),
            'aadhaar_consent' => self::yes($row['aadhaar_consent'] ?? '') ? '1' : '',
            'pan' => self::digits($row['pan'] ?? ''),
            'gstin' => self::digits($row['gstin'] ?? ''),
        ];
    }

    protected function fieldMap(): array
    {
        return ['state_code' => 'state', 'nationality_type' => 'nationality'];
    }

    /** @return list<string> */
    public function instructions(): array
    {
        return [
            'Indian nationals need an Aadhaar number with "Aadhaar consent" = Yes; foreign nationals need Country + Passport no. instead.',
            'Imported visitors get a Unique Visitor ID straight away with KYC "pending" — upload their documents and verify them from the KYC queue.',
            'Aadhaar numbers are encrypted on import and are masked (XXXX XXXX 1234) in the preview and the error report — re-type them when you correct a row.',
        ];
    }
}
