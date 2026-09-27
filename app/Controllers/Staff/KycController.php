<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Enums\KycStatus;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\CustomerSignatory;
use App\Services\Kyc\KycReviewService;
use App\Services\Visitors\ProfileService;
use App\Services\Visitors\VisitorDirectory;

/** Centre Manager KYC verification queue (spec 4.1 step 5): side-by-side documents + data, approve / reject. */
final class KycController extends StaffController
{
    public function __construct(
        private readonly VisitorDirectory $directory,
        private readonly KycReviewService $review,
        private readonly ProfileService $profiles,
    ) {
    }

    public function index(Request $request): Response
    {
        $status = KycStatus::tryFrom($request->string('status', 'pending')) ?? KycStatus::Pending;
        if ($status === KycStatus::NotSubmitted) {
            $status = KycStatus::Pending;
        }
        return $this->view('staff/kyc/index', [
            'title' => 'KYC verification',
            'subtitle' => 'Oldest submissions first. Check each document against the details, then approve or reject with a reason.',
            'status' => $status,
            'rows' => $this->directory->queue($status),
            'counts' => $this->directory->kycCounts(),
        ]);
    }

    public function show(int $id): Response
    {
        $customer = Customer::find($id) ?? throw new NotFoundException();
        $next = null;
        foreach ($this->directory->queue(KycStatus::Pending) as $row) {
            if ((int) $row['id'] !== $id) {
                $next = (int) $row['id'];
                break;
            }
        }
        return $this->view('staff/kyc/show', [
            'title' => 'Review KYC',
            'hideTitle' => true,
            'customer' => Customer::safe($customer),
            'type' => Customer::type($customer),
            'kyc' => Customer::kyc($customer),
            'signatory' => CustomerSignatory::primaryFor($id),
            'documents' => CustomerDocument::forCustomer($id),
            'checklist' => $this->profiles->documentChecklist($customer),
            'missing' => $this->profiles->missing($customer),
            'nextId' => $next,
        ]);
    }

    public function approve(Request $request, int $id): Response
    {
        $data = $this->validate($request, ['remarks' => 'nullable|string|max:500']);
        $this->review->approve($id, $this->staffId(), ($data['remarks'] ?? '') !== '' ? (string) $data['remarks'] : null);
        return $this->afterDecision($id, 'KYC approved — the visitor has been notified by email.');
    }

    public function reject(Request $request, int $id): Response
    {
        $data = $this->validate($request, ['reason' => 'required|string|min:10|max:500'], ['reason.required' => 'Tell the visitor what to fix.'], ['reason' => 'reason']);
        $this->review->reject($id, $this->staffId(), (string) $data['reason']);
        return $this->afterDecision($id, 'KYC rejected — the visitor has been emailed the reason.');
    }

    private function afterDecision(int $id, string $message): Response
    {
        foreach ($this->directory->queue(KycStatus::Pending, 1) as $row) {
            return redirect(url('staff.kyc.show', ['id' => (int) $row['id']]))->with('success', $message . ' Next in queue ↓');
        }
        unset($id);
        return redirect(url('staff.kyc.index'))->with('success', $message . ' The queue is empty.');
    }
}
