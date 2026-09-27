<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Enums\CustomerType;
use App\Enums\HolderType;
use App\Enums\KycStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerSignatory;
use App\Services\AuditLog;
use App\Services\Bookings\BookingDirectory;
use App\Services\Payments\PaymentLedger;
use App\Services\Kyc\DocumentStore;
use App\Services\Visitors\DuplicateFinder;
use App\Services\Visitors\ProfileService;
use App\Services\Visitors\RegistrationService;
use App\Services\Visitors\VisitorDirectory;
use App\Support\IndianStates;

/**
 * Assisted registration & visitor records (spec 4.2). Uses the same field partials and the same
 * ProfileService rules as the online wizard, laid out as one long form.
 */
final class VisitorController extends StaffController
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly DuplicateFinder $duplicates,
        private readonly VisitorDirectory $directory,
        private readonly DocumentStore $documents,
        private readonly RegistrationService $registration,
        private readonly AuditLog $audit,
        private readonly Database $db,
        private readonly \App\Services\Visitors\QrCodeRenderer $qr,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = ['q' => $request->string('q'), 'type' => $request->string('type'), 'kyc' => $request->string('kyc')];
        return $this->view('staff/visitors/index', [
            'title' => 'Visitors',
            'subtitle' => 'Search by Unique ID, name, mobile, email, PAN or GSTIN.',
            'filters' => $filters,
            'result' => $this->directory->search($filters, $request->int('page', 1)),
            'counts' => $this->directory->kycCounts(),
            'canRegister' => $this->can('visitors.register'),
        ]);
    }

    public function create(Request $request): Response
    {
        $type = CustomerType::tryFrom($request->string('type', 'individual')) ?? CustomerType::Individual;
        return $this->view('staff/visitors/form', [
            'title' => 'New visitor',
            'subtitle' => 'Assisted registration — the same details and checks as online sign-up.',
            'type' => $type,
            'customer' => ['type' => $type->value, 'state_code' => IndianStates::HOME, 'nationality' => 'Indian', 'id' => 0, 'kyc_status' => KycStatus::NotSubmitted->value],
            'signatory' => null,
            'checklist' => $this->blankChecklist($type),
            'duplicates' => session()->getFlash('duplicates', []),
            'canVerify' => $this->can('kyc.verify'),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): Response
    {
        $type = CustomerType::tryFrom($request->string('type')) ?? CustomerType::Individual;
        $input = $request->all();
        $this->validateAll($type, $input, []);

        $invite = $request->bool('send_invite');
        if ($invite) {
            $existing = Account::findByEmail((string) $input['email']);
            if ($existing !== null && Customer::findByAccount((int) $existing['id']) !== null) {
                throw new ValidationException(['send_invite' => ['This email already has a portal account linked to another visitor.']]);
            }
        }

        $matches = $this->duplicates->find($this->duplicates->criteriaFromInput($input));
        if ($matches !== [] && !$request->bool('confirm_duplicates')) {
            return back()->withInput()->with('duplicates', $matches)
                ->with('warning', 'This visitor may already be registered. Check the matches below before continuing.');
        }

        $basic = $this->profiles->validateBasic($type, $input);
        $identity = $this->profiles->validateIdentity($type, $input + ['state_code' => $basic['state_code']], []);
        $staff = $this->user();

        $customerId = $this->db->transaction(function (Database $db) use ($type, $basic, $identity, $staff): int {
            $id = $db->insert('customers', $basic + $identity['customer'] + [
                'type' => $type->value,
                'centre_id' => RegistrationService::centreId($db, isset($staff['centre_id']) ? (int) $staff['centre_id'] : null),
                'kyc_status' => KycStatus::NotSubmitted->value,
                'registered_via' => 'reception',
                'registered_by' => (int) $staff['id'],
                'profile_step' => 2,
                'consent_at' => $identity['consent'] ? date('Y-m-d H:i:s') : null,
            ]);
            if ($identity['signatory'] !== null) {
                $db->insert('customer_signatories', $identity['signatory'] + ['customer_id' => $id, 'is_primary' => 1]);
            }
            $this->audit->record('visitor.register', 'customer', $id, null, ['type' => $type->value, 'via' => 'reception']);
            return $id;
        });

        $failed = $this->storeUploads($request, $customerId);
        $customer = (array) Customer::find($customerId);
        $this->profiles->completeDocuments($customerId);

        $wantsVerify = $request->bool('mark_verified') && $this->can('kyc.verify');
        $notes = [];
        $missingDocs = $this->profiles->missing($customer)[3] ?? [];
        if ($wantsVerify && $missingDocs !== []) {
            $wantsVerify = false;
            $notes[] = 'Not marked verified — missing: ' . implode(' ', $missingDocs);
        }
        $uniqueId = $this->profiles->submit($customer, HolderType::Staff, $this->staffId(), verify: $wantsVerify, checkDocuments: false);

        if ($invite) {
            $sent = $this->registration->invite((array) Customer::find($customerId), $this->staffId());
            $notes[] = $sent ? 'Portal invite emailed to ' . $basic['email'] . '.' : 'The portal invite could not be sent.';
        }
        if ($failed !== []) {
            $notes[] = 'Some files were not accepted: ' . implode(' ', $failed) . ' Upload them again below.';
        }

        $redirect = redirect(url('staff.visitors.show', ['ref' => $uniqueId]))
            ->with('success', "Visitor registered — Unique ID {$uniqueId}" . ($wantsVerify ? ' (KYC verified).' : ' (KYC pending).'));
        return $notes !== [] ? $redirect->with($failed !== [] || !$wantsVerify && $request->bool('mark_verified') ? 'warning' : 'info', implode(' ', $notes)) : $redirect;
    }

    public function show(string $ref, PaymentLedger $ledger, BookingDirectory $bookings): Response
    {
        $customer = $this->findCustomer($ref);
        $account = $customer['account_id'] !== null ? Account::find((int) $customer['account_id']) : null;
        unset($account['password_hash']);
        return $this->view('staff/visitors/show', [
            'title' => (string) $customer['name'],
            'customer' => Customer::safe($customer),
            'type' => Customer::type($customer),
            'kyc' => Customer::kyc($customer),
            'signatory' => CustomerSignatory::primaryFor((int) $customer['id']),
            'checklist' => $this->profiles->documentChecklist($customer),
            'account' => $account,
            'registeredBy' => $customer['registered_by'] !== null ? $this->db->scalar('SELECT name FROM staff_users WHERE id = ?', [$customer['registered_by']]) : null,
            'verifiedBy' => $customer['kyc_verified_by'] !== null ? $this->db->scalar('SELECT name FROM staff_users WHERE id = ?', [$customer['kyc_verified_by']]) : null,
            'history' => $this->db->select(
                "SELECT a.action, a.actor_type, a.reason, a.created_at, s.name AS staff_name FROM audit_logs a
                 LEFT JOIN staff_users s ON a.actor_type = 'staff' AND s.id = a.actor_id
                 WHERE a.entity_type = 'customer' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 12",
                [$customer['id']],
            ),
            'canEdit' => $this->can('visitors.register'),
            'canUpload' => $this->can('documents.upload') && (!ProfileService::documentsLocked($customer) || $this->can('kyc.verify')),
            'canVerify' => $this->can('kyc.verify'),
            'canViewDocs' => $this->can('documents.view'),
            'qr' => (string) ($customer['unique_id'] ?? '') !== '' ? $this->qr->dataUri((string) $customer['unique_id']) : null,
            'outstanding' => $ledger->customerOutstanding((int) $customer['id']),
            'bookings' => array_slice($bookings->forCustomer((int) $customer['id']), 0, 6),
        ]);
    }

    public function edit(string $ref): Response
    {
        $customer = $this->findCustomer($ref);
        $type = Customer::type($customer);
        return $this->view('staff/visitors/form', [
            'title' => 'Edit visitor',
            'subtitle' => (string) ($customer['unique_id'] ?? 'Profile not yet submitted'),
            'type' => $type,
            'customer' => Customer::safe($customer),
            'signatory' => CustomerSignatory::primaryFor((int) $customer['id']),
            'checklist' => [],
            'duplicates' => [],
            'canVerify' => false,
            'mode' => 'edit',
        ]);
    }

    public function update(Request $request, string $ref): Response
    {
        $customer = $this->findCustomer($ref);
        $type = Customer::type($customer);
        $input = $request->all();
        $this->validateAll($type, $input, $customer);
        $reopenedA = $this->profiles->saveBasic($customer, $input, HolderType::Staff, $this->staffId(), includeEmail: true);
        $customer = (array) Customer::find((int) $customer['id']);
        $reopenedB = $this->profiles->saveIdentity($customer, $input, HolderType::Staff, $this->staffId());
        $redirect = redirect(url('staff.visitors.show', ['ref' => Customer::ref($customer)]))->with('success', 'Visitor details updated.');
        return $reopenedA || $reopenedB ? $redirect->with('warning', 'KYC-relevant details changed, so the profile is back in the KYC queue for re-verification.') : $redirect;
    }

    /** Live duplicate check while the receptionist types (JSON). */
    public function duplicates(Request $request): Response
    {
        $exclude = $request->int('exclude') ?: null;
        $matches = $this->duplicates->find($this->duplicates->criteriaFromInput($request->all()), $exclude);
        return Response::json(['matches' => array_map(static fn (array $m) => [
            'name' => $m['customer']['name'],
            'unique_id' => $m['customer']['unique_id'],
            'type' => CustomerType::from((string) $m['customer']['type'])->label(),
            'kyc' => KycStatus::from((string) $m['customer']['kyc_status'])->label(),
            'fields' => $m['labels'],
            'url' => url('staff.visitors.show', ['ref' => Customer::ref($m['customer'])]),
        ], $matches)]);
    }

    public function invite(string $ref): Response
    {
        $customer = $this->findCustomer($ref);
        if ((string) $customer['email'] === '') {
            return back()->with('error', 'Add an email address before sending a portal invite.');
        }
        $sent = $this->registration->invite($customer, $this->staffId());
        return back()->with($sent ? 'success' : 'warning', $sent
            ? 'Portal invite emailed to ' . $customer['email'] . '.'
            : 'No invite sent: this visitor already uses the portal, or the email belongs to another account.');
    }

    /**
     * Validate basic + identity together so the receptionist sees every problem at once.
     * @param array<string, mixed> $input
     * @param array<string, mixed> $customer
     */
    private function validateAll(CustomerType $type, array $input, array $customer): void
    {
        $input['state_code'] ??= '';
        foreach (['pan', 'gstin', 'tan', 'passport_no'] as $k) {
            if (isset($input[$k]) && is_string($input[$k])) {
                $input[$k] = \App\Services\Kyc\IdValidator::normalize($input[$k]);
            }
        }
        Validator::make(
            $input,
            $this->profiles->basicRules($type) + $this->profiles->identityRules($type, $input, $customer),
            ProfileService::messages(),
            ProfileService::basicAttributes($type) + ProfileService::identityAttributes(),
        )->validate();
    }

    /** @return list<string> human-readable failures */
    private function storeUploads(Request $request, int $customerId): array
    {
        $failed = [];
        foreach (\App\Enums\DocumentType::cases() as $docType) {
            $file = $request->file('doc_' . $docType->value);
            if ($file === null) {
                continue;
            }
            try {
                $this->documents->store($customerId, $docType, $file, HolderType::Staff, $this->staffId(), 'doc_' . $docType->value);
            } catch (ValidationException $e) {
                $failed[] = $docType->label() . ': ' . (array_values($e->errors())[0][0] ?? 'invalid file') ;
            }
        }
        return $failed;
    }

    /**
     * Checklist for a not-yet-created visitor (no documents on file).
     * @return list<array{type: \App\Enums\DocumentType, required: bool, hint: string, doc: null}>
     */
    private function blankChecklist(CustomerType $type): array
    {
        $fake = ['id' => 0, 'type' => $type->value, 'nationality' => 'Indian', 'pan' => '', 'gstin' => ''];
        $items = $this->profiles->documentChecklist($fake);
        if ($type === CustomerType::Individual) {
            // Show the passport slot too; the right one is picked by the nationality toggle.
            array_splice($items, 1, 0, [['type' => \App\Enums\DocumentType::Passport, 'required' => true, 'hint' => 'Photo page of the passport (foreign nationals).', 'doc' => null]]);
        }
        return array_map(static fn (array $i) => ['doc' => null] + $i, $items);
    }
}
