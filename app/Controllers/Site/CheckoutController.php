<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Controllers\Controller;
use App\Core\App;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\KycStatus;
use App\Models\Customer;
use App\Services\Bookings\BookingDirectory;
use App\Services\Bookings\BookingNotifier;
use App\Services\Bookings\BookingService;
use App\Services\Notify\Mailer;
use App\Services\Pricing\QuoteService;
use App\Services\Space\SeatHolder;
use App\Services\Space\SeatHoldService;
use App\Services\Space\SpaceRuleException;
use App\Services\Visitors\ProfileService;

/**
 * Checkout review → booking request (spec 1.2 / 5): the visitor's held seats + chosen add-ons are
 * re-quoted, the visitor accepts the terms and submits; BookingService re-checks availability under
 * row locks and creates a `requested` booking. Profiles must be submitted for KYC first (pending is fine;
 * confirmation later needs verified KYC).
 */
final class CheckoutController extends Controller
{
    private const CART = 'space.cart';

    public function __construct(
        private readonly SeatHoldService $holds,
        private readonly QuoteService $quotes,
        private readonly BookingService $bookings,
        private readonly BookingDirectory $directory,
        private readonly Mailer $mailer,
        private readonly Session $session,
        private readonly BookingNotifier $notifier,
    ) {
    }

    /** POST from the explorer drawer: remember add-ons, then show the review page. */
    public function start(Request $request): Response
    {
        $addons = json_decode($request->string('addons', '{}'), true);
        $this->session->put(self::CART, [
            'addons' => is_array($addons) ? array_map('intval', array_filter($addons, 'is_numeric')) : [],
            'back' => $this->safeBack($request->string('back')),
            'renew' => preg_match('/^[A-Za-z0-9-]{1,30}$/', $request->string('renew')) === 1 ? $request->string('renew') : null,
        ]);
        return redirect(url('spaces.checkout'));
    }

    public function show(): Response
    {
        $holder = $this->holder();
        $selection = $this->holds->current($holder);
        $cart = (array) $this->session->get(self::CART, []);
        $back = (string) ($cart['back'] ?? url('spaces.explore'));
        if ($selection['units'] === [] || $selection['period'] === null) {
            return redirect($back)->with('warning', 'Your seat hold has expired or your selection is empty — please pick your seats again.');
        }
        $customer = $this->customer();
        $kyc = KycStatus::tryFrom((string) ($customer['kyc_status'] ?? '')) ?? KycStatus::NotSubmitted;
        try {
            $quote = $this->quotes->quote(
                array_map(static fn (array $u) => (int) $u['seat_id'], $selection['units']),
                $selection['period'],
                (array) ($cart['addons'] ?? []),
                (string) ($customer['state_code'] ?? ''),
            );
        } catch (SpaceRuleException $e) {
            return redirect($back)->with('error', $e->getMessage());
        }
        return $this->view('site/checkout/show', [
            'title' => 'Review & request',
            'selection' => $selection,
            'quote' => $quote,
            'customer' => Customer::safe($customer),
            'kyc' => $kyc,
            'canRequest' => in_array($kyc, [KycStatus::Pending, KycStatus::Verified], true),
            'wizardUrl' => url('portal.wizard', ['step' => ProfileService::nextStep($customer)]),
            'back' => $back,
        ]);
    }

    public function submit(Request $request): Response
    {
        $this->validate($request, ['terms' => 'accepted', 'notes' => 'nullable|string|max:1000'], ['terms.accepted' => 'Please accept the terms to send your request.']);
        $holder = $this->holder();
        $selection = $this->holds->current($holder);
        $cart = (array) $this->session->get(self::CART, []);
        $back = (string) ($cart['back'] ?? url('spaces.explore'));
        if ($selection['units'] === [] || $selection['period'] === null) {
            return redirect($back)->with('warning', 'Your seat hold expired before the request was sent — please pick your seats again.');
        }
        $customer = $this->customer();
        $kyc = KycStatus::tryFrom((string) ($customer['kyc_status'] ?? '')) ?? KycStatus::NotSubmitted;
        if (!in_array($kyc, [KycStatus::Pending, KycStatus::Verified], true)) {
            return redirect(url('spaces.checkout'))->with('error', 'Please complete and submit your profile & KYC before requesting a booking.');
        }
        try {
            $result = $this->bookings->create(
                $customer,
                $holder,
                array_map(static fn (array $u) => (int) $u['seat_id'], $selection['units']),
                $selection['period'],
                (array) ($cart['addons'] ?? []),
                BookingSource::Online,
                [
                    'status' => BookingStatus::Requested,
                    'terms' => true,
                    'notes' => $request->string('notes') !== '' ? $request->string('notes') : null,
                    'requested_by' => $holder->id,
                    'renewed_from_id' => $this->renewedFrom($cart, (int) $customer['id']),
                ],
            );
        } catch (SpaceRuleException $e) {
            return redirect($back)->with('error', $e->getMessage());
        }
        $this->session->forget(self::CART);
        $booking = $result['booking'];
        $email = (string) ($customer['email'] ?? App::guard('visitor')->user()['email'] ?? '');
        if ($email !== '') {
            $this->mailer->send([$email, (string) $customer['name']], 'Booking request ' . $booking['booking_no'] . ' received', 'booking-requested', [
                'name' => (string) $customer['name'],
                'booking' => $booking,
                'quote' => $result['quote']->toArray(),
                'url' => absolute_url('portal.bookings.show', ['no' => $booking['booking_no']]),
            ]);
        }
        $this->notifier->requested($booking);
        logger()->info('Online booking request {no} from {customer} ({seats}) — awaiting Centre Manager approval', [
            'no' => $booking['booking_no'],
            'customer' => $customer['unique_id'] ?? $customer['id'],
            'seats' => implode(', ', array_column($result['quote']->seats, 'code')),
        ]);
        return redirect(url('spaces.checkout.done', ['no' => $booking['booking_no']]));
    }

    public function done(string $no): Response
    {
        $customer = $this->customer();
        $booking = $this->directory->findByNo($no, (int) $customer['id']) ?? throw new NotFoundException();
        return $this->view('site/checkout/done', [
            'title' => 'Request sent',
            'booking' => $booking,
            'seats' => $this->directory->seats((int) $booking['id']),
            'facilities' => $this->directory->facilities((int) $booking['id']),
            'customer' => Customer::safe($customer),
        ]);
    }

    /**
     * Renewal link from the explorer (?renew=) — only for the visitor's own booking.
     *
     * @param array<string, mixed> $cart
     */
    private function renewedFrom(array $cart, int $customerId): ?int
    {
        $no = (string) ($cart['renew'] ?? '');
        $b = $no !== '' ? $this->directory->findByNo($no, $customerId) : null;
        return $b !== null ? (int) $b['id'] : null;
    }

    private function holder(): SeatHolder
    {
        return SeatHolder::account((int) App::guard('visitor')->id(), $this->session->id());
    }

    /** @return array<string, mixed> */
    private function customer(): array
    {
        return Customer::findByAccount((int) App::guard('visitor')->id()) ?? throw new NotFoundException('No visitor profile is linked to this account.');
    }

    private function safeBack(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);
        return str_starts_with($path, '/spaces/explore') ? $path . ($query !== '' ? '?' . $query : '') : url('spaces.explore');
    }
}
