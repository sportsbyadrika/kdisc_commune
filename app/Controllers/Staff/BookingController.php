<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Enums\PaymentKind;
use App\Enums\PaymentMode;
use App\Enums\PaymentRule;
use App\Models\Customer;
use App\Services\Bookings\Actor;
use App\Services\Bookings\BookingDirectory;
use App\Services\Bookings\BookingWorkflow;
use App\Services\Bookings\CheckinService;
use App\Services\Bookings\RenewalService;
use App\Services\Bookings\SeatTransferService;
use App\Services\Bookings\WorkflowException;
use App\Services\Payments\PaymentLedger;
use App\Services\Space\AvailabilityService;
use App\Services\Space\BookingPeriod;
use App\Services\Space\FloorMapService;
use App\Services\Space\MiniMapPresenter;
use App\Support\Clock;

/**
 * Staff bookings console (spec 6.2 / 6.3): tabbed list with filters, the booking detail page and every
 * lifecycle action (approve / reject / confirm / cancel / early exit / check-in / handover / extend).
 * Rules live in the services (BookingWorkflow, CheckinService, SeatTransferService, RenewalService); this
 * controller only maps WorkflowException to a flash message (or 422 JSON).
 */
final class BookingController extends Controller
{
    public function __construct(
        private readonly BookingDirectory $bookings,
        private readonly BookingWorkflow $workflow,
        private readonly PaymentLedger $ledger,
        private readonly CheckinService $checkins,
        private readonly FloorMapService $maps,
        private readonly MiniMapPresenter $mini,
        private readonly AvailabilityService $availability,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        $tab = array_key_exists($request->string('tab'), BookingDirectory::TABS) ? $request->string('tab') : 'requests';
        $filters = [
            'q' => $request->string('q'),
            'category' => $request->string('category'),
            'floor' => $request->string('floor'),
            'source' => $request->string('source'),
            'from' => $request->string('from'),
            'to' => $request->string('to'),
            'within' => $request->string('within', '30'),
        ];
        $today = $this->clock->today();
        $counts = $this->bookings->tabCounts($filters, $today);
        if (!$request->has('tab') && $counts['requests'] === 0) {
            $tab = $counts['payment'] > 0 ? 'payment' : 'all';
        }
        return $this->view('staff/bookings/index', [
            'title' => 'Bookings',
            'tab' => $tab,
            'filters' => $filters,
            'result' => $this->bookings->console($tab, $filters, $today, max(1, $request->int('page', 1))),
            'counts' => $counts,
            'categories' => db()->select('SELECT code, name FROM seat_categories ORDER BY sort_order'),
            'floors' => $this->maps->floors(),
            'today' => $today,
        ]);
    }

    public function show(string $no, RenewalService $renewals): Response
    {
        $booking = $this->find($no);
        $id = (int) $booking['id'];
        $seats = $this->bookings->seats($id);
        $customer = (array) Customer::find((int) $booking['customer_id']);
        $actor = $this->actor();
        $dues = $this->ledger->dues($booking);

        // one mini-map per floor the booking's current seats are on, highlighting them (matched by seat_key)
        $maps = [];
        $current = array_values(array_filter($seats, static fn (array $s) => $s['released_at'] === null || $s['transferred_to_id'] === null));
        $keys = array_map(static fn (array $s) => (int) $s['seat_key'], $current);
        $period = $booking['start_time'] !== null
            ? BookingPeriod::hours((string) $booking['start_date'], substr((string) $booking['start_time'], 0, 5), substr((string) $booking['end_time'], 0, 5))
            : BookingPeriod::days(max((string) $booking['start_date'], min($this->clock->today(), (string) $booking['end_date'])), (string) $booking['end_date']);
        $floorSlugs = [];
        foreach ($this->availability->publishedByKey($keys) as $live) {
            $floorSlugs[(string) $live['floor_slug']] = true;
        }
        foreach (array_keys($floorSlugs) as $slug) {
            $floor = $this->maps->findFloor($slug);
            if ($floor !== null) {
                $maps[] = $this->mini->floor($floor, $period, $keys, ['mode' => 'view']);
            }
        }

        return $this->view('staff/bookings/show', [
            'title' => 'Booking ' . $booking['booking_no'],
            'booking' => $booking,
            'customer' => Customer::safe($customer),
            'seats' => $seats,
            'current' => $this->checkins->currentSeats($id),
            'facilities' => $this->bookings->facilities($id),
            'timeline' => BookingDirectory::timeline($booking),
            'activity' => $this->bookings->activity($id),
            'checkinHistory' => $this->checkins->history($id),
            'dues' => $dues,
            'payments' => $this->ledger->payments($id),
            'outstanding' => $this->ledger->customerOutstanding((int) $booking['customer_id']),
            'actions' => $this->workflow->actions($booking, $actor),
            'renewal' => $renewals->renewalOf($id),
            'renewedFrom' => $booking['renewed_from_id'] !== null ? db()->first('SELECT booking_no, status FROM bookings WHERE id = ?', [(int) $booking['renewed_from_id']]) : null,
            'maps' => $maps,
            'kinds' => PaymentKind::options(),
            'modes' => PaymentMode::deskOptions(),
            'suggestedKind' => ($booking['payment_rule'] === PaymentRule::SecurityDeposit->value && $dues['deposit']['paid'] < $dues['deposit']['due']) ? PaymentKind::Deposit->value : ($booking['payment_rule'] === PaymentRule::SecurityDeposit->value ? PaymentKind::Rent->value : PaymentKind::Advance->value),
            'today' => $this->clock->today(),
        ]);
    }

