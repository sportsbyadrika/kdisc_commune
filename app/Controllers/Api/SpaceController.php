<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\App;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\StaffRole;
use App\Models\Customer;
use App\Services\Bookings\BookingService;
use App\Services\Pricing\QuoteService;
use App\Services\Space\AvailabilityService;
use App\Services\Space\BookingPeriod;
use App\Services\Space\FloorMapService;
use App\Services\Space\SeatHolder;
use App\Services\Space\SeatHoldService;
use App\Services\Space\SpaceRuleException;
use App\Services\Visitors\VisitorDirectory;
use InvalidArgumentException;

/**
 * Space Explorer JSON API (spec 5). Mounted twice so each audience uses its own session cookie:
 *   /api/space/*        site session  — visitors (guests may read; holds need a signed-in visitor)
 *   /staff/api/space/*  staff session — receptionist mode (auth.staff + can:space.explore)
 * Mutations are CSRF-protected (X-CSRF-TOKEN header). Rule violations return 422 {message}.
 */
final class SpaceController extends Controller
{
    public function __construct(
        private readonly FloorMapService $maps,
        private readonly AvailabilityService $availability,
        private readonly SeatHoldService $holds,
        private readonly QuoteService $quotes,
        private readonly Session $session,
    ) {
    }

    // ------------------------------------------------------------------ read

