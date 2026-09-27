<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Migrator;
use App\Enums\BillingUnit;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\RateScope;
use App\Services\Bookings\Actor;
use App\Services\Bookings\BookingService;
use App\Services\Bookings\BookingTicker;
use App\Services\Bookings\BookingWorkflow;
use App\Services\Bookings\CheckinService;
use App\Services\Bookings\RenewalService;
use App\Services\Bookings\SeatTransferService;
use App\Services\Bookings\WorkflowException;
use App\Services\Payments\PaymentLedger;
use App\Services\Payments\PaymentService;
use App\Services\Pricing\RateService;
use App\Services\Space\AvailabilityService;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SeatHolder;
use App\Support\Clock;
use Database\Seeds\DatabaseSeeder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Batch 5: booking state machine (KYC gate, invalid transitions), confirmation rules for ≤ 6 and > 6 months incl.
 * the rent schedule, payments + void, seat handover (seat_key history + availability), extension quoting after a
 * rate change, check-in/out, and bookings:tick idempotency — against the seeded commune_test database.
 */
final class BookingLifecycleTest extends TestCase
{
    private static bool $booted = false;

    public static function setUpBeforeClass(): void
    {
        foreach (['DB_DATABASE' => 'commune_test', 'MAIL_DSN' => 'null://null', 'APP_ENV' => 'testing'] as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        App::boot(dirname(__DIR__, 2));
        Database::setInstance(null);
        try {
            $db = Database::getInstance();
            App::container()->instance(Database::class, $db);
            $db->pdo();
            (new Migrator($db, dirname(__DIR__, 2) . '/database/migrations', static fn () => null))->fresh();
            (new DatabaseSeeder($db, static fn () => null))->run();
            self::$booted = true;
        } catch (\Throwable) {
            self::$booted = false;
        }
    }

    protected function setUp(): void
    {
        if (!self::$booted) {
            self::markTestSkipped('commune_test database not available.');
        }
        foreach (['checkins', 'payments', 'rent_schedules', 'notifications', 'seat_holds', 'booking_facilities', 'booking_seats'] as $t) {
            db()->execute("DELETE FROM {$t}");
        }
        db()->execute('UPDATE bookings SET renewed_from_id = NULL');
        db()->execute('DELETE FROM bookings');
        db()->execute("DELETE FROM rates WHERE effective_from > '2026-01-01'");
        db()->execute("UPDATE rates SET effective_to = NULL WHERE effective_from = '2026-01-01'");
        $this->clock()->freeze(new DateTimeImmutable('2026-09-27 10:00:00'));
    }

    protected function tearDown(): void
    {
        if (self::$booted) {
            $this->clock()->freeze(null);
        }
    }

    private function clock(): Clock
    {
        /** @var Clock */
        return App::container()->get(Clock::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function svc(string $class): object
    {
        /** @var T */
        return App::container()->get($class);
    }

    private function seat(string $code): int
    {
        return (int) db()->scalar(
            "SELECT s.id FROM seats s JOIN zones z ON z.id = s.zone_id JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published' WHERE s.code = ?",
            [$code],
        );
    }

    /** @return array<string, mixed> */
    private function customer(string $kyc = 'verified'): array
    {
        static $n = 0;
        $n++;
        $id = db()->insert('customers', [
            'centre_id' => (int) db()->scalar('SELECT id FROM centres LIMIT 1'), 'type' => 'individual', 'unique_id' => sprintf('CMN-KTR-I-2026-9%04d', $n),
            'name' => 'Lifecycle Visitor ' . $n, 'email' => "life{$n}@example.test", 'mobile' => '+9198470' . sprintf('%05d', $n), 'state_code' => '32', 'kyc_status' => $kyc,
        ]);
        return (array) db()->first('SELECT * FROM customers WHERE id = ?', [$id]);
    }

    private function manager(): Actor
    {
        return Actor::staff((array) db()->first("SELECT * FROM staff_users WHERE role = 'centre_manager' LIMIT 1"));
    }

    private function reception(): Actor
    {
        return Actor::staff((array) db()->first("SELECT * FROM staff_users WHERE role = 'receptionist' LIMIT 1"));
    }

    /**
     * @param list<string> $codes
     * @return array<string, mixed>
     */
    private function book(array $customer, array $codes, string $from, string $to, BookingStatus $status = BookingStatus::Requested): array
    {
        $ids = array_map(fn (string $c) => $this->seat($c), $codes);
        $holder = $status === BookingStatus::Requested ? SeatHolder::account(1, 'sess-' . uniqid()) : SeatHolder::staff(1, 'staff-' . uniqid(), (int) $customer['id']);
        $r = $this->svc(BookingService::class)->create($customer, $holder, $ids, BookingPeriod::days($from, $to), [], $status === BookingStatus::Requested ? BookingSource::Online : BookingSource::Reception, [
            'status' => $status, 'created_by' => $status === BookingStatus::Requested ? null : 1,
        ]);
        return $r['booking'];
    }

    /** @return array<string, mixed> */
    private function fresh(int $id): array
    {
        return $this->svc(BookingWorkflow::class)->find($id);
    }

    /** @param array<string, mixed> $b */
    private function pay(array $b, string $kind, float $amount, string $mode = 'upi', ?Actor $actor = null): array
    {
        return $this->svc(PaymentService::class)->log($this->fresh((int) $b['id']), [
            'kind' => $kind, 'mode' => $mode, 'reference_no' => $mode === 'cash' ? '' : 'UTR' . random_int(100000, 999999), 'amount' => $amount, 'paid_on' => '2026-09-27',
        ], $actor ?? $this->reception());
    }

    // ------------------------------------------------------------------ state machine

    public function testApprovalRequiresVerifiedKyc(): void
    {
        $customer = $this->customer('pending');
        $b = $this->book($customer, ['G-FX-01', 'G-FX-02'], '2026-10-01', '2026-10-31');
        $wf = $this->svc(BookingWorkflow::class);
        try {
            $wf->approve((int) $b['id'], $this->manager());
            self::fail('approval without verified KYC must fail');
        } catch (WorkflowException $e) {
            self::assertSame('kyc', $e->kind);
            self::assertStringContainsString('Verify KYC first', $e->getMessage());
            self::assertNotNull($e->link);
        }
        self::assertSame('requested', $this->fresh((int) $b['id'])['status']);

        db()->update('customers', ['kyc_status' => 'verified'], ['id' => $customer['id']]);
        $after = $wf->approve((int) $b['id'], $this->manager());
        self::assertSame('approved', $after['status']);
        self::assertSame('2026-10-04', $after['payment_due_by'], 'approval_payment_days = 7');
        $audit = db()->first("SELECT * FROM audit_logs WHERE action = 'booking.approved' AND entity_id = ? ORDER BY id DESC", [$b['id']]);
        self::assertNotNull($audit);
        self::assertSame('staff', $audit['actor_type']);
    }

    public function testInvalidTransitionsAndPermissionsAreRejected(): void
    {
        $b = $this->book($this->customer(), ['G-FX-03'], '2026-10-01', '2026-10-10');
        $wf = $this->svc(BookingWorkflow::class);
        foreach ([
            fn () => $wf->confirm((int) $b['id'], $this->manager()),
            fn () => $wf->activate((int) $b['id'], $this->manager()),
            fn () => $wf->complete((int) $b['id'], $this->manager()),
            fn () => $wf->approve((int) $b['id'], $this->reception()), // receptionists cannot approve
            fn () => $wf->reject((int) $b['id'], $this->manager(), 'no'), // reason too short
            fn () => $wf->cancel((int) $b['id'], Actor::visitor(99, 12345), 'not mine'),
        ] as $i => $attempt) {
            try {
                $attempt();
                self::fail("attempt #{$i} should have been rejected");
            } catch (WorkflowException) {
                self::assertSame('requested', $this->fresh((int) $b['id'])['status']);
            }
        }
        $rejected = $wf->reject((int) $b['id'], $this->manager(), 'Seats reserved for an event');
        self::assertSame('rejected', $rejected['status']);
        self::assertNotNull(db()->scalar('SELECT released_at FROM booking_seats WHERE booking_id = ?', [$b['id']]));
        $this->expectException(WorkflowException::class);
        $wf->approve((int) $b['id'], $this->manager());
    }

    public function testVisitorCancelsOwnRequestAndSeatsAreFreed(): void
    {
        $c = $this->customer();
        $b = $this->book($c, ['G-FX-04'], '2026-10-01', '2026-10-10');
        $wf = $this->svc(BookingWorkflow::class);
        $wf->cancel((int) $b['id'], Actor::visitor(1, (int) $c['id']), 'Plans changed');
        self::assertSame('cancelled', $this->fresh((int) $b['id'])['status']);
        $st = $this->svc(AvailabilityService::class)->seatStatuses([$this->seat('G-FX-04')], BookingPeriod::days('2026-10-01', '2026-10-10'));
        self::assertSame(AvailabilityService::AVAILABLE, $st[$this->seat('G-FX-04')]);
    }

    // ------------------------------------------------------------------ confirmation rules

    public function testAdvanceBookingConfirmsOnlyWhenFullyPaid(): void
    {
        $b = $this->book($this->customer(), ['G-FX-05', 'G-FX-06'], '2026-10-01', '2026-10-31');
        $this->svc(BookingWorkflow::class)->approve((int) $b['id'], $this->manager());
        $grand = (float) $b['grand_total'];
        self::assertSame('advance', $b['payment_rule']);

        $r = $this->pay($b, 'advance', 1000);
        self::assertFalse($r['confirmed']);
        self::assertSame('approved', $this->fresh((int) $b['id'])['status']);
        try {
            $this->pay($b, 'advance', $grand); // more than the balance
            self::fail('overpayment must be refused');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        try {
            $this->svc(PaymentService::class)->log($this->fresh((int) $b['id']), ['kind' => 'advance', 'mode' => 'upi', 'reference_no' => '', 'amount' => 10, 'paid_on' => '2026-09-27'], $this->reception());
            self::fail('UPI without a reference must be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('reference_no', $e->errors());
        }
        $r = $this->pay($b, 'advance', round($grand - 1000, 2));
        self::assertTrue($r['confirmed']);
        $after = $this->fresh((int) $b['id']);
        self::assertSame('confirmed', $after['status']);
        self::assertNotNull($after['confirmed_at']);
        self::assertSame(0, (int) db()->scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = ? AND allocated_at IS NULL', [$b['id']]));
    }

    public function testLongBookingNeedsDepositPlusFirstMonthAndHasARentSchedule(): void
    {
        $b = $this->book($this->customer(), ['G-DD-01'], '2026-10-01', '2027-05-31'); // 8 months dedicated
        self::assertSame('security_deposit', $b['payment_rule']);
        $this->svc(BookingWorkflow::class)->approve((int) $b['id'], $this->manager());
        $schedule = db()->select('SELECT * FROM rent_schedules WHERE booking_id = ? ORDER BY period_no', [$b['id']]);
        self::assertCount(8, $schedule);
        self::assertEqualsWithDelta((float) $b['grand_total'], array_sum(array_map(static fn ($p) => (float) $p['amount'], $schedule)), 0.001);
        self::assertSame('2026-11-01', $schedule[1]['due_on']);

        $r = $this->pay($b, 'deposit', (float) $b['deposit_amount'], 'bank_transfer');
        self::assertFalse($r['confirmed'], 'deposit alone is not enough');
        self::assertSame((float) $schedule[0]['amount'], $r['dues']['due_now']);
        $r = $this->pay($b, 'rent', (float) $schedule[0]['amount'], 'cash');
        self::assertTrue($r['confirmed']);
        $dues = $this->svc(PaymentLedger::class)->dues($this->fresh((int) $b['id']));
        self::assertSame('paid', $dues['schedule'][0]['state']);
        self::assertSame(0.0, $dues['due_now']);
    }

    public function testPaymentLoggedBeforeKycVerifiedWaitsForManualConfirm(): void
    {
        $c = $this->customer('pending');
        $b = $this->book($c, ['G-FX-07'], '2026-10-01', '2026-10-05', BookingStatus::Approved); // reception booking
        self::assertSame('2026-10-04', $b['payment_due_by']);
        $r = $this->pay($b, 'advance', (float) $b['grand_total'], 'cash');
        self::assertFalse($r['confirmed']);
        self::assertTrue($r['dues']['confirmation_met']);
        db()->update('customers', ['kyc_status' => 'verified'], ['id' => $c['id']]);
        self::assertSame('confirmed', $this->svc(BookingWorkflow::class)->confirm((int) $b['id'], $this->reception())['status']);
    }

    public function testVoidKeepsThePaymentAndReopensTheBalance(): void
    {
        $b = $this->book($this->customer(), ['G-FX-08'], '2026-10-01', '2026-10-05', BookingStatus::Approved);
        $r = $this->pay($b, 'advance', 500);
        $pid = (int) $r['payment']['id'];
        $svc = $this->svc(PaymentService::class);
        try {
            $svc->void($pid, $this->reception(), 'Duplicate entry');
            self::fail('receptionists cannot void');
        } catch (WorkflowException $e) {
            self::assertSame('permission', $e->kind);
        }
        $void = $svc->void($pid, $this->manager(), 'Duplicate entry');
        self::assertSame('void', $void['status']);
        self::assertSame(1, (int) db()->scalar('SELECT COUNT(*) FROM payments WHERE id = ?', [$pid]), 'never deleted');
        $dues = $this->svc(PaymentLedger::class)->dues($this->fresh((int) $b['id']));
        self::assertSame(0.0, $dues['paid']);
        $this->expectException(WorkflowException::class);
        $svc->void($pid, $this->manager(), 'Again please');
    }

    // ------------------------------------------------------------------ front desk

    public function testCheckInActivatesAndCheckOutOnTheLastDayCompletes(): void
    {
        $b = $this->book($this->customer(), ['G-FX-09'], '2026-09-27', '2026-09-27', BookingStatus::Approved);
        $this->pay($b, 'advance', (float) $b['grand_total'], 'cash');
        $ci = $this->svc(CheckinService::class);
        $r = $ci->checkIn((int) $b['id'], null, $this->reception(), 'qr');
        self::assertTrue($r['activated']);
        self::assertSame('active', $this->fresh((int) $b['id'])['status']);
        $key = (int) db()->scalar('SELECT seat_key FROM seats WHERE id = ?', [$this->seat('G-FX-09')]);
        self::assertArrayHasKey($key, $ci->bySeatKey());
        $r = $ci->checkOut((int) $b['id'], null, $this->reception());
        self::assertTrue($r['completed']);
        self::assertSame('completed', $this->fresh((int) $b['id'])['status']);
    }

    public function testHandoverKeepsHistoryAndMovesOccupancyBySeatKey(): void
    {
        $b = $this->book($this->customer(), ['G-FX-10'], '2026-09-27', '2026-10-31', BookingStatus::Approved);
        db()->update('bookings', ['status' => 'active', 'start_date' => '2026-09-20'], ['id' => $b['id']]);
        db()->update('booking_seats', ['start_date' => '2026-09-20'], ['booking_id' => $b['id']]);
        $this->svc(CheckinService::class)->checkIn((int) $b['id'], null, $this->reception());
        $old = (array) db()->first('SELECT * FROM booking_seats WHERE booking_id = ?', [$b['id']]);
        $target = $this->seat('G-FX-11');

        $diff = $this->svc(SeatTransferService::class)->priceDifference($this->fresh((int) $b['id']), (int) $old['id'], $target);
        self::assertSame(0.0, $diff['diff'], 'same category rate → no difference');
        try {
            $this->svc(SeatTransferService::class)->transfer((int) $b['id'], [(int) $old['id'] => $this->seat('G-DD-02')], $this->manager(), 'Wrong category');
            self::fail('handover to another category must fail');
        } catch (WorkflowException $e) {
            self::assertSame('seats', $e->kind);
        }
        $r = $this->svc(SeatTransferService::class)->transfer((int) $b['id'], [(int) $old['id'] => $target], $this->manager(), 'Visitor wants a window seat');
        self::assertSame('G-FX-10', $r['moved'][0]['from']);
        self::assertSame('G-FX-11', $r['moved'][0]['to']);

        $rows = db()->select('SELECT * FROM booking_seats WHERE booking_id = ? ORDER BY id', [$b['id']]);
        self::assertCount(2, $rows);
        self::assertNotNull($rows[0]['released_at']);
        self::assertSame((int) $rows[1]['id'], (int) $rows[0]['transferred_to_id']);
        self::assertSame('2026-09-26', $rows[0]['end_date'], 'old seat ends the day before the move');
        self::assertSame('2026-09-27', $rows[1]['start_date']);
        self::assertSame((int) db()->scalar('SELECT seat_key FROM seats WHERE id = ?', [$target]), (int) $rows[1]['seat_key']);

        $period = BookingPeriod::days('2026-09-28', '2026-10-31');
        $st = $this->svc(AvailabilityService::class)->seatStatuses([$this->seat('G-FX-10'), $target], $period);
        self::assertSame(AvailabilityService::AVAILABLE, $st[$this->seat('G-FX-10')]);
        self::assertSame(AvailabilityService::OCCUPIED, $st[$target]);
        // the open check-in moved along
        self::assertSame(1, (int) db()->scalar('SELECT COUNT(*) FROM checkins WHERE booking_id = ? AND checked_out_at IS NULL AND booking_seat_id = ?', [$b['id'], $rows[1]['id']]));
    }

    public function testExtensionIsQuotedAtTheRateEffectiveOnItsStartDate(): void
    {
        $c = $this->customer();
        $b = $this->book($c, ['G-DD-03', 'G-DD-04'], '2026-10-01', '2026-10-31', BookingStatus::Approved);
        db()->update('bookings', ['status' => 'confirmed'], ['id' => $b['id']]);
        $dedicated = (int) db()->scalar("SELECT id FROM seat_categories WHERE code = 'DEDICATED'");
        $this->svc(RateService::class)->set(RateScope::Category, [$dedicated], BillingUnit::Month, 6000, 18, '2026-11-01', 2);
        // someone else takes G-DD-04 in November
        $this->book($this->customer(), ['G-DD-04'], '2026-11-05', '2026-11-20', BookingStatus::Approved);

        $renewals = $this->svc(RenewalService::class);
        $booking = $this->svc(\App\Services\Bookings\BookingDirectory::class)->findByNo((string) $b['booking_no']);
        self::assertNotNull($booking);
        $p = $renewals->proposal($booking);
        self::assertSame('2026-11-01', $p['from']);
        self::assertSame('2026-11-30', $p['to']);
        self::assertFalse($p['complete'], 'a taken seat needs a replacement');
        $taken = array_values(array_filter($p['seats'], static fn ($s) => !$s['available']));
        self::assertCount(1, $taken);
        self::assertSame('G-DD-04', $taken[0]['code']);
        $replacement = $taken[0]['alternatives'][0]['id'];

        $r = $renewals->extend($booking, '2026-11-30', [$taken[0]['seat_key'] => $replacement], $this->reception());
        $new = $r['booking'];
        self::assertSame((int) $b['id'], (int) $new['renewed_from_id']);
        self::assertSame('approved', $new['status']);
        self::assertSame('2026-11-01', $new['start_date']);
        $prices = array_map('floatval', db()->column('SELECT unit_price FROM booking_seats WHERE booking_id = ?', [$new['id']]));
        self::assertSame([6000.0, 6000.0], $prices, 'the November rate applies');
        self::assertContains($this->seat('G-DD-03'), array_map('intval', db()->column('SELECT seat_id FROM booking_seats WHERE booking_id = ?', [$new['id']])));
    }

    // ------------------------------------------------------------------ scheduler

    public function testTickIsIdempotent(): void
    {
        $today = '2026-09-27';
        $starts = $this->book($this->customer(), ['G-FX-12'], $today, '2026-10-10', BookingStatus::Approved);
        db()->update('bookings', ['status' => 'confirmed'], ['id' => $starts['id']]);
        $ended = $this->book($this->customer(), ['G-FX-13'], $today, $today, BookingStatus::Approved);
        db()->update('bookings', ['status' => 'active', 'start_date' => '2026-09-01', 'end_date' => '2026-09-26'], ['id' => $ended['id']]);
        $unpaid = $this->book($this->customer(), ['G-FX-14'], '2026-10-01', '2026-10-05', BookingStatus::Approved);
        db()->update('bookings', ['payment_due_by' => '2026-09-26'], ['id' => $unpaid['id']]);
        $ending = $this->book($this->customer(), ['G-FX-15'], $today, '2026-10-10', BookingStatus::Approved);
        db()->update('bookings', ['status' => 'active', 'start_date' => '2026-09-01'], ['id' => $ending['id']]); // 13 days left → 15-day reminder

        $ticker = $this->svc(BookingTicker::class);
        $first = $ticker->run();
        self::assertSame(1, $first['activated']);
        self::assertSame(1, $first['completed']);
        self::assertSame(1, $first['expired']);
        self::assertGreaterThanOrEqual(1, $first['reminders']);
        self::assertSame([], $first['errors']);
        self::assertSame('active', $this->fresh((int) $starts['id'])['status']);
        self::assertSame('completed', $this->fresh((int) $ended['id'])['status']);
        self::assertSame('cancelled', $this->fresh((int) $unpaid['id'])['status']);
        $notes = (int) db()->scalar('SELECT COUNT(*) FROM notifications');

        $second = $ticker->run();
        self::assertSame(['activated' => 0, 'completed' => 0, 'expired' => 0, 'reminders' => 0, 'errors' => []], $second);
        self::assertSame($notes, (int) db()->scalar('SELECT COUNT(*) FROM notifications'));

        // a week later the 7-day reminder goes out once
        $this->clock()->freeze(new DateTimeImmutable('2026-10-03 09:00:00'));
        self::assertGreaterThanOrEqual(1, $ticker->run()['reminders']);
        self::assertSame(0, $ticker->run()['reminders']);
        self::assertSame(1, (int) db()->scalar("SELECT COUNT(*) FROM notifications WHERE dedupe_key LIKE ?", ['renewal.' . $ending['id'] . '.7.%']));
    }
}