    public function approve(Request $request, string $no): Response
    {
        $b = $this->find($no);
        return $this->attempt($no, function () use ($b, $request): string {
            $after = $this->workflow->approve((int) $b['id'], $this->actor(), $request->string('note') ?: null);
            return $after['status'] === 'confirmed'
                ? 'Approved and confirmed — the payment was already complete.'
                : sprintf('Approved. The visitor has been emailed to pay by %s.', format_date((string) $after['payment_due_by']));
        });
    }

    public function reject(Request $request, string $no): Response
    {
        $b = $this->find($no);
        return $this->attempt($no, function () use ($b, $request): string {
            $this->workflow->reject((int) $b['id'], $this->actor(), $request->string('reason'));
            return 'Request rejected — the seats were released and the visitor emailed.';
        });
    }

    public function confirm(string $no): Response
    {
        $b = $this->find($no);
        return $this->attempt($no, function () use ($b): string {
            $this->workflow->confirm((int) $b['id'], $this->actor(), 'Confirmed at the front desk');
            return 'Booking confirmed — seats allotted.';
        });
    }

    public function cancel(Request $request, string $no): Response
    {
        $b = $this->find($no);
        return $this->attempt($no, function () use ($b, $request): string {
            $this->workflow->cancel((int) $b['id'], $this->actor(), $request->string('reason'));
            return 'Booking cancelled — the seats were released.';
        });
    }

    public function earlyExit(Request $request, string $no): Response
    {
        $b = $this->find($no);
        return $this->attempt($no, function () use ($b, $request): string {
            $after = $this->workflow->earlyExit((int) $b['id'], $this->actor(), $request->string('release_from'), $request->string('reason'));
            return sprintf('Tenure shortened — the booking now ends on %s. No automatic refund; Finance handles adjustments.', format_date((string) $after['end_date']));
        });
    }

    public function checkIn(Request $request, string $no): Response
    {
        $b = $this->find($no);
        return $this->attempt($no, function () use ($b, $request): string {
            $r = $this->checkins->checkIn((int) $b['id'], $request->int('booking_seat_id') ?: null, $this->actor(), $request->string('method') === 'qr' ? 'qr' : 'desk');
            return sprintf('Checked in: %s.%s', implode(', ', $r['checked_in']) ?: 'already in', $r['activated'] ? ' The booking is now active.' : '');
        }, $request->string('back'));
    }

    public function checkOut(Request $request, string $no): Response
    {
        $b = $this->find($no);
        return $this->attempt($no, function () use ($b, $request): string {
            $r = $this->checkins->checkOut((int) $b['id'], $request->int('booking_seat_id') ?: null, $this->actor());
            return sprintf('Checked out: %s.%s', implode(', ', $r['checked_out']), $r['completed'] ? ' The booking is complete.' : '');
        }, $request->string('back'));
    }

    // ------------------------------------------------------------------ handover

