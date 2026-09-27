<?php

declare(strict_types=1);

namespace App\Services\Visitors;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Validator;
use App\Enums\CustomerSubCategory;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\HolderType;
use App\Enums\KycStatus;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\CustomerSignatory;
use App\Services\AuditLog;
use App\Services\Kyc\AadhaarVault;
use App\Services\Kyc\IdValidator;
use App\Support\IndianStates;

/**
 * Visitor profile & KYC rules shared by the online wizard (Portal\WizardController) and assisted
 * registration (Staff\VisitorController) — one source of truth for spec 4.3.
 *
 * Steps: 1 basic details · 2 identity & KYC · 3 documents · 4 review & submit.
 * customers.profile_step records the highest completed step so the wizard is resumable.
 *
 *   $profiles->saveBasic($customer, $request->all(), HolderType::Account, $accountId);
 *   $profiles->saveIdentity($customer, $request->all(), HolderType::Account, $accountId);
 *   $uniqueId = $profiles->submit($customer, HolderType::Account, $accountId);
 */
final class ProfileService
{
    public const STEPS = [1 => 'Basic details', 2 => 'Identity & KYC', 3 => 'Documents', 4 => 'Review & submit'];

    /** Columns whose change sends a verified profile back to "KYC pending". */
    private const KYC_COLUMNS = [
        'sub_category', 'name', 'email', 'mobile', 'address', 'city', 'pincode', 'state_code', 'profile',
        'nationality', 'pan', 'gstin', 'tan', 'aadhaar_hash', 'passport_no',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly AadhaarVault $vault,
        private readonly UniqueIdGenerator $ids,
        private readonly AuditLog $audit,
    ) {
    }

    // ------------------------------------------------------------------ rules

