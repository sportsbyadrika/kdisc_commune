<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Migrator;
use App\Enums\BillingUnit;
use App\Enums\BookingSource;
use App\Enums\RateScope;
use App\Services\Bookings\BookingDirectory;
use App\Services\Bookings\BookingService;
use App\Services\Layout\FacilityService;
use App\Services\Layout\LayoutDraftService;
use App\Services\Layout\LayoutException;
use App\Services\Layout\LayoutPublisher;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\RateService;
use App\Services\Space\AvailabilityService;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SeatHolder;
use App\Services\Space\SeatHoldService;
use App\Support\Clock;
use Database\Seeds\DatabaseSeeder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Layout & Pricing Designer: draft clone / save / publish keeps bookings valid, publish validation,
 * effective-dated rates and facility master rules — against a freshly seeded commune_test database.
 */
final class LayoutDesignerTest extends TestCase
{
    private static bool $booted = false;
    private static int $staffId = 0;

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
            self::$staffId = (int) $db->scalar("SELECT id FROM staff_users WHERE role = 'centre_manager' LIMIT 1");
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
        $this->clock()->freeze(new DateTimeImmutable('2026-09-27 10:00:00'));
    }

    protected function tearDown(): void
    {
        if (self::$booted) {
            // leave every floor with exactly its published version and no draft
            foreach (db()->select("SELECT id FROM layout_versions WHERE status = 'draft'") as $d) {
                $this->drafts()->discard((int) $d['id']);
            }
            $this->clock()->freeze(null);
        }
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function svc(string $class): object
    {
        /** @var T */
        return App::container()->get($class);
    }

    private function clock(): Clock
    {
        return $this->svc(Clock::class);
    }

    private function drafts(): LayoutDraftService
    {
        return $this->svc(LayoutDraftService::class);
    }

    private function publisher(): LayoutPublisher
    {
        return $this->svc(LayoutPublisher::class);
    }

    private function floorId(string $slug = 'ground-floor'): int
    {
        return (int) db()->scalar('SELECT id FROM floors WHERE slug = ?', [$slug]);
    }

    /** Published seat row id by code. */
    private function seat(string $code): int
    {
        return (int) db()->scalar(
            "SELECT s.id FROM seats s JOIN zones z ON z.id = s.zone_id JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published' WHERE s.code = ?",
            [$code],
        );
    }

    /** @return array<string, mixed> */
    private function customer(): array
    {
        static $n = 0;
        $n++;
        $id = db()->insert('customers', [
            'centre_id' => (int) db()->scalar('SELECT id FROM centres LIMIT 1'), 'type' => 'individual',
            'name' => 'Layout Test ' . $n, 'email' => "layout{$n}@example.test", 'state_code' => '32', 'kyc_status' => 'pending',
        ]);
        return (array) db()->first('SELECT * FROM customers WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed> booking row */
    private function book(string $code, BookingPeriod $period, string $session): array
    {
        $holder = SeatHolder::account(900 + strlen($session), $session);
        $id = $this->seat($code);
        $this->svc(SeatHoldService::class)->hold($holder, [$id], $period);
        return $this->svc(BookingService::class)->create($this->customer(), $holder, [$id], $period, [], BookingSource::Online)['booking'];
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function &seatIn(array &$doc, string $code): array
    {
        foreach ($doc['seats'] as &$s) {
            if ($s['code'] === $code) {
                return $s;
            }
        }
        throw new \RuntimeException('no seat ' . $code);
    }

    // ------------------------------------------------------------------ versioning

    public function testDraftClonesPublishedLayoutWithStableKeys(): void
    {
        $floor = $this->floorId();
        $pub = (array) $this->drafts()->published($floor);
        $draft = $this->drafts()->createDraft($floor, self::$staffId);
        self::assertSame('draft', $draft['status']);
        self::assertSame((int) $pub['id'], (int) $draft['base_version_id']);
        self::assertSame((int) $draft['id'], (int) $this->drafts()->createDraft($floor, self::$staffId)['id'], 'one draft per floor');

        $a = $this->drafts()->document((int) $pub['id']);
        $b = $this->drafts()->document((int) $draft['id']);
        self::assertCount(count($a['seats']), $b['seats']);
        self::assertCount(count($a['placements']), $b['placements']);
        self::assertEqualsCanonicalizing(array_column($a['seats'], 'key'), array_column($b['seats'], 'key'));
        self::assertEmpty(array_intersect(array_column($a['seats'], 'id'), array_column($b['seats'], 'id')), 'draft rows are new rows');
        // cabin chairs point at the cloned parent
        $cabin = array_values(array_filter($b['seats'], static fn ($s) => $s['code'] === 'G-CB-B'))[0];
        $chairs = array_filter($b['seats'], static fn ($s) => $s['parent_id'] === $cabin['id']);
        self::assertCount(3, $chairs);
        // availability of the draft is not published inventory
        self::assertSame(AvailabilityService::BLOCKED, $this->svc(AvailabilityService::class)->seatStatuses([$cabin['id']], BookingPeriod::days('2026-10-01', '2026-10-31'))[$cabin['id']]);
    }

    public function testPublishKeepsExistingBookingsOccupiedAndHistoryIntact(): void
    {
        db()->execute('DELETE FROM seat_holds');
        $oct = BookingPeriod::days('2026-10-01', '2026-10-31');
        $booking = $this->book('G-FX-01', $oct, 'sess-pub');
        $bookedRow = $this->seat('G-FX-01');
        // someone else is holding G-FX-05 right now
        $holder = SeatHolder::account(77, 'sess-hold');
        $this->svc(SeatHoldService::class)->hold($holder, [$this->seat('G-FX-05')], $oct);
        $avail = $this->svc(AvailabilityService::class);
        $before = $avail->floorSummary($oct)[$this->floorId()];

        $floor = $this->floorId();
        $draft = $this->drafts()->createDraft($floor, self::$staffId);
        $doc = $this->drafts()->document((int) $draft['id']);
        $s1 = &$this->seatIn($doc, 'G-FX-01');
        $s1['x'] += 1.5;                     // moved + rotated, still the same seat
        $s1['rotation'] = 90;
        unset($s1);
        $zone = $this->seatIn($doc, 'G-FX-01')['zone_id'];
        $doc['seats'][] = ['id' => 't1', 'zone_id' => $zone, 'parent_id' => null, 'code' => 'G-FX-19', 'label' => '19', 'kind' => 'seat', 'capacity' => 1,
            'x' => 30.0, 'y' => 40.0, 'w' => 3.4, 'h' => 5.44, 'rotation' => 0, 'status' => 'available'];
        $res = $this->drafts()->save((int) $draft['id'], (int) $draft['revision'], $doc, self::$staffId);
        self::assertSame((int) $draft['revision'] + 1, $res['revision']);
        self::assertArrayHasKey('t1', $res['ids']);

        $out = $this->publisher()->publish((int) $draft['id'], self::$staffId);
        self::assertSame([], $out['errors']);
        self::assertSame(1, $out['summary']['added']);
        self::assertSame(1, $out['summary']['moved']);

        $newRow = $this->seat('G-FX-01');
        self::assertNotSame($bookedRow, $newRow, 'the live seat is a new row of the new version');
        self::assertSame('archived', db()->scalar('SELECT status FROM layout_versions WHERE id = ?', [(int) $draft['base_version_id']]));
        // booking still references the exact row that was booked; history shows the same code
        self::assertSame($bookedRow, (int) db()->scalar('SELECT seat_id FROM booking_seats WHERE booking_id = ?', [(int) $booking['id']]));
        $seats = $this->svc(BookingDirectory::class)->seats((int) $booking['id']);
        self::assertSame('G-FX-01', $seats[0]['code']);
        // …and it still occupies the seat on the new layout
        self::assertSame(AvailabilityService::OCCUPIED, $avail->seatStatuses([$newRow], $oct)[$newRow]);
        self::assertSame(AvailabilityService::AVAILABLE, $avail->seatStatuses([$newRow], BookingPeriod::days('2026-11-01', '2026-11-30'))[$newRow]);
        $after = $avail->floorSummary($oct)[$floor];
        self::assertSame($before['chairs'] + 1, $after['chairs']);
        self::assertSame($before['free'] + 1, $after['free'], 'only the new seat is extra free; the booked seat stays booked');
        // the hold followed its seat
        self::assertSame($this->seat('G-FX-05'), (int) db()->scalar('SELECT seat_id FROM seat_holds WHERE session_id = ?', ['sess-hold']));
        self::assertSame(AvailabilityService::MINE, $avail->seatStatuses([$this->seat('G-FX-05')], $oct, $holder)[$this->seat('G-FX-05')]);
        // new seat has key = own id; the moved seat kept the key of the booked row
        self::assertSame((int) db()->scalar('SELECT seat_key FROM seats WHERE id = ?', [$bookedRow]), (int) db()->scalar('SELECT seat_key FROM seats WHERE id = ?', [$newRow]));
        $new = $this->seat('G-FX-19');
        self::assertSame($new, (int) db()->scalar('SELECT seat_key FROM seats WHERE id = ?', [$new]));
        self::assertGreaterThanOrEqual(1, (int) db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'layout.publish'"));
    }

    public function testSaveRejectsStaleRevisionAndBadGeometry(): void
    {
        $draft = $this->drafts()->createDraft($this->floorId(), self::$staffId);
        $doc = $this->drafts()->document((int) $draft['id']);
        $this->drafts()->save((int) $draft['id'], (int) $draft['revision'], $doc, self::$staffId);
        try {
            $this->drafts()->save((int) $draft['id'], (int) $draft['revision'], $doc, self::$staffId);
            self::fail('stale revision accepted');
        } catch (LayoutException $e) {
            self::assertSame(409, $e->status);
        }
        $doc['seats'][0]['x'] = 120;
        $this->expectException(LayoutException::class);
        $this->expectExceptionMessage('between 0 and 100');
        $this->drafts()->save((int) $draft['id'], (int) $draft['revision'] + 1, $doc, self::$staffId);
    }

    public function testPublishValidationErrorsAndWarnings(): void
    {
        $floor = $this->floorId();
        $booking = $this->book('G-DD-03', BookingPeriod::days('2026-10-01', '2026-12-31'), 'sess-val');
        self::assertNotEmpty($booking);
        $draft = $this->drafts()->createDraft($floor, self::$staffId);
        $doc = $this->drafts()->document((int) $draft['id']);
        $cabin = $this->seatIn($doc, 'G-CB-B');
        // duplicate code, a seat outside every zone, a cabin without chairs, and the booked G-DD-03 removed
        $s = &$this->seatIn($doc, 'G-FX-02');
        $s['code'] = 'G-FX-03';
        unset($s);
        $doc['seats'][] = ['id' => 't9', 'zone_id' => null, 'parent_id' => null, 'code' => 'G-FX-40', 'kind' => 'seat', 'x' => 1, 'y' => 1, 'w' => 2, 'h' => 3, 'status' => 'available'];
        $doc['seats'] = array_values(array_filter($doc['seats'], static fn ($x) => $x['parent_id'] !== $cabin['id'] && $x['code'] !== 'G-DD-03'));
        $this->drafts()->save((int) $draft['id'], (int) $draft['revision'], $doc, self::$staffId);

        $report = $this->publisher()->check((int) $draft['id']);
        $messages = implode("\n", array_column($report['errors'], 'message'));
        self::assertStringContainsString('unique', $messages);
        self::assertContains('G-FX-03', $report['errors'][0]['items']);
        self::assertStringContainsString('inside a zone', $messages);
        self::assertStringContainsString('G-CB-B has no chairs', $messages);
        self::assertStringContainsString('removed', implode("\n", array_column($report['warnings'], 'message')));
        self::assertStringContainsString((string) $booking['booking_no'], implode("\n", array_merge(...array_column($report['warnings'], 'items'))));
        try {
            $this->publisher()->publish((int) $draft['id'], self::$staffId, true);
            self::fail('published with errors');
        } catch (LayoutException $e) {
            self::assertSame(422, $e->status);
        }

        // fix the errors: only the warning is left → needs confirmation
        $doc = $this->drafts()->document((int) $draft['id']);
        $doc['seats'] = array_values(array_filter($doc['seats'], static fn ($x) => !in_array($x['code'], ['G-FX-40', 'G-CB-B'], true)));
        $s = &$this->seatIn($doc, 'G-FX-03');
        $s['code'] = 'G-FX-02';
        unset($s);
        $v = (array) $this->drafts()->version((int) $draft['id']);
        $this->drafts()->save((int) $draft['id'], (int) $v['revision'], $doc, self::$staffId);
        // a space type without a base rate blocks publishing
        db()->execute("UPDATE rates SET effective_to = '2026-09-01' WHERE scope = 'category' AND unit = 'hour'");
        self::assertStringContainsString('no base rate', implode("\n", array_column($this->publisher()->check((int) $draft['id'])['errors'], 'message')));
        db()->execute("UPDATE rates SET effective_to = NULL WHERE scope = 'category' AND unit = 'hour'");

        try {
            $this->publisher()->publish((int) $draft['id'], self::$staffId);
            self::fail('published without confirming warnings');
        } catch (LayoutException $e) {
            self::assertSame(409, $e->status);
            self::assertTrue($e->payload['needs_confirmation']);
        }
        $out = $this->publisher()->publish((int) $draft['id'], self::$staffId, true);
        self::assertSame('published', $out['version']['status']);
        self::assertSame(0, $this->seat('G-DD-03'), 'removed from the live layout');
        self::assertSame(1, (int) db()->scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = ?', [(int) $booking['id']]), 'booking untouched');
    }

    public function testDiscardRemovesDraftAndItsDraftOnlyRates(): void
    {
        $draft = $this->drafts()->createDraft($this->floorId('first-floor'), self::$staffId);
        $doc = $this->drafts()->document((int) $draft['id']);
        $doc['seats'][] = ['id' => 't5', 'zone_id' => $doc['seats'][0]['zone_id'], 'parent_id' => null, 'code' => 'F-FX-99', 'kind' => 'seat', 'x' => 10, 'y' => 10, 'w' => 3, 'h' => 5, 'status' => 'available'];
        $res = $this->drafts()->save((int) $draft['id'], (int) $draft['revision'], $doc, self::$staffId);
        $newKey = (int) db()->scalar('SELECT seat_key FROM seats WHERE id = ?', [$res['ids']['t5']]);
        $this->svc(RateService::class)->set(RateScope::Seat, [$newKey], BillingUnit::Month, 4200, 18, '2026-09-27', self::$staffId);
        $this->drafts()->discard((int) $draft['id']);
        self::assertNull($this->drafts()->version((int) $draft['id']));
        self::assertSame(0, (int) db()->scalar("SELECT COUNT(*) FROM rates WHERE scope = 'seat' AND scope_id = ?", [$newKey]));
        self::assertSame(0, (int) db()->scalar('SELECT COUNT(*) FROM seats WHERE id = ?', [$res['ids']['t5']]));
    }

    // ------------------------------------------------------------------ rates

    public function testNewRateClosesPreviousAndResolverPicksByDate(): void
    {
        $rates = $this->svc(RateService::class);
        $prices = $this->svc(PriceResolver::class);
        $flexi = (int) db()->scalar("SELECT id FROM seat_categories WHERE code = 'FLEXI'");
        $old = (array) db()->first("SELECT * FROM rates WHERE scope = 'category' AND scope_id = ? AND unit = 'month' AND effective_to IS NULL", [$flexi]);

        $rates->set(RateScope::Category, [$flexi], BillingUnit::Month, 4500, 18, '2026-11-01', self::$staffId);
        self::assertSame('2026-10-31', db()->scalar('SELECT effective_to FROM rates WHERE id = ?', [(int) $old['id']]));
        self::assertSame(4000.0, $prices->forCategory($flexi, BillingUnit::Month, '2026-10-31')['amount'] ?? null);
        self::assertSame(4500.0, $prices->forCategory($flexi, BillingUnit::Month, '2026-11-01')['amount'] ?? null);

        // resetting the same future date before anything was priced replaces the scheduled row
        $rates->set(RateScope::Category, [$flexi], BillingUnit::Month, 4600, 18, '2026-11-01', self::$staffId);
        self::assertSame(4600.0, $prices->forCategory($flexi, BillingUnit::Month, '2026-11-15')['amount'] ?? null);
        self::assertSame(1, (int) db()->scalar("SELECT COUNT(*) FROM rates WHERE scope = 'category' AND scope_id = ? AND unit = 'month' AND effective_from = '2026-11-01'", [$flexi]));

        // once it priced a booking the row is immutable: same date refused, a later date closes it
        $b = $this->book('G-FX-10', BookingPeriod::days('2026-11-05', '2026-12-04'), 'sess-rate');
        self::assertSame(4600.0, (float) db()->scalar('SELECT unit_price FROM booking_seats WHERE booking_id = ?', [(int) $b['id']]));
        try {
            $rates->set(RateScope::Category, [$flexi], BillingUnit::Month, 4700, 18, '2026-11-01', self::$staffId);
            self::fail('priced rate replaced');
        } catch (LayoutException $e) {
            self::assertStringContainsString('already priced bookings', $e->getMessage());
        }
        $rates->set(RateScope::Category, [$flexi], BillingUnit::Month, 4700, 18, '2027-01-01', self::$staffId);
        self::assertSame('2026-12-31', db()->scalar("SELECT effective_to FROM rates WHERE scope = 'category' AND scope_id = ? AND unit = 'month' AND effective_from = '2026-11-01'", [$flexi]));
        self::assertSame(4700.0, $prices->forCategory($flexi, BillingUnit::Month, '2027-02-01')['amount'] ?? null);
        self::assertGreaterThan(0, (int) db()->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'rate.close'"));

        // past dates are refused
        $this->expectException(LayoutException::class);
        $rates->set(RateScope::Category, [$flexi], BillingUnit::Month, 4800, 18, '2026-09-01', self::$staffId);
    }

    public function testSeatOverrideFollowsTheSeatAcrossVersionsAndCanBeCleared(): void
    {
        $rates = $this->svc(RateService::class);
        $prices = $this->svc(PriceResolver::class);
        $id = $this->seat('G-DD-10');
        $key = (int) db()->scalar('SELECT seat_key FROM seats WHERE id = ?', [$id]);
        $rates->set(RateScope::Seat, [$key], BillingUnit::Month, 6500, 18, '2026-09-27', self::$staffId);
        self::assertSame('seat', $prices->forSeat($id, BillingUnit::Month, '2026-10-01')['scope'] ?? null);

        $draft = $this->drafts()->createDraft($this->floorId(), self::$staffId);
        $this->publisher()->publish((int) $draft['id'], self::$staffId, true);
        $newId = $this->seat('G-DD-10');
        self::assertNotSame($id, $newId);
        self::assertSame(6500.0, $prices->forSeats([$newId], '2026-10-01')[$newId]['month']['amount']);

        $rates->clear(RateScope::Seat, [$key], null, '2026-10-01');
        self::assertSame(6500.0, $prices->forSeat($newId, BillingUnit::Month, '2026-09-30')['amount'] ?? null);
        self::assertSame('category', $prices->forSeat($newId, BillingUnit::Month, '2026-10-01')['scope'] ?? null);
    }

    // ------------------------------------------------------------------ facilities

    public function testFacilityMasterRules(): void
    {
        $svc = $this->svc(FacilityService::class);
        try {
            $svc->create(['name' => 'Standing desk', 'icon' => 'lamp-desk', 'kind' => 'addon', 'unit' => 'month', 'price' => 0, 'gst_rate' => 18]);
            self::fail('add-on without price');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('price', $e->errors());
        }
        $id = $svc->create(['name' => 'Standing desk', 'icon' => 'lamp-desk', 'kind' => 'addon', 'unit' => 'month', 'price' => 350, 'gst_rate' => 18, 'stock_qty' => 5, 'is_active' => 1]);
        $row = (array) db()->first('SELECT * FROM facilities WHERE id = ?', [$id]);
        self::assertSame('STANDING_DESK', $row['code']);
        self::assertSame(5, (int) $row['stock_qty']);
        $lm = $svc->create(['name' => 'Water cooler', 'icon' => 'coffee', 'kind' => 'landmark', 'unit' => 'month', 'price' => 99, 'stock_qty' => 3, 'gst_rate' => 18]);
        $lmRow = (array) db()->first('SELECT * FROM facilities WHERE id = ?', [$lm]);
        self::assertNull($lmRow['unit']);
        self::assertSame(0.0, (float) $lmRow['price']);
        self::assertNull($lmRow['stock_qty']);
        self::assertSame(0, (int) $lmRow['is_active'], 'unchecked = inactive');

        self::assertTrue($svc->delete($lm), 'unused facility is deleted');
        $locker = (int) db()->scalar("SELECT id FROM facilities WHERE code = 'LOCKER'");
        self::assertFalse($svc->delete($locker), 'placed on a published plan: deactivate instead');
        self::assertNotNull(db()->scalar('SELECT id FROM facilities WHERE id = ?', [$locker]));
        self::assertContains('lamp-desk', FacilityService::icons());
    }
}
