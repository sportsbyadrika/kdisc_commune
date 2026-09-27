<?php

declare(strict_types=1);

namespace App\Services\Imports\Types;

use App\Enums\CustomerType;
use App\Services\Imports\ImportColumn;

/** Bulk registration of institutions with their authorised signatory (template: institutions.xlsx). */
final class InstitutionsImporter extends VisitorImporter
{
    public function key(): string
    {
        return 'institutions';
    }

    public function label(): string
    {
        return 'Institutions';
    }

    public function description(): string
    {
        return 'Companies, startups, NGOs, institutions and government bodies — PAN, GSTIN, TAN and signatory.';
    }

    public function icon(): string
    {
        return 'building-2';
    }

    protected function type(): CustomerType
    {
        return CustomerType::Institution;
    }

    public function columns(): array
    {
        return [
            new ImportColumn('sub_category', 'Institution type', true, 'Startup', '', array_values($this->subCategories()), width: 16),
            new ImportColumn('name', 'Institution name', true, 'Kollam Code Labs LLP', '', width: 26),
            new ImportColumn('email', 'Official email', true, 'accounts@kollamcodelabs.in', 'Unique — used for the portal account.', width: 28),
            new ImportColumn('mobile', 'Official phone', true, '04742451234', 'Landline with STD code or a mobile number.', type: 'id', width: 15),
            new ImportColumn('address', 'Address', true, 'Chinnakada Road', '', width: 30),
            new ImportColumn('city', 'City', true, 'Kollam', '', width: 16),
            new ImportColumn('pincode', 'PIN code', true, '691001', '6 digits.', type: 'id', width: 10),
            new ImportColumn('state', 'State', true, 'Kerala', 'Must match the GSTIN state code.', self::stateNames(), width: 18),
            new ImportColumn('profile', 'Institution profile', true, 'Software studio building civic-tech products for local governments across Kerala, with a team of twelve engineers.', 'At least 80 characters.', width: 44),
            new ImportColumn('pan', 'PAN', true, 'AAQFK7788L', "The institution's PAN.", type: 'id', width: 13),
            new ImportColumn('gstin', 'GSTIN', true, '32AAQFK7788L1ZF', 'Checksum verified; must contain the PAN and the state code.', type: 'id', width: 18),
            new ImportColumn('tan', 'TAN', true, 'TVDK12345A', '', type: 'id', width: 13),
            new ImportColumn('sig_name', 'Signatory name', true, 'Suresh Babu', 'Authorised signatory.', width: 20),
            new ImportColumn('sig_designation', 'Signatory designation', true, 'Director', '', width: 16),
            new ImportColumn('sig_email', 'Signatory email', true, 'suresh@kollamcodelabs.in', '', width: 26),
            new ImportColumn('sig_mobile', 'Signatory mobile', true, '9847066602', '10-digit Indian mobile.', type: 'id', width: 15),
            new ImportColumn('sig_aadhaar', "Signatory's Aadhaar", true, '2341 2341 2346', '12 digits, Verhoeff checksum. Stored encrypted; never shown again.', type: 'id', sensitive: true, width: 17),
            new ImportColumn('aadhaar_consent', 'Aadhaar consent', true, 'Yes', '"Yes" = the signatory consented to store the Aadhaar number.', ['Yes', 'No'], width: 12),
        ];
    }

    protected function input(array $row): array
    {
        $in = ['type' => 'institution', 'sub_category' => self::option($row['sub_category'] ?? '', $this->subCategories()), 'state_code' => self::stateCode($row['state'] ?? '')];
        foreach (['name', 'email', 'mobile', 'address', 'city', 'pincode', 'profile', 'sig_name', 'sig_designation', 'sig_email', 'sig_mobile'] as $k) {
            $in[$k] = trim($row[$k] ?? '');
        }
        foreach (['pan', 'gstin', 'tan'] as $k) {
            $in[$k] = self::digits($row[$k] ?? '');
        }
        $in['sig_aadhaar'] = (string) preg_replace('/\D+/', '', $row['sig_aadhaar'] ?? '');
        $in['aadhaar_consent'] = self::yes($row['aadhaar_consent'] ?? '') ? '1' : '';
        return $in;
    }

    protected function fieldMap(): array
    {
        return ['state_code' => 'state'];
    }

    /** @return list<string> */
    public function instructions(): array
    {
        return [
            'The GSTIN must be valid (checksum), contain the PAN and start with the state code of the State column.',
            "Imported institutions get a Unique Visitor ID with KYC \"pending\" — upload the documents (PAN, GST certificate, TAN, registration, authorisation letter, signatory's Aadhaar) and verify from the KYC queue.",
            "The signatory's Aadhaar is encrypted on import and masked in the preview and the error report.",
        ];
    }
}