    /** @return array<string, list<string>> */
    public function basicRules(CustomerType $type, bool $includeEmail = true): array
    {
        $individual = $type === CustomerType::Individual;
        $rules = [
            'sub_category' => ['required', 'in:' . implode(',', array_keys(CustomerSubCategory::optionsFor($type)))],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'mobile' => ['required', $individual ? 'mobile_in' : 'phone_in'],
            'address' => ['required', 'string', 'min:5', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
            'pincode' => ['required', 'regex:/^[1-9][0-9]{5}$/'],
            'state_code' => ['required', IndianStates::rule()],
            'profile' => ['required', 'string', $individual ? 'min:30' : 'min:80', 'max:4000'],
        ];
        if (!$includeEmail) {
            unset($rules['email']);
        }
        return $rules;
    }

    /** @return array<string, string> */
    public static function basicAttributes(CustomerType $type): array
    {
        return $type === CustomerType::Individual
            ? ['sub_category' => 'category', 'name' => 'full name', 'mobile' => 'mobile number', 'profile' => 'professional summary', 'state_code' => 'state']
            : ['sub_category' => 'institution type', 'name' => 'institution name', 'email' => 'official email', 'mobile' => 'official phone', 'profile' => 'institution profile', 'state_code' => 'state'];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $customer existing row ([] when creating) — an Aadhaar already on file may be kept
     * @return array<string, list<string>>
     */
    public function identityRules(CustomerType $type, array $input, array $customer = []): array
    {
        $hasAadhaar = (string) ($customer['aadhaar_last4'] ?? '') !== '';
        if ($type === CustomerType::Individual) {
            $foreign = ($input['nationality_type'] ?? 'indian') === 'foreign';
            return [
                'nationality_type' => ['required', 'in:indian,foreign'],
                'country' => $foreign ? ['required', 'string', 'min:2', 'max:60', fn ($v) => mb_strtolower(trim((string) $v)) !== 'indian' && mb_strtolower(trim((string) $v)) !== 'india' ?: 'Choose "Indian citizen" instead.'] : ['nullable'],
                'aadhaar' => $foreign ? ['nullable'] : [$hasAadhaar ? 'nullable' : 'required', 'aadhaar'],
                'passport_no' => $foreign ? ['required', 'passport'] : ['nullable'],
                'pan' => ['nullable', 'pan'],
                'gstin' => ['nullable', 'gstin', 'gstin_pan:pan', 'gstin_state:state_code'],
                'aadhaar_consent' => !$foreign && ($input['aadhaar'] ?? '') !== '' ? ['accepted'] : ['nullable'],
            ];
        }
        $sig = CustomerSignatory::primaryFor((int) ($customer['id'] ?? 0));
        $hasSigAadhaar = $sig !== null && (string) ($sig['aadhaar_last4'] ?? '') !== '';
        return [
            'pan' => ['required', 'pan'],
            'gstin' => ['required', 'gstin', 'gstin_pan:pan', 'gstin_state:state_code'],
            'tan' => ['required', 'tan'],
            'sig_name' => ['required', 'string', 'min:2', 'max:150'],
            'sig_designation' => ['required', 'string', 'max:100'],
            'sig_email' => ['required', 'email', 'max:190'],
            'sig_mobile' => ['required', 'mobile_in'],
            'sig_aadhaar' => [$hasSigAadhaar ? 'nullable' : 'required', 'aadhaar'],
            'aadhaar_consent' => ($input['sig_aadhaar'] ?? '') !== '' ? ['accepted'] : ['nullable'],
        ];
    }

    /** @return array<string, string> */
    public static function identityAttributes(): array
    {
        return [
            'nationality_type' => 'nationality', 'passport_no' => 'passport number', 'pan' => 'PAN', 'gstin' => 'GSTIN', 'tan' => 'TAN',
            'aadhaar' => 'Aadhaar number', 'sig_name' => 'signatory name', 'sig_designation' => 'designation', 'sig_email' => 'signatory email',
            'sig_mobile' => 'signatory mobile', 'sig_aadhaar' => "signatory's Aadhaar", 'aadhaar_consent' => 'Aadhaar consent',
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'aadhaar_consent.accepted' => 'Please confirm your consent to store the Aadhaar number.',
            'pincode.regex' => 'Enter a valid 6-digit PIN code.',
            'country.required' => 'Enter your nationality (country).',
        ];
    }

    // ------------------------------------------------------------------ validation + normalisation

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> customers columns
     */
    public function validateBasic(CustomerType $type, array $input, bool $includeEmail = true): array
    {
        $data = Validator::make($input, $this->basicRules($type, $includeEmail), self::messages(), self::basicAttributes($type))->validate();
        $out = [
            'sub_category' => $data['sub_category'],
            'name' => self::squash((string) $data['name']),
            'mobile' => $type === CustomerType::Individual ? IdValidator::normalizeMobile((string) $data['mobile']) : self::normalizePhone((string) $data['mobile']),
            'address' => trim((string) $data['address']),
            'city' => self::squash((string) $data['city']),
            'pincode' => (string) $data['pincode'],
            'state_code' => str_pad((string) $data['state_code'], 2, '0', STR_PAD_LEFT),
            'profile' => trim((string) $data['profile']),
        ];
        if ($includeEmail) {
            $out['email'] = mb_strtolower((string) $data['email']);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $customer existing row, or [] + state_code when creating
     * @return array{customer: array<string, mixed>, signatory: array<string, mixed>|null, consent: bool}
     */
    public function validateIdentity(CustomerType $type, array $input, array $customer): array
    {
        $input['state_code'] = $input['state_code'] ?? ($customer['state_code'] ?? '');
        foreach (['pan', 'gstin', 'tan', 'passport_no'] as $k) {
            if (isset($input[$k]) && is_string($input[$k])) {
                $input[$k] = IdValidator::normalize($input[$k]);
            }
        }
        $data = Validator::make($input, $this->identityRules($type, $input, $customer), self::messages(), self::identityAttributes())->validate();
        $consent = ($data['aadhaar_consent'] ?? '') !== '' && ($data['aadhaar_consent'] ?? '') !== '0';

        if ($type === CustomerType::Individual) {
            $foreign = $data['nationality_type'] === 'foreign';
            $cols = [
                'nationality' => $foreign ? self::squash(ucwords(mb_strtolower((string) $data['country']))) : 'Indian',
                'passport_no' => $foreign ? IdValidator::normalize((string) $data['passport_no']) : null,
                'pan' => ($data['pan'] ?? '') !== '' ? IdValidator::normalize((string) $data['pan']) : null,
                'gstin' => ($data['gstin'] ?? '') !== '' ? IdValidator::normalize((string) $data['gstin']) : null,
                'tan' => null,
            ];
            if ($foreign) {
                $cols += ['aadhaar_enc' => null, 'aadhaar_hash' => null, 'aadhaar_last4' => null];
            } elseif (($data['aadhaar'] ?? '') !== '') {
                $cols += $this->vault->protect((string) $data['aadhaar']);
            }
            return ['customer' => $cols, 'signatory' => null, 'consent' => $consent];
        }

        $sig = [
            'name' => self::squash((string) $data['sig_name']),
            'designation' => self::squash((string) $data['sig_designation']),
            'email' => mb_strtolower((string) $data['sig_email']),
            'mobile' => IdValidator::normalizeMobile((string) $data['sig_mobile']),
        ];
        if (($data['sig_aadhaar'] ?? '') !== '') {
            $sig += $this->vault->protect((string) $data['sig_aadhaar']);
        }
        return [
            'customer' => [
                'nationality' => 'Indian',
                'pan' => IdValidator::normalize((string) $data['pan']),
                'gstin' => IdValidator::normalize((string) $data['gstin']),
                'tan' => IdValidator::normalize((string) $data['tan']),
                'passport_no' => null,
            ],
            'signatory' => $sig,
            'consent' => $consent,
        ];
    }

    // ------------------------------------------------------------------ persistence (wizard)

    /**
     * Step 1. Returns true when a verified profile was sent back to "KYC pending".
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $input
     */
    public function saveBasic(array $customer, array $input, HolderType $by, ?int $byId, bool $includeEmail = false): bool
    {
        $cols = $this->validateBasic(Customer::type($customer), $input, $includeEmail);
        $cols['profile_step'] = max(1, (int) $customer['profile_step']);
        return $this->persist($customer, $cols, null, false, $by, $byId, 'profile.basic');
    }

    /**
     * Step 2 (rejects an Aadhaar/PAN/GSTIN that belongs to another visitor).
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $input
     */
    public function saveIdentity(array $customer, array $input, HolderType $by, ?int $byId, ?DuplicateFinder $duplicates = null): bool
    {
        $type = Customer::type($customer);
        $v = $this->validateIdentity($type, $input, $customer);
        if ($duplicates !== null) {
            $this->guardDuplicates($duplicates, $v, (int) $customer['id']);
        }
        $cols = $v['customer'];
        $cols['profile_step'] = max(2, (int) $customer['profile_step']);
        return $this->persist($customer, $cols, $v['signatory'], $v['consent'], $by, $byId, 'profile.identity');
    }

    /** Step 3 is done when the visitor continues from the documents page. */
    public function completeDocuments(int $customerId): void
    {
        $this->db->execute('UPDATE customers SET profile_step = GREATEST(profile_step, 3) WHERE id = ?', [$customerId]);
    }

    /**
     * Online visitors cannot claim an identity already on file (spec 4.2 duplicate check).
     * @param array{customer: array<string, mixed>, signatory: array<string, mixed>|null, consent: bool} $v
     */
    private function guardDuplicates(DuplicateFinder $duplicates, array $v, int $customerId): void
    {
        $errors = [];
        $hash = $v['customer']['aadhaar_hash'] ?? ($v['signatory']['aadhaar_hash'] ?? null);
        $field = isset($v['customer']['aadhaar_hash']) ? 'aadhaar' : 'sig_aadhaar';
        if (is_string($hash) && $duplicates->find(['aadhaar_hash' => $hash], $customerId) !== []) {
            $errors[$field] = ['This Aadhaar number is already registered with another visitor. Please contact the front desk.'];
        }
        foreach (['pan' => 'PAN', 'gstin' => 'GSTIN'] as $col => $label) {
            $value = $v['customer'][$col] ?? null;
            if (is_string($value) && $value !== '' && $duplicates->find([$col => $value], $customerId) !== []) {
                $errors[$col] = ["This {$label} is already registered with another visitor. Please contact the front desk."];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * @param array<string, mixed> $customer
     * @param array<string, mixed> $cols
     * @param array<string, mixed>|null $signatory
     */
    private function persist(array $customer, array $cols, ?array $signatory, bool $consent, HolderType $by, ?int $byId, string $action): bool
    {
        $id = (int) $customer['id'];
        return $this->db->transaction(function (Database $db) use ($id, $customer, $cols, $signatory, $consent, $by, $byId, $action): bool {
            $current = (array) $db->first('SELECT * FROM customers WHERE id = ? FOR UPDATE', [$id]);
            $changed = [];
            foreach (self::KYC_COLUMNS as $col) {
                if (array_key_exists($col, $cols) && (string) ($current[$col] ?? '') !== (string) ($cols[$col] ?? '')) {
                    $changed[] = $col;
                }
            }
            if ($consent && array_key_exists('aadhaar_hash', $cols) || ($consent && $signatory !== null && isset($signatory['aadhaar_hash']))) {
                $cols['consent_at'] = date('Y-m-d H:i:s');
            }
            if ($signatory !== null) {
                $sigChanged = $this->saveSignatory($db, $id, $signatory);
                if ($sigChanged) {
                    $changed[] = 'signatory';
                }
            }
            $reopened = false;
            if ($changed !== [] && $current['kyc_status'] === KycStatus::Verified->value) {
                $cols += ['kyc_status' => KycStatus::Pending->value, 'kyc_submitted_at' => date('Y-m-d H:i:s'), 'kyc_verified_at' => null, 'kyc_verified_by' => null];
                $reopened = true;
            }
            $db->update('customers', $cols, ['id' => $id]);
            if ($changed !== []) {
                $this->audit->record($action, 'customer', $id, ['fields' => $changed], $reopened ? ['kyc_status' => 'pending'] : null,
                    actorType: $by->value, actorId: $byId);
            }
            unset($customer);
            return $reopened;
        });
    }

    /** @param array<string, mixed> $sig */
    private function saveSignatory(Database $db, int $customerId, array $sig): bool
    {
        $existing = $db->first('SELECT * FROM customer_signatories WHERE customer_id = ? ORDER BY is_primary DESC, id LIMIT 1', [$customerId]);
        if ($existing === null) {
            $db->insert('customer_signatories', $sig + ['customer_id' => $customerId, 'is_primary' => 1]);
            return true;
        }
        $changed = false;
        foreach (['name', 'designation', 'email', 'mobile', 'aadhaar_hash'] as $col) {
            if (array_key_exists($col, $sig) && (string) $existing[$col] !== (string) $sig[$col]) {
                $changed = true;
            }
        }
        $db->update('customer_signatories', $sig, ['id' => $existing['id']]);
        return $changed;
    }

    // ------------------------------------------------------------------ documents & completeness

    /**
     * Documents to collect for this visitor (spec 4.3 "Documents").
     *
     * @param array<string, mixed> $customer
     * @return list<array{type: DocumentType, required: bool, hint: string, doc: array<string, mixed>|null}>
     */
    public function documentChecklist(array $customer): array
    {
        $have = CustomerDocument::byType((int) $customer['id']);
        $items = [];
        $add = static function (DocumentType $t, bool $required, string $hint) use (&$items, $have): void {
            $items[] = ['type' => $t, 'required' => $required, 'hint' => $hint, 'doc' => $have[$t->value] ?? null];
        };
        if (Customer::type($customer) === CustomerType::Individual) {
            if (Customer::isForeign($customer)) {
                $add(DocumentType::Passport, true, 'Photo page of your passport (with visa page if applicable).');
            } else {
                $add(DocumentType::Aadhaar, true, 'Please upload a masked Aadhaar — only the last 4 digits visible (download it from myaadhaar.uidai.gov.in).');
            }
            $add(DocumentType::Photo, true, 'A recent passport-size photograph, face clearly visible.');
            $add(DocumentType::Pan, (string) ($customer['pan'] ?? '') !== '', 'Required if you entered a PAN.');
            $add(DocumentType::GstCertificate, (string) ($customer['gstin'] ?? '') !== '', 'Required if you entered a GSTIN.');
        } else {
            $add(DocumentType::SignatoryAadhaar, true, "Authorised signatory's Aadhaar — masked copy preferred.");
            $add(DocumentType::Pan, true, "The institution's PAN card.");
            $add(DocumentType::GstCertificate, true, 'GST registration certificate (Form REG-06).');
            $add(DocumentType::Tan, true, 'TAN allotment letter or proof.');
            $add(DocumentType::Registration, true, 'Certificate of incorporation / registration.');
            $add(DocumentType::AuthorisationLetter, true, 'Board resolution or letter authorising the signatory.');
        }
        $add(DocumentType::Other, false, 'Anything else that supports your application (optional).');
        return $items;
    }

    /**
     * Reasons the profile cannot be submitted yet (empty = ready).
     * @param array<string, mixed> $customer
     * @return array<int, list<string>> step => messages
     */
    public function missing(array $customer, bool $checkDocuments = true): array
    {
        $type = Customer::type($customer);
        $out = [];
        foreach (['sub_category', 'name', 'email', 'mobile', 'address', 'city', 'pincode', 'state_code', 'profile'] as $col) {
            if ((string) ($customer[$col] ?? '') === '') {
                $out[1][] = ucfirst(self::basicAttributes($type)[$col] ?? str_replace('_', ' ', $col)) . ' is missing.';
            }
        }
        if ($type === CustomerType::Individual) {
            if (Customer::isForeign($customer) && (string) $customer['passport_no'] === '') {
                $out[2][] = 'Passport number is missing.';
            }
            if (!Customer::isForeign($customer) && (string) $customer['aadhaar_last4'] === '') {
                $out[2][] = 'Aadhaar number is missing.';
            }
        } else {
            foreach (['pan' => 'PAN', 'gstin' => 'GSTIN', 'tan' => 'TAN'] as $col => $label) {
                if ((string) ($customer[$col] ?? '') === '') {
                    $out[2][] = "{$label} is missing.";
                }
            }
            $sig = CustomerSignatory::primaryFor((int) $customer['id']);
            if ($sig === null || (string) $sig['aadhaar_last4'] === '') {
                $out[2][] = 'Authorised signatory details are missing.';
            }
        }
        if ($checkDocuments) {
            foreach ($this->documentChecklist($customer) as $item) {
                if ($item['required'] && $item['doc'] === null) {
                    $out[3][] = $item['type']->label() . ' has not been uploaded.';
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ submission

    /**
     * Step 4: issue the Unique Visitor ID (first submission only) and queue for KYC verification.
     * Staff with kyc.verify may pass $verify = true to mark it verified straight away (spec 4.2).
     *
     * @param array<string, mixed> $customer
     */
    public function submit(array $customer, HolderType $by, ?int $byId, bool $verify = false, bool $checkDocuments = true): string
    {
        $missing = $this->missing($customer, $checkDocuments);
        if ($missing !== []) {
            throw new ValidationException(['profile' => array_merge(...array_values($missing))]);
        }
        $id = (int) $customer['id'];
        return $this->db->transaction(function (Database $db) use ($id, $by, $byId, $verify): string {
            $row = (array) $db->first('SELECT id, type, unique_id, kyc_status FROM customers WHERE id = ? FOR UPDATE', [$id]);
            $uniqueId = (string) ($row['unique_id'] ?? '');
            if ($uniqueId === '') {
                $uniqueId = $this->ids->next(CustomerType::from((string) $row['type']));
            }
            $now = date('Y-m-d H:i:s');
            $cols = [
                'unique_id' => $uniqueId,
                'kyc_status' => $verify ? KycStatus::Verified->value : KycStatus::Pending->value,
                'kyc_submitted_at' => $now,
                'kyc_remarks' => null,
                'profile_step' => 4,
            ];
            if ($verify) {
                $cols += ['kyc_verified_by' => $byId, 'kyc_verified_at' => $now];
                $db->execute('UPDATE customer_documents SET verified_by = ?, verified_at = ? WHERE customer_id = ? AND verified_at IS NULL', [$byId, $now, $id]);
            }
            $db->update('customers', $cols, ['id' => $id]);
            $this->audit->record('kyc.submit', 'customer', $id, ['kyc_status' => $row['kyc_status']], ['kyc_status' => $cols['kyc_status'], 'unique_id' => $uniqueId],
                actorType: $by->value, actorId: $byId);
            if ($verify) {
                $this->audit->record('kyc.approve', 'customer', $id, null, ['kyc_status' => 'verified'], 'Verified at registration', $by->value, $byId);
            }
            return $uniqueId;
        });
    }

    /**
     * First wizard step the visitor still has to complete (1-4).
     * @param array<string, mixed> $customer
     */
    public static function nextStep(array $customer): int
    {
        return min(4, max(1, (int) ($customer['profile_step'] ?? 0) + 1));
    }

    /**
     * Documents may be changed until KYC is verified.
     * @param array<string, mixed> $customer
     */
    public static function documentsLocked(array $customer): bool
    {
        return ($customer['kyc_status'] ?? '') === KycStatus::Verified->value;
    }

    public static function squash(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    public static function normalizePhone(string $value): string
    {
        return IdValidator::normalizeMobile($value) ?? trim((string) preg_replace('/[^\d+\- ]/', '', $value));
    }
}
