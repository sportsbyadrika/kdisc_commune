<?php

declare(strict_types=1);

namespace App\Services\Visitors;

use App\Core\Database;
use App\Core\Validator;
use App\Enums\CustomerType;
use App\Enums\HolderType;
use App\Enums\KycStatus;
use App\Models\Customer;
use App\Services\AuditLog;
use App\Services\Kyc\IdValidator;

/**
 * Staff-side creation of a visitor profile (assisted registration, bulk import, demo data) — ONE path so the
 * reception form, the XLSX importer and `demo:seed` apply identical rules:
 *
 *   validate()  ProfileService basic + identity rules together (every error at once; Aadhaar Verhoeff, PAN, GSTIN
 *               checksum + PAN match + state, TAN, passport, mobile, email…) — throws ValidationException
 *   create()    insert customers (+ primary signatory) with Aadhaar protected by AadhaarVault (encrypted + HMAC hash +
 *               last 4 — via ProfileService::validateIdentity), audit `visitor.register`
 *   register()  validate + create + submit (issues the Unique Visitor ID; optional verify)
 *
 * Duplicate checks (DuplicateFinder) are the caller's decision: the form asks for confirmation, the importer refuses.
 */
final class VisitorRegistration
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly RegistrationService $registration,
        private readonly AuditLog $audit,
        private readonly Database $db,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $customer existing row when editing ([] = new)
     */
    public function validate(CustomerType $type, array $input, array $customer = []): void
    {
        $input['state_code'] ??= '';
        foreach (['pan', 'gstin', 'tan', 'passport_no'] as $k) {
            if (isset($input[$k]) && is_string($input[$k])) {
                $input[$k] = IdValidator::normalize($input[$k]);
            }
        }
        Validator::make(
            $input,
            $this->profiles->basicRules($type) + $this->profiles->identityRules($type, $input, $customer),
            ProfileService::messages(),
            ProfileService::basicAttributes($type) + ProfileService::identityAttributes(),
        )->validate();
    }

    /**
     * Insert the profile (not yet submitted). $via = reception | online.
     *
     * @param array<string, mixed> $input already validated
     * @param array<string, mixed> $staff staff_users row (id, centre_id)
     */
    public function create(CustomerType $type, array $input, array $staff, string $via = 'reception'): int
    {
        $basic = $this->profiles->validateBasic($type, $input);
        $identity = $this->profiles->validateIdentity($type, $input + ['state_code' => $basic['state_code']], []);
        return $this->db->transaction(function (Database $db) use ($type, $basic, $identity, $staff, $via): int {
            $id = $db->insert('customers', $basic + $identity['customer'] + [
                'type' => $type->value,
                'centre_id' => RegistrationService::centreId($db, isset($staff['centre_id']) ? (int) $staff['centre_id'] : null),
                'kyc_status' => KycStatus::NotSubmitted->value,
                'registered_via' => $via === 'online' ? 'online' : 'reception',
                'registered_by' => isset($staff['id']) ? (int) $staff['id'] : null,
                'profile_step' => 2,
                'consent_at' => $identity['consent'] ? date('Y-m-d H:i:s') : null,
            ]);
            if ($identity['signatory'] !== null) {
                $db->insert('customer_signatories', $identity['signatory'] + ['customer_id' => $id, 'is_primary' => 1]);
            }
            $this->audit->record('visitor.register', 'customer', $id, null, ['type' => $type->value, 'via' => $via], null, 'staff', isset($staff['id']) ? (int) $staff['id'] : null);
            return $id;
        });
    }

    /**
     * Validate, create and submit (Unique Visitor ID issued; KYC pending, or verified when $verify). Documents are
     * collected later (checkDocuments = false), like an assisted registration without uploads.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $staff
     * @return array{id: int, unique_id: string}
     */
    public function register(CustomerType $type, array $input, array $staff, string $via = 'reception', bool $verify = false): array
    {
        $this->validate($type, $input);
        $id = $this->create($type, $input, $staff, $via);
        $this->profiles->completeDocuments($id);
        $uniqueId = $this->profiles->submit((array) Customer::find($id), HolderType::Staff, isset($staff['id']) ? (int) $staff['id'] : null, verify: $verify, checkDocuments: false);
        return ['id' => $id, 'unique_id' => $uniqueId];
    }

    /** Portal invite (set-password email); false when the visitor already has an account or no email. */
    public function invite(int $customerId, int $staffId): bool
    {
        $customer = Customer::find($customerId);
        return $customer !== null && (string) $customer['email'] !== '' && $this->registration->invite($customer, $staffId);
    }
}
