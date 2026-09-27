<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Enums\StaffRole;
use App\Models\Customer;
use App\Services\Space\ExplorerPresenter;
use App\Services\Space\FloorMapService;
use App\Services\Space\SeatHolder;

/**
 * Receptionist mode of the Space Explorer (spec 5.2 / 6.2): the same map inside the staff console, with a
 * visitor picker, occupant details in seat popovers, a manager-only override for blocked/held seats,
 * and direct booking creation (status approved) for the chosen visitor.
 */
final class ExplorerController extends Controller
{
    public function __construct(
        private readonly ExplorerPresenter $presenter,
        private readonly FloorMapService $maps,
        private readonly Session $session,
    ) {
    }

    public function index(Request $request): Response
    {
        $floors = $this->maps->floors();
        $floor = $this->maps->findFloor($request->string('floor') ?: (string) ($floors[0]['slug'] ?? '')) ?? $this->maps->findFloor((string) $floors[0]['slug']);
        $filters = $this->presenter->filters($request->all());
        $staff = (array) App::guard('staff')->user();
        $role = StaffRole::from((string) $staff['role']);
        $customer = $request->int('customer') > 0 ? Customer::find($request->int('customer')) : null;
        $holder = SeatHolder::staff((int) $staff['id'], $this->session->id(), $customer !== null ? (int) $customer['id'] : null, $role->can('space.override'));
        return $this->view('staff/explorer/index', [
            'title' => 'Space Explorer',
            'config' => $this->presenter->floorConfig((array) $floor, $filters, true, $holder, [
                'canOverride' => $role->can('space.override'),
                'canBook' => $role->can('bookings.create'),
                'customersUrl' => url('staff.api.space.customers'),
                'registerVisitorUrl' => $role->can('visitors.register') ? url('staff.visitors.create') : null,
                'canCheckin' => $role->can('checkins.manage'),
                'checkinUrl' => url('staff.checkins.seat'),
                'bookingUrl' => url('staff.bookings.show', ['no' => '__NO__']),
                'customer' => $customer !== null ? [
                    'id' => (int) $customer['id'], 'name' => (string) $customer['name'], 'unique_id' => $customer['unique_id'],
                    'mobile' => format_phone((string) ($customer['mobile'] ?? '')), 'kyc_status' => $customer['kyc_status'], 'state_code' => $customer['state_code'],
                ] : null,
            ]),
        ]);
    }
}
