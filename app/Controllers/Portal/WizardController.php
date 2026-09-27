<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Core\Request;
use App\Core\Response;
use App\Enums\HolderType;
use App\Enums\KycStatus;
use App\Models\Customer;
use App\Models\CustomerSignatory;
use App\Services\Visitors\DuplicateFinder;
use App\Services\Visitors\ProfileService;
use App\Services\Notify\Mailer;

/**
 * Profile wizard /my/profile/wizard/{1-4} (spec 4.1 step 4): saved at every step and resumable.
 * Step 3 uploads go through Portal\DocumentController; step 4 issues the Unique Visitor ID.
 */
final class WizardController extends PortalController
{
    public function __construct(private readonly ProfileService $profiles)
    {
    }

    public function start(): Response
    {
        return redirect(url('portal.wizard', ['step' => ProfileService::nextStep($this->customer())]));
    }

    public function show(int $step): Response
    {
        $customer = $this->customer();
        $maxStep = ProfileService::nextStep($customer);
        if ((int) $customer['profile_step'] >= 4) {
            $maxStep = 4;
        }
        if ($step > $maxStep) {
            return redirect(url('portal.wizard', ['step' => $maxStep]))->with('info', 'Please complete this step first.');
        }
        return $this->view('portal/wizard', [
            'title' => 'Complete your profile',
            'step' => $step,
            'maxStep' => $maxStep,
            'customer' => Customer::safe($customer),
            'type' => Customer::type($customer),
            'kyc' => Customer::kyc($customer),
            'signatory' => CustomerSignatory::primaryFor((int) $customer['id']),
            'checklist' => $this->profiles->documentChecklist($customer),
            'missing' => $step === 4 ? $this->profiles->missing($customer) : [],
            'locked' => ProfileService::documentsLocked($customer),
            'accountEmail' => (string) ($this->account()['email'] ?? ''),
        ]);
    }

    public function save(Request $request, int $step, DuplicateFinder $duplicates, Mailer $mailer): Response
    {
        $customer = $this->customer();
        $by = HolderType::Account;
        $reopened = false;
        switch ($step) {
            case 1:
                $input = $request->all();
                $input['email'] = $customer['email'] ?? ($this->account()['email'] ?? '');
                $reopened = $this->profiles->saveBasic($customer, $input, $by, $this->accountId(), includeEmail: false);
                break;
            case 2:
                $reopened = $this->profiles->saveIdentity($customer, $request->all(), $by, $this->accountId(), $duplicates);
                break;
            case 3:
                $missing = $this->profiles->missing($customer)[3] ?? [];
                if ($missing !== [] && $customer['kyc_status'] !== KycStatus::Verified->value) {
                    return redirect(url('portal.wizard', ['step' => 3]))->withErrors(['documents' => $missing]);
                }
                $this->profiles->completeDocuments((int) $customer['id']);
                break;
            case 4:
                $this->validate($request, ['declaration' => 'accepted'], ['declaration.accepted' => 'Please confirm the declaration to submit.']);
                $first = (string) ($customer['unique_id'] ?? '') === '';
                $wasPending = $customer['kyc_status'] === KycStatus::Pending->value;
                $uniqueId = $this->profiles->submit($customer, $by, $this->accountId());
                if (!$wasPending) {
                    $mailer->send([(string) ($this->account()['email'] ?? $customer['email']), (string) $customer['name']], 'We received your Commune profile', 'kyc-submitted', [
                        'name' => (string) $customer['name'], 'uniqueId' => $uniqueId, 'portalUrl' => absolute_url('portal.dashboard'),
                    ]);
                }
                return redirect(url('portal.dashboard'))->with('success', $first
                    ? "Profile submitted! Your Unique Visitor ID is {$uniqueId}. Our Centre Manager will verify your KYC shortly."
                    : 'Profile re-submitted for verification.');
        }
        $next = min(4, $step + 1);
        $redirect = redirect(url('portal.wizard', ['step' => $next]));
        if ($reopened) {
            return $redirect->with('warning', 'Your changes were saved. Because your profile was already verified, it has gone back to “KYC pending” until the Centre Manager re-verifies it.');
        }
        return $redirect->with('success', ProfileService::STEPS[$step] . ' saved.');
    }
}