    /** GET building?from&to — Level 1 numbers per floor. */
    public function building(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $period = BookingPeriod::days(...$this->dates($request));
            $summary = $this->availability->floorSummary($period, $this->holder($request));
            $floors = [];
            foreach ($this->maps->floors() as $f) {
                $floors[] = ['slug' => $f['slug'], 'name' => $f['name'], 'level' => (int) $f['level']] + ($summary[(int) $f['id']] ?? ['chairs' => 0, 'free' => 0, 'by_category' => []]);
            }
            return ['period' => $period->toArray(), 'floors' => $floors];
        });
    }

    /** GET floors/{floor}/map?from&to&start_time&end_time */
    public function map(Request $request, string $floor): Response
    {
        return $this->guard(function () use ($request, $floor): array {
            $row = $this->maps->findFloor($floor) ?? throw new NotFoundException('Floor not found.');
            $holder = $this->holder($request);
            $data = $this->maps->map($row, $this->period($request), $holder, $this->isStaff($request));
            return $data + ['floors' => $this->maps->floors(), 'selection' => $holder !== null ? $this->selection($holder) : null];
        });
    }

    /** GET availability?floor&from&to&start_time&end_time&version — lightweight poll. */
    public function availability(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $row = $this->maps->findFloor($request->string('floor')) ?? throw new NotFoundException('Floor not found.');
            $holder = $this->holder($request);
            $since = $request->string('version');
            return $this->maps->poll($row, $this->period($request), $holder, $since !== '' ? $since : null)
                + ['selection' => $holder !== null ? $this->selection($holder) : null];
        });
    }

    /** GET holds — the caller's current selection (restores the drawer after a reload). */
    public function current(Request $request): Response
    {
        return $this->guard(fn (): array => ['selection' => $this->selection($this->requireHolder($request))]);
    }

    // ------------------------------------------------------------------ holds

    /** POST holds {seat_ids[], from, to, start_time?, end_time?, seats_needed, replace?, override_reason?, customer_id?} */
    public function hold(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $holder = $this->requireHolder($request);
            $ids = array_values(array_filter(array_map('intval', (array) $request->input('seat_ids', [])), static fn (int $i) => $i > 0));
            $reason = $request->string('override_reason');
            $result = $this->holds->hold(
                $holder,
                $ids,
                $this->period($request),
                max(1, $request->int('seats_needed', 1)),
                $request->bool('replace'),
                $reason !== '' ? mb_substr($reason, 0, 500) : null,
            );
            return $result + ['selection' => $this->selection($holder)];
        });
    }

    /** DELETE holds/{seat} — release one unit (a chair id releases its cabin). */
    public function release(Request $request, int $seat): Response
    {
        return $this->guard(function () use ($request, $seat): array {
            $holder = $this->requireHolder($request);
            $this->holds->release($holder, $seat);
            return ['released' => $seat, 'selection' => $this->selection($holder)];
        });
    }

    /** DELETE holds — clear the whole selection. */
    public function releaseAll(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $holder = $this->requireHolder($request);
            return ['released' => $this->holds->release($holder), 'selection' => $this->selection($holder)];
        });
    }

    /** POST holds/renew — another hold period for the whole selection. */
    public function renew(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $holder = $this->requireHolder($request);
            $expires = $this->holds->renew($holder);
            if ($expires === null) {
                throw new SpaceRuleException('Your hold has expired — please pick your seats again.');
            }
            return ['selection' => $this->selection($holder)];
        });
    }

    // ------------------------------------------------------------------ quote

    /** POST quote {seat_ids?[] (default: current holds), from, to, start_time?, end_time?, addons{facility_id: qty}, customer_id?} */
    public function quote(Request $request): Response
    {
        return $this->guard(function () use ($request): array {
            $holder = $this->holder($request);
            $ids = array_values(array_filter(array_map('intval', (array) $request->input('seat_ids', []))));
            if ($ids === [] && $holder !== null) {
                $ids = array_map(static fn (array $u) => (int) $u['seat_id'], $this->holds->current($holder)['units']);
            }
            $addons = (array) $request->input('addons', []);
            return ['quote' => $this->quotes->quote($ids, $this->period($request), $addons, $this->customerStateCode($request))->toArray()];
        });
    }

    // ------------------------------------------------------------------ reception (staff API only)

    /** GET customers?q= — visitor picker (Unique ID / name / mobile / email / PAN / GSTIN). */
    public function customers(Request $request, VisitorDirectory $directory): Response
    {
        $q = $request->string('q');
        if (mb_strlen($q) < 2) {
            return Response::json(['results' => []]);
        }
        $rows = $directory->search(['q' => $q], 1, 8)['rows'];
        return Response::json(['results' => array_map(static fn (array $c) => [
            'id' => (int) $c['id'],
            'name' => (string) $c['name'],
            'unique_id' => $c['unique_id'],
            'mobile' => format_phone((string) ($c['mobile'] ?? '')),
            'email' => $c['email'] ?? null,
            'type' => $c['type'] ?? null,
            'kyc_status' => $c['kyc_status'] ?? null,
            'state_code' => $c['state_code'] ?? null,
        ], $rows)]);
    }

    /** POST book {customer_id, from, to, start_time?, end_time?, addons{}, notes?, override_reason?} — reception booking (status approved). */
    public function book(Request $request, BookingService $bookings): Response
    {
        return $this->guard(function () use ($request, $bookings): array {
            $holder = $this->requireHolder($request);
            $customer = Customer::find($request->int('customer_id')) ?? throw new SpaceRuleException('Choose the visitor this booking is for.');
            $selection = $this->holds->current($holder);
            if ($selection['units'] === [] || $selection['period'] === null) {
                throw new SpaceRuleException('Your hold has expired — please pick the seats again.');
            }
            $reason = $request->string('override_reason');
            $result = $bookings->create(
                $customer,
                $holder,
                array_map(static fn (array $u) => (int) $u['seat_id'], $selection['units']),
                $selection['period'],
                (array) $request->input('addons', []),
                BookingSource::Reception,
                [
                    'status' => BookingStatus::Approved,
                    'override_reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
                    'notes' => $request->string('notes') !== '' ? mb_substr($request->string('notes'), 0, 1000) : null,
                    'created_by' => $holder->id,
                    'terms' => true,
                ],
            );
            $b = $result['booking'];
            logger()->info('Reception booking {no} created for {customer} by staff #{staff}', ['no' => $b['booking_no'], 'customer' => $customer['unique_id'] ?? $customer['id'], 'staff' => $holder->id]);
            return [
                'booking' => ['id' => (int) $b['id'], 'booking_no' => $b['booking_no'], 'status' => $b['status'], 'grand_total' => (float) $b['grand_total']],
                'url' => url('staff.bookings.show', ['no' => $b['booking_no']]),
                'message' => sprintf('Booking %s created for %s.', $b['booking_no'], $customer['name']),
            ];
        });
    }

    // ------------------------------------------------------------------ helpers

    private function isStaff(Request $request): bool
    {
        return str_starts_with($request->path(), '/staff/');
    }

    /** Signed-in holder for this request (null for guests). */
    private function holder(Request $request): ?SeatHolder
    {
        if ($this->isStaff($request)) {
            $staff = App::guard('staff')->user();
            if ($staff === null) {
                return null;
            }
            $customerId = $request->int('customer_id');
            return SeatHolder::staff((int) $staff['id'], $this->session->id(), $customerId > 0 ? $customerId : null, StaffRole::from((string) $staff['role'])->can('space.override'));
        }
        $id = App::guard('visitor')->id();
        return $id !== null && App::guard('visitor')->check() ? SeatHolder::account($id, $this->session->id()) : null;
    }

    private function requireHolder(Request $request): SeatHolder
    {
        $holder = $this->holder($request) ?? throw new GuestException();
        if ($holder->isStaff() && !StaffRole::from((string) (App::guard('staff')->user()['role'] ?? ''))->can('bookings.create')) {
            throw new SpaceRuleException('Your role can view the map but not hold or book seats.');
        }
        return $holder;
    }

    private function customerStateCode(Request $request): ?string
    {
        if ($this->isStaff($request)) {
            $c = $request->int('customer_id') > 0 ? Customer::find($request->int('customer_id')) : null;
            return $c !== null ? (string) ($c['state_code'] ?? '') : null;
        }
        $id = App::guard('visitor')->id();
        $c = $id !== null ? Customer::findByAccount($id) : null;
        return $c !== null ? (string) ($c['state_code'] ?? '') : null;
    }

    /** @return array{0: string, 1: string} */
    private function dates(Request $request): array
    {
        $from = $request->string('from') ?: date('Y-m-d');
        $to = $request->string('to') ?: $from;
        return [$from, $to];
    }

    private function period(Request $request): BookingPeriod
    {
        [$from, $to] = $this->dates($request);
        return BookingPeriod::fromInput(['from' => $from, 'to' => $to, 'start_time' => $request->string('start_time'), 'end_time' => $request->string('end_time')]);
    }

    /** @return array<string, mixed>|null */
    private function selection(SeatHolder $holder): ?array
    {
        return $this->holds->selectionPayload($holder);
    }

    /** Runs an action, mapping rule errors to 422 and guests to 401 JSON. */
    private function guard(\Closure $action): Response
    {
        try {
            $out = $action();
            return Response::json($out)->header('Cache-Control', 'no-store');
        } catch (GuestException) {
            return Response::json([
                'message' => 'Sign in to pick seats — it takes a minute to create an account.',
                'login_url' => url('portal.login'),
                'register_url' => url('portal.register'),
            ], 401);
        } catch (SpaceRuleException | InvalidArgumentException $e) {
            return Response::json(['message' => $e->getMessage()], 422);
        }
    }
}
