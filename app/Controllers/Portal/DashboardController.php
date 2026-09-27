<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Core\Response;
use App\Models\Customer;
use App\Models\CustomerSignatory;
use App\Services\Visitors\ProfileService;
use App\Services\Visitors\QrCodeRenderer;

/** Visitor portal home (/my) and profile view (/my/profile). */
final class DashboardController extends PortalController
{
    public function __construct(private readonly ProfileService $profiles, private readonly QrCodeRenderer $qr)
    {
    }

    public function index(): Response
    {
        $customer = $this->customer();
        $uniqueId = (string) ($customer['unique_id'] ?? '');
        return $this->view('portal/dashboard', [
            'title' => 'My dashboard',
            'customer' => Customer::safe($customer),
            'account' => $this->account(),
            'type' => Customer::type($customer),
            'kyc' => Customer::kyc($customer),
            'qr' => $uniqueId !== '' ? $this->qr->dataUri($uniqueId) : null,
            'nextStep' => ProfileService::nextStep($customer),
            'missing' => $this->profiles->missing($customer),
        ]);
    }

    public function profile(): Response
    {
        $customer = $this->customer();
        return $this->view('portal/profile', [
            'title' => 'My profile',
            'customer' => Customer::safe($customer),
            'type' => Customer::type($customer),
            'kyc' => Customer::kyc($customer),
            'signatory' => CustomerSignatory::primaryFor((int) $customer['id']),
            'checklist' => $this->profiles->documentChecklist($customer),
        ]);
    }
}
