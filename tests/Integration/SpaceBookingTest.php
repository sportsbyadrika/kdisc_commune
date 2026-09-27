<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Database;
use App\Core\Migrator;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Services\Bookings\BookingService;
use App\Services\Pricing\QuoteService;
use App\Services\Space\AvailabilityService;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SeatHolder;
use App\Services\Space\SeatHoldService;
use App\Services\Space\SpaceRuleException;
use App\Support\Clock;
use Database\Seeds\DatabaseSeeder;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Seat holds, availability overlaps and the checkout double-booking guard against the seeded
 * Kottarakara layout in the commune_test database. Skipped when the database is unreachable.
 */
final class SpaceBookingTest extends TestCase
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
        db()->execute('DELETE FROM seat_holds');
        db()->execute('DELETE FROM booking_facilities');
        db()->execute('DELETE FROM booking_seats');
        db()->execute('DELETE FROM bookings');
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

    private function holds(): SeatHoldService
    {
        /** @var SeatHoldService */
        return App::container()->get(SeatHoldService::class);
    }

    private function availability(): AvailabilityService
    {
        /** @var AvailabilityService */
        return App::container()->get(AvailabilityService::class);
    }

    private function bookings(): BookingService
    {
        /** @var BookingService */
        return App::container()->get(BookingService::class);
    }

    private function seat(string $code): int
    {
        return (int) db()->scalar('SELECT id FROM seats WHERE code = ?', [$code]);
    }

    /** @return array<string, mixed> */
    private function customer(string $state = '32'): array
    {
        static $n = 0;
        $n++;
        $id = db()->insert('customers', [
            'centre_id' => (int) db()->scalar('SELECT id FROM centres LIMIT 1'), 'type' => 'individual',
            'name' => 'Test Visitor ' . $n, 'email' => "visitor{$n}@example.test", 'state_code' => $state, 'kyc_status' => 'pending',
        ]);
        return (array) db()->first('SELECT * FROM customers WHERE id = ?', [$id]);
    }

    private function october(): BookingPeriod
    {
        return BookingPeriod::days('2026-10-01', '2026-10-31');
    }

    // ---------------------------------------------------------------- holds

    public function testHoldConflictsBetweenHolders(): void
    {
        $a = SeatHolder::account(1, 'sess-a');
        $b = SeatHolder::account(2, 'sess-b');
        $id = $this->seat('G-FX-01');
        $res = $this->holds()->hold($a, [$id], $this->october());
        self::assertSame([$id], $res['held']);
        self::assertSame(600, $res['expires_in']);

        $res = $this->holds()->hold($b, [$id], $this->october());
        self::assertSame([], $res['held']);
        self::assertArrayHasKey($id, $res['failed']);

        self::assertSame(AvailabilityService::MINE, $this->availability()->seatStatuses([$id], $this->october(), $a)[$id]);
        self::assertSame(AvailabilityService::HELD, $this->availability()->seatStatuses([$id], $this->october(), $b)[$id]);
        // a different, non-overlapping period is free for B
        $nov = BookingPeriod::days('2026-11-01', '2026-11-30');
        self::assertSame([$id], $this->holds()->hold($b, [$id], $nov)['held']);
    }

    public function testHoldsExpireAndCanBeRenewedOrReleased(): void
    {
        $a = SeatHolder::account(1, 'sess-a');
        $b = SeatHolder::account(2, 'sess-b');
        $id = $this->seat('G-FX-02');
        $this->holds()->hold($a, [$id], $this->october());
        $this->clock()->advance('+9 minutes');
        self::assertNotNull($this->holds()->renew($a));
        $this->clock()->advance('+9 minutes');
        self::assertSame(AvailabilityService::HELD, $this->availability()->seatStatuses([$id], $this->october(), $b)[$id], 'renewed hold still active');
        $this->clock()->advance('+2 minutes');
        self::assertSame(AvailabilityService::AVAILABLE, $this->availability()->seatStatuses([$id], $this->october(), $b)[$id], 'expired holds are ignored');
        self::assertNull($this->holds()->renew($a));
        self::assertSame([$id], $this->holds()->hold($b, [$id], $this->october())['held'], 'expired rows are purged lazily');
        self::assertSame(1, $this->holds()->release($b, $id));
        self::assertNull($this->holds()->selectionPayload($b));
    }

    public function testCabinIsHeldAsAWholeUnit(): void
    {
        $a = SeatHolder::account(1, 'sess-a');
        $b = SeatHolder::account(2, 'sess-b');
        $chair = $this->seat('G-CB-B2');
        $cabin = $this->seat('G-CB-B');
        $res = $this->holds()->hold($a, [$chair], $this->october());
        self::assertSame([$cabin], $res['held'], 'a chair maps to its cabin');
        $statuses = $this->availability()->seatStatuses([$cabin], $this->october(), $b);
        self::assertSame(AvailabilityService::HELD, $statuses[$cabin]);
        self::assertSame([], $this->holds()->hold($b, [$this->seat('G-CB-B3')], $this->october())['held']);

        $this->expectException(SpaceRuleException::class);
        $this->holds()->hold($a, [$this->seat('G-CB-D')], $this->october()); // one cabin per booking
    }

    public function testCategoryRulesAreEnforced(): void
    {
        $a = SeatHolder::account(1, 'sess-a');
        $this->holds()->hold($a, [$this->seat('G-DD-01'), $this->seat('G-DD-02')], $this->october(), 2);
        try {
            $this->holds()->hold($a, [$this->seat('G-DD-03')], $this->october(), 2);
            self::fail('seats needed limit');
        } catch (SpaceRuleException $e) {
            self::assertStringContainsString('2 seats', $e->getMessage());
        }
        try {
            $this->holds()->hold($a, [$this->seat('G-FX-05')], $this->october(), 3);
            self::fail('one category per selection');
        } catch (SpaceRuleException $e) {
            self::assertStringContainsString('Clear it', $e->getMessage());
        }
        try {
            $this->holds()->hold($a, [$this->seat('G-CF-F')], $this->october(), 1, true);
            self::fail('conference needs a time slot');
        } catch (SpaceRuleException $e) {
            self::assertStringContainsString('hour', $e->getMessage());
        }
        $slot = BookingPeriod::hours('2026-10-01', '10:00', '12:00');
        self::assertSame([$this->seat('G-CF-F')], $this->holds()->hold($a, [$this->seat('G-CF-F2')], $slot, 1, true)['held']);
        $this->expectException(SpaceRuleException::class);
        $this->holds()->hold($a, [$this->seat('G-CF-F')], BookingPeriod::hours('2026-10-01', '06:00', '09:00'), 1, true); // before opening
    }

    public function testBlockedSeatsNeedAManagerOverride(): void
    {
        $id = $this->seat('G-FX-06');
        db()->execute("UPDATE seats SET status = 'maintenance', status_from = '2026-10-10', status_to = '2026-10-20' WHERE id = ?", [$id]);
        try {
            $a = SeatHolder::account(1, 'sess-a');
            self::assertSame(AvailabilityService::BLOCKED, $this->availability()->seatStatuses([$id], $this->october())[$id]);
            self::assertSame(AvailabilityService::AVAILABLE, $this->availability()->seatStatuses([$id], BookingPeriod::days('2026-11-01', '2026-11-30'))[$id]);
            self::assertSame([], $this->holds()->hold($a, [$id], $this->october())['held']);
            $manager = SeatHolder::staff(2, 'staff-sess', null, true);
            self::assertSame([$id], $this->holds()->hold($manager, [$id], $this->october(), 1, false, 'Chair repaired early')['held']);
            self::assertSame(1, (int) db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'seat.override' AND entity_id = ?", [$id]));
        } finally {
            db()->execute("UPDATE seats SET status = 'available', status_from = NULL, status_to = NULL WHERE id = ?", [$id]);
        }
    }

    // ---------------------------------------------------------------- availability overlaps

    public function testBookingOverlapAndCabinChairs(): void
    {
        $a = SeatHolder::account(1, 'sess-a');
        $cabin = $this->seat('G-CB-E');
        $this->holds()->hold($a, [$cabin], $this->october());
        $result = $this->bookings()->create($this->customer(), $a, [$cabin], $this->october(), [], BookingSource::Online);
        self::assertMatchesRegularExpression('/^BK-2026-\d{6}$/', (string) $result['booking']['booking_no']);
        self::assertSame(BookingStatus::Requested->value, $result['booking']['status']);
        self::assertSame(0, (int) db()->scalar('SELECT COUNT(*) FROM seat_holds'), 'holds converted');

        $floor = (int) db()->scalar("SELECT id FROM floors WHERE slug = 'ground-floor'");
        $statuses = $this->availability()->floorStatuses($floor, BookingPeriod::days('2026-10-15', '2026-11-15'));
        self::assertSame(AvailabilityService::OCCUPIED, $statuses[$cabin]);
        foreach (['G-CB-E1', 'G-CB-E2', 'G-CB-E3'] as $chair) {
            self::assertSame(AvailabilityService::OCCUPIED, $statuses[$this->seat($chair)], "{$chair} follows its cabin");
        }
        $after = $this->availability()->floorStatuses($floor, BookingPeriod::days('2026-11-01', '2026-11-30'));
        self::assertSame(AvailabilityService::AVAILABLE, $after[$cabin], 'no overlap after the end date');

        db()->execute("UPDATE bookings SET status = 'cancelled'");
        self::assertSame(AvailabilityService::AVAILABLE, $this->availability()->floorStatuses($floor, $this->october())[$cabin], 'cancelled bookings free the seat');
    }

    public function testConferenceOverlapIsByTime(): void
    {
        $a = SeatHolder::account(1, 'sess-a');
        $room = $this->seat('G-CF-F');
        $slot = BookingPeriod::hours('2026-10-02', '10:00', '12:00');
        $this->holds()->hold($a, [$room], $slot, 1, true);
        $this->bookings()->create($this->customer(), $a, [$room], $slot, [], BookingSource::Online);

        $status = fn (string $s, string $e) => $this->availability()->seatStatuses([$room], BookingPeriod::hours('2026-10-02', $s, $e))[$room];
        self::assertSame(AvailabilityService::OCCUPIED, $status('11:00', '13:00'));
        self::assertSame(AvailabilityService::AVAILABLE, $status('12:00', '14:00'), 'back-to-back slots are fine');
        self::assertSame(AvailabilityService::AVAILABLE, $status('08:00', '10:00'));
        $slots = $this->availability()->hourlySlots($room, '2026-10-02');
        self::assertSame([['start' => '10:00', 'end' => '12:00', 'status' => 'occupied']], $slots);
        // day-range browsing never marks the hourly room occupied
        self::assertSame(AvailabilityService::AVAILABLE, $this->availability()->seatStatuses([$room], BookingPeriod::days('2026-10-02', '2026-10-02'))[$room]);
    }

    // ---------------------------------------------------------------- quote + stock

    public function testQuoteServiceUsesRatesStockAndCustomerState(): void
    {
        /** @var QuoteService $quotes */
        $quotes = App::container()->get(QuoteService::class);
        $locker = (int) db()->scalar("SELECT id FROM facilities WHERE code = 'LOCKER'");
        $ids = [$this->seat('G-DD-01'), $this->seat('G-DD-02')];

        // seat override beats the category rate
        db()->insert('rates', ['scope' => 'seat', 'scope_id' => $ids[0], 'unit' => 'month', 'amount' => 6000, 'gst_rate' => 18, 'effective_from' => '2026-01-01']);
        try {
            $q = $quotes->quote($ids, $this->october(), [$locker => 2], '29');
            self::assertSame(11000.0, $q->seatsTotal);
            self::assertSame(600.0, $q->addonsTotal);
            self::assertTrue($q->interState);
            self::assertSame(round(11600 * 0.18, 2), $q->igst);
        } finally {
            db()->execute("DELETE FROM rates WHERE scope = 'seat'");
        }

        db()->execute("UPDATE facilities SET stock_qty = 1 WHERE id = ?", [$locker]);
        try {
            $a = SeatHolder::account(1, 'sess-a');
            $this->holds()->hold($a, [$ids[0]], $this->october());
            $this->bookings()->create($this->customer(), $a, [$ids[0]], $this->october(), [$locker => 1], BookingSource::Online);
            $this->expectException(SpaceRuleException::class);
            $this->expectExceptionMessage('sold out');
            $quotes->quote([$ids[1]], BookingPeriod::days('2026-10-15', '2026-10-31'), [$locker => 1]);
        } finally {
            db()->execute('UPDATE facilities SET stock_qty = 40 WHERE id = ?', [$locker]);
        }
    }

    // ---------------------------------------------------------------- double-booking guard

    public function testCheckoutCannotDoubleBook(): void
    {
        $a = SeatHolder::account(1, 'sess-a');
        $b = SeatHolder::account(2, 'sess-b');
        $id = $this->seat('G-FX-10');

        // A holds, but lets the hold lapse; B holds and books.
        $this->holds()->hold($a, [$id], $this->october());
        $this->clock()->advance('+11 minutes');
        self::assertSame([$id], $this->holds()->hold($b, [$id], $this->october())['held']);
        $this->bookings()->create($this->customer(), $b, [$id], $this->october(), [], BookingSource::Online);

        // A's stale checkout is refused under the row lock — and nothing is written.
        $before = (int) db()->scalar('SELECT COUNT(*) FROM bookings');
        try {
            $this->bookings()->create($this->customer(), $a, [$id], BookingPeriod::days('2026-10-20', '2026-11-20'), [], BookingSource::Online);
            self::fail('double booking must be refused');
        } catch (SpaceRuleException $e) {
            self::assertStringContainsString('just booked', $e->getMessage());
        }
        self::assertSame($before, (int) db()->scalar('SELECT COUNT(*) FROM bookings'));
        self::assertSame(1, (int) db()->scalar('SELECT COUNT(*) FROM booking_seats WHERE seat_id = ?', [$id]));

        // Even a manager override cannot book over an existing booking.
        $manager = SeatHolder::staff(2, 'staff', null, true);
        $this->expectException(SpaceRuleException::class);
        $this->bookings()->create($this->customer(), $manager, [$id], $this->october(), [], BookingSource::Reception, ['override_reason' => 'VIP', 'status' => BookingStatus::Approved]);
    }

    public function testSeatRowsAreLockedDuringCheckout(): void
    {
        // A second connection trying to lock the same seat waits while a checkout transaction holds it.
        $id = $this->seat('G-FX-11');
        $cfg = (array) config('database.connections.' . config('database.default'));
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 3306, $cfg['database'] ?? 'commune_test');
        $other = new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $blocked = false;
        db()->transaction(function (Database $db) use ($id, $other, &$blocked): void {
            $db->select('SELECT id FROM seats WHERE id = ? FOR UPDATE', [$id]);
            $other->beginTransaction();
            try {
                $other->query('SELECT id FROM seats WHERE id = ' . $id . ' FOR UPDATE');
            } catch (\PDOException) {
                $blocked = true;
            }
            $other->rollBack();
        });
        self::assertTrue($blocked, 'concurrent checkout of the same seat waits for the lock');
    }
}