    public function handover(string $no, SeatTransferService $transfers): Response
    {
        $booking = $this->find($no);
        if (!$this->workflow->actions($booking, $this->actor())['handover']) {
            return redirect(url('staff.bookings.show', ['no' => $no]))->with('warning', 'Seats of this booking cannot be handed over.');
        }
        $effective = $transfers->effectiveDate($booking);
        $current = array_values(array_filter($this->bookings->seats((int) $booking['id']), static fn (array $s) => $s['released_at'] === null));
        $keys = array_map(static fn (array $s) => (int) $s['seat_key'], $current);
        $maps = [];
        $live = $this->availability->publishedByKey($keys);
        $slugs = array_values(array_unique(array_merge(array_map(static fn (array $l) => (string) $l['floor_slug'], $live), array_map(static fn (array $f) => (string) $f['slug'], $this->maps->floors()))));
        foreach ($slugs as $slug) {
            $floor = $this->maps->findFloor($slug);
            if ($floor !== null) {
                $maps[] = $this->mini->floor($floor, BookingPeriod::days($effective, (string) $booking['end_date']), $keys, ['mode' => 'pick', 'pickCategory' => $booking['category']]);
            }
        }
        return $this->view('staff/bookings/handover', [
            'title' => 'Hand over seats · ' . $booking['booking_no'],
            'booking' => $booking,
            'current' => $current,
            'maps' => $maps,
            'effective' => $effective,
            'quoteUrl' => url('staff.bookings.handover.quote', ['no' => $no]),
        ]);
    }

    public function handoverQuote(Request $request, string $no, SeatTransferService $transfers): Response
    {
        $booking = $this->find($no);
        return Response::json($transfers->priceDifference($booking, $request->int('booking_seat_id'), $request->int('seat_id')));
    }

    public function handoverStore(Request $request, string $no, SeatTransferService $transfers): Response
    {
        $booking = $this->find($no);
        try {
            $r = $transfers->transfer((int) $booking['id'], [$request->int('booking_seat_id') => $request->int('seat_id')], $this->actor(), $request->string('reason'));
        } catch (WorkflowException $e) {
            return redirect(url('staff.bookings.handover', ['no' => $no]))->with('error', $e->getMessage())->withInput();
        }
        $m = $r['moved'][0];
        return redirect(url('staff.bookings.show', ['no' => $no]))->with('success', sprintf('Seat %s handed over to %s. %s', $m['from'], $m['to'], $m['note'] ?? ''));
    }

    // ------------------------------------------------------------------ extension

    public function extend(Request $request, string $no, RenewalService $renewals): Response
    {
        $booking = $this->find($no);
        try {
            $proposal = $renewals->proposal($booking, $request->string('to') ?: null, (array) $request->input('replace', []));
        } catch (WorkflowException $e) {
            return redirect(url('staff.bookings.show', ['no' => $no]))->with('warning', $e->getMessage());
        }
        return $this->view('staff/bookings/extend', [
            'title' => 'Extend · ' . $booking['booking_no'],
            'booking' => $booking,
            'proposal' => $proposal,
            'quote' => $proposal['quote']?->toArray(),
            'existing' => $renewals->renewalOf((int) $booking['id']),
            'minEnd' => $proposal['from'],
        ]);
    }

    public function extendStore(Request $request, string $no, RenewalService $renewals): Response
    {
        $booking = $this->find($no);
        $replace = (array) $request->input('replace', []);
        try {
            $r = $renewals->extend($booking, $request->string('to'), $replace, $this->actor(), $request->string('notes') ?: null);
        } catch (WorkflowException $e) {
            return redirect(url('staff.bookings.extend', ['no' => $no]) . '?' . http_build_query(['to' => $request->string('to'), 'replace' => $replace]))->with('error', $e->getMessage());
        }
        return redirect(url('staff.bookings.show', ['no' => $r['booking']['booking_no']]))->with('success', sprintf(
            'Extension %s created (%s → %s) at the current rate — log the payment to confirm it.',
            $r['booking']['booking_no'],
            format_date((string) $r['booking']['start_date']),
            format_date((string) $r['booking']['end_date']),
        ));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function find(string $no): array
    {
        return $this->bookings->findByNo($no) ?? throw new NotFoundException('Booking not found.');
    }

    private function actor(): Actor
    {
        return Actor::staff((array) staff());
    }

    /** Run an action; success → flash on the booking page (or $back), WorkflowException → error flash / 422 JSON. */
    private function attempt(string $no, callable $action, string $back = ''): Response
    {
        $request = \App\Core\App::request();
        $target = $this->safeBack($back) ?? url('staff.bookings.show', ['no' => $no]);
        try {
            $message = (string) $action();
        } catch (WorkflowException $e) {
            if ($request?->wantsJson()) {
                return Response::json(['message' => $e->getMessage(), 'link' => $e->link], 422);
            }
            $r = redirect($target)->with('error', $e->getMessage());
            if ($e->kind === 'kyc' && $e->link !== null) {
                $r->with('kyc_link', $e->link);
            }
            return $r;
        }
        if ($request?->wantsJson()) {
            return Response::json(['message' => $message]);
        }
        return redirect($target)->with('success', $message);
    }

    private function safeBack(string $back): ?string
    {
        return $back !== '' && str_starts_with($back, '/staff/') && !str_starts_with($back, '//') ? $back : null;
    }
}
