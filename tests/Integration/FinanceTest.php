<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Database;
use App\Core\Migrator;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CreditNoteReason;
use App\Services\Bookings\Actor;
use App\Services\Bookings\BookingDirectory;
use App\Services\Bookings\BookingService;
use App\Services\Bookings\BookingWorkflow;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\DepositRefundService;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\FinanceException;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\NumberSequence;
use App\Services\Finance\PaymentVerificationService;
use App\Services\Payments\PaymentLedger;
use App\Services\Payments\PaymentService;
use App\Services\Pdf\BookingDocuments;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SeatHolder;
use App\Support\Clock;
use Database\Seeds\DatabaseSeeder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Batch 6: FY numbering (rollover, no gaps, row lock / concurrency), payment verification + receipts, invoice
 * rules (advance vs one per rent period, deposit never invoiced, CGST/SGST vs IGST, immutability), credit note
 * limits, deposit refunds and a PDF smoke test — against the seeded commune_test database.
 */
final class FinanceTest extends TestCase
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
        self::clean();
        $this->clock()->freeze(new DateTimeImmutable('2026-09-27 10:00:00'));
    }

    protected function tearDown(): void
    {
        if (self::$booted) {
            self::clean();
            $this->clock()->freeze(null);
        }
    }

    public static function clean(): void
    {
        foreach (['credit_note_items', 'credit_notes', 'deposit_refunds', 'receipts', 'invoice_items', 'invoices', 'checkins', 'payments', 'rent_schedules', 'notifications', 'seat_holds', 'booking_facilities', 'booking_seats'] as $t) {
            db()->execute("DELETE FROM {$t}");
        }
        db()->execute('UPDATE bookings SET renewed_from_id = NULL');
        db()->execute('DELETE FROM bookings');
        db()->execute("DELETE FROM number_sequences WHERE name IN ('invoice', 'receipt', 'credit_note', 'refund_voucher')");
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
    private function customer(string $state = '32', ?string $gstin = null): array
    {
        static $n = 0;
        $n++;
        $id = db()->insert('customers', [
            'centre_id' => (int) db()->scalar('SELECT id FROM centres LIMIT 1'), 'type' => 'individual', 'unique_id' => sprintf('CMN-KTR-I-2026-8%04d', $n),
            'name' => 'Finance Visitor ' . $n, 'email' => "fin{$n}@example.test", 'mobile' => '+9198471' . sprintf('%05d', $n), 'state_code' => $state, 'gstin' => $gstin,
            'address' => 'Test Road', 'city' => 'Kottarakara', 'pincode' => '691506', 'kyc_status' => 'verified',
        ]);
        return (array) db()->first('SELECT * FROM customers WHERE id = ?', [$id]);
    }

    private function staff(string $role): Actor
    {
        return Actor::staff((array) db()->first('SELECT * FROM staff_users WHERE role = ? LIMIT 1', [$role]));
    }

    /**
     * Reception booking created straight in `approved`.
     *
     * @param list<string> $codes
     * @param array<int, int> $addons
     * @return array<string, mixed>
     */
    private function book(array $customer, array $codes, string $from, string $to, array $addons = []): array
    {
        $ids = array_map(fn (string $c) => $this->seat($c), $codes);
        $r = $this->svc(BookingService::class)->create($customer, SeatHolder::staff(1, 'fin-' . uniqid(), (int) $customer['id']), $ids, BookingPeriod::days($from, $to), $addons, BookingSource::Reception, [
            'status' => BookingStatus::Approved, 'created_by' => 1,
        ]);
        return $r['booking'];
    }

    /**
     * @param array<string, mixed> $b
     * @return array<string, mixed> payments row
     */
    private function pay(array $b, string $kind, float $amount): array
    {
        $r = $this->svc(PaymentService::class)->log($this->svc(BookingWorkflow::class)->find((int) $b['id']), [
            'kind' => $kind, 'mode' => 'upi', 'reference_no' => 'UTR' . random_int(100000, 999999), 'amount' => $amount, 'paid_on' => $this->clock()->today(),
        ], $this->staff('receptionist'));
        return $r['payment'];
    }

    /** @return array<string, mixed> receipt */
    private function verify(int $paymentId): array
    {
        return $this->svc(PaymentVerificationService::class)->verify($paymentId, $this->staff('finance_admin'))['receipt'];
    }

    // ------------------------------------------------------------------ numbering

    public function testNumbersRestartEachFinancialYearAndLeaveNoGaps(): void
    {
        $seq = $this->svc(NumberSequence::class);
        $next = fn (string $fy) => db()->transaction(fn () => $seq->next(NumberSequence::INVOICE, $fy));
        self::assertSame('KDISC/CMN/2026-27/0001', $next('2026-27')['no']);
        self::assertSame('KDISC/CMN/2026-27/0002', $next('2026-27')['no']);
        try {
            db()->transaction(function () use ($seq): void {
                $seq->next(NumberSequence::INVOICE, '2026-27');
                throw new \RuntimeException('insert failed');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame('KDISC/CMN/2026-27/0003', $next('2026-27')['no'], 'a rolled-back issue does not burn a number');
        self::assertSame('KDISC/CMN/2027-28/0001', $next('2027-28')['no']);

        $this->expectException(\LogicException::class);
        $seq->next(NumberSequence::INVOICE, '2026-27'); // outside a transaction
    }

    public function testMarchToAprilRolloverOnRealDocuments(): void
    {
        $this->clock()->freeze(new DateTimeImmutable('2027-03-31 16:00:00'));
        $b = $this->book($this->customer(), ['G-FX-11'], '2027-04-01', '2027-04-10');
        $p = $this->pay($b, 'advance', (float) $b['grand_total']);
        self::assertSame('RCPT/2026-27/0001', $this->verify((int) $p['id'])['receipt_no']);
        $this->clock()->freeze(new DateTimeImmutable('2027-04-01 09:00:00'));
        $inv = $this->svc(InvoiceService::class)->issue((int) $b['id'], 'advance', $this->staff('finance_admin'));
        self::assertSame('KDISC/CMN/2027-28/0001', $inv['invoice_no']);
        self::assertSame('2027-28', $inv['fy']);
    }

    public function testSequenceRowIsLockedWhileADocumentIsBeingIssued(): void
    {
        $seq = $this->svc(NumberSequence::class);
        db()->transaction(fn () => $seq->next(NumberSequence::RECEIPT, '2026-27'));
        $other = (new Database((array) config('database.connections.mysql', [])))->pdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        db()->pdo()->beginTransaction();
        try {
            $seq->next(NumberSequence::RECEIPT, '2026-27'); // holds the row lock
            $other->beginTransaction();
            try {
                $other->query("SELECT last_value FROM number_sequences WHERE name = 'receipt' AND period = '2026-27' FOR UPDATE");
                self::fail('a second issuer must wait for the lock');
            } catch (\PDOException $e) {
                self::assertStringContainsString('Lock wait timeout', $e->getMessage());
            } finally {
                $other->rollBack();
            }
        } finally {
            db()->pdo()->rollBack();
        }
    }

    public function testConcurrentIssuersGetUniqueGapFreeNumbers(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl not available');
        }
        $dir = sys_get_temp_dir() . '/commune-seq-' . uniqid();
        mkdir($dir);
        $children = [];
        for ($c = 0; $c < 4; $c++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // own connection: never touch the parent's socket (settings lookups go through the container)
                $db = new Database((array) config('database.connections.mysql', []));
                Database::setInstance($db);
                App::container()->instance(Database::class, $db);
                $seq = new NumberSequence($db);
                $got = [];
                for ($i = 0; $i < 10; $i++) {
                    $got[] = $db->transaction(fn () => $seq->next(NumberSequence::CREDIT_NOTE, '2030-31'))['seq'];
                }
                file_put_contents("{$dir}/{$c}", implode(',', $got));
                posix_kill(getmypid(), SIGKILL); // skip PHPUnit's shutdown handlers in the child
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $all = [];
        foreach (glob("{$dir}/*") ?: [] as $f) {
            array_push($all, ...array_map('intval', explode(',', (string) file_get_contents($f))));
            unlink($f);
        }
        rmdir($dir);
        sort($all);
        self::assertSame(range(1, 40), $all, 'every number once, no gaps');
        Database::setInstance(null);
        $fresh = Database::getInstance();
        App::container()->instance(Database::class, $fresh);
        self::assertSame(40, (int) $fresh->scalar("SELECT last_value FROM number_sequences WHERE name = 'credit_note' AND period = '2030-31'"));
    }

    // ------------------------------------------------------------------ verification + invoices

    public function testAdvanceBookingIsInvoicedOnceAfterVerification(): void
    {
        $b = $this->book($this->customer(), ['G-FX-02', 'G-FX-03'], '2026-10-01', '2026-10-31', [(int) db()->scalar("SELECT id FROM facilities WHERE code = 'LOCKER'") => 1]);
        $p = $this->pay($b, 'advance', (float) $b['grand_total']);
        $invoices = $this->svc(InvoiceService::class);
        self::assertSame([], $invoices->queue(), 'logged (unverified) money is not invoiced');
        try {
            $invoices->issue((int) $b['id'], 'advance', $this->staff('finance_admin'));
            self::fail('cannot invoice before verification');
        } catch (FinanceException) {
        }
        try {
            $this->svc(PaymentVerificationService::class)->verify((int) $p['id'], $this->staff('receptionist'));
            self::fail('only Finance verifies');
        } catch (FinanceException $e) {
            self::assertSame('permission', $e->kind);
        }

        $receipt = $this->verify((int) $p['id']);
        self::assertSame('RCPT/2026-27/0001', $receipt['receipt_no']);
        self::assertSame('payment', $receipt['kind']);
        $row = db()->first('SELECT * FROM payments WHERE id = ?', [$p['id']]);
        self::assertSame('verified', $row['status']);
        self::assertNotNull($row['verified_by']);
        self::assertNotNull(db()->first("SELECT id FROM audit_logs WHERE action = 'payment.verify' AND entity_id = ?", [$b['id']]));

        $queue = $invoices->queue();
        self::assertCount(1, $queue);
        self::assertSame('advance', $queue[0]['source_key']);
        $inv = $invoices->issue((int) $b['id'], 'advance', $this->staff('finance_admin'));
        self::assertSame('KDISC/CMN/2026-27/0001', $inv['invoice_no']);
        self::assertEqualsWithDelta((float) $b['grand_total'], (float) $inv['total'], 0.001);
        self::assertEqualsWithDelta((float) $b['subtotal'] + (float) $b['facilities_total'], (float) $inv['taxable_value'], 0.001);
        self::assertSame((float) $inv['cgst'], (float) $inv['sgst']);
        self::assertSame(0.0, (float) $inv['igst']);
        self::assertSame('32', $inv['place_of_supply']);
        self::assertStringStartsWith('Rupees ', (string) $inv['amount_words']);
        $items = $invoices->items((int) $inv['id']);
        self::assertCount(2, $items);
        self::assertEqualsWithDelta((float) $inv['taxable_value'], array_sum(array_column($items, 'taxable_value')), 0.001);
        self::assertSame($inv['id'], db()->scalar('SELECT invoice_id FROM receipts WHERE id = ?', [$receipt['id']]), 'receipt linked to the advance invoice');

        // immutable: a second invoice for the same source is refused, and nothing is queued any more
        try {
            $invoices->issue((int) $b['id'], 'advance', $this->staff('finance_admin'));
            self::fail('invoiced twice');
        } catch (FinanceException $e) {
            self::assertStringContainsString('Already invoiced', $e->getMessage());
        }
        self::assertSame([], $invoices->queue());
    }

    public function testInterStateCustomerIsChargedIgst(): void
    {
        $b = $this->book($this->customer('33'), ['G-FX-04'], '2026-10-01', '2026-10-31');
        $this->verify((int) $this->pay($b, 'advance', (float) $b['grand_total'])['id']);
        $inv = $this->svc(InvoiceService::class)->issue((int) $b['id'], 'advance', $this->staff('finance_admin'));
        self::assertSame('33', $inv['place_of_supply']);
        self::assertSame(0.0, (float) $inv['cgst']);
        self::assertSame(0.0, (float) $inv['sgst']);
        self::assertEqualsWithDelta(round((float) $inv['taxable_value'] * 0.18, 2), (float) $inv['igst'], 0.011);
        self::assertEqualsWithDelta((float) $b['grand_total'], (float) $inv['total'], 0.001);
    }

    public function testDepositIsNeverInvoicedAndRentIsInvoicedPerPeriod(): void
    {
        $b = $this->book($this->customer(), ['G-DD-02'], '2026-10-01', '2027-05-31');
        self::assertSame('security_deposit', $b['payment_rule']);
        $schedule = $this->svc(PaymentLedger::class)->schedule($b);
        $dep = $this->pay($b, 'deposit', (float) $b['deposit_amount']);
        $receipt = $this->verify((int) $dep['id']);
        self::assertSame('deposit', $receipt['kind']);
        $invoices = $this->svc(InvoiceService::class);
        self::assertSame([], $invoices->queue(), 'a verified deposit alone never produces an invoice');

        $this->verify((int) $this->pay($b, 'rent', (float) $schedule[0]['amount'])['id']);
        $queue = $invoices->queue();
        self::assertSame(['rent:1'], array_column($queue, 'source_key'));
        $inv = $invoices->issue((int) $b['id'], 'rent:1', $this->staff('finance_admin'));
        self::assertSame('rent', $inv['kind']);
        self::assertSame((int) $schedule[0]['id'], (int) $inv['rent_schedule_id']);
        self::assertEqualsWithDelta((float) $schedule[0]['amount'], (float) $inv['total'], 0.001);
        self::assertEqualsWithDelta((float) $schedule[0]['taxable'], (float) $inv['taxable_value'], 0.001);
        self::assertSame($schedule[0]['period_start'], $inv['period_start']);

        // a partial second-month payment is not enough; the rest completes period 2 → exactly one more invoice
        $this->verify((int) $this->pay($b, 'rent', 1000.0)['id']);
        self::assertSame([], $invoices->queue());
        $this->verify((int) $this->pay($b, 'rent', round((float) $schedule[1]['amount'] - 1000, 2))['id']);
        self::assertSame(['rent:2'], array_column($invoices->queue(), 'source_key'));
        $inv2 = $invoices->issue((int) $b['id'], 'rent:2', $this->staff('finance_admin'));
        self::assertSame('KDISC/CMN/2026-27/0002', $inv2['invoice_no']);
        self::assertSame(0, (int) db()->scalar("SELECT COUNT(*) FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE booking_id = ?) AND description LIKE '%deposit%'", [$b['id']]));
    }

    public function testCreditNotesCannotExceedTheInvoice(): void
    {
        $b = $this->book($this->customer(), ['G-FX-05'], '2026-10-01', '2026-12-31');
        $this->verify((int) $this->pay($b, 'advance', (float) $b['grand_total'])['id']);
        $inv = $this->svc(InvoiceService::class)->issue((int) $b['id'], 'advance', $this->staff('finance_admin'));
        $cns = $this->svc(CreditNoteService::class);
        $fin = $this->staff('finance_admin');

        try {
            $cns->issue((int) $inv['id'], CreditNoteReason::Discount, 'Too much', (float) $inv['taxable_value'] + 1, $fin);
            self::fail('over-credit');
        } catch (FinanceException $e) {
            self::assertStringContainsString('cannot exceed', $e->getMessage());
        }
        try {
            $cns->issue((int) $inv['id'], CreditNoteReason::Discount, 'Goodwill discount', 1000.0, $this->staff('receptionist'));
            self::fail('receptionists cannot issue credit notes');
        } catch (FinanceException $e) {
            self::assertSame('permission', $e->kind);
        }

        $cn1 = $cns->issue((int) $inv['id'], CreditNoteReason::Discount, 'Goodwill discount', 1000.0, $fin);
        self::assertSame('CN/2026-27/0001', $cn1['credit_note_no']);
        self::assertSame(1000.0, (float) $cn1['taxable_value']);
        self::assertSame(90.0, (float) $cn1['cgst']);
        self::assertSame(90.0, (float) $cn1['sgst']);
        self::assertSame(1180.0, (float) $cn1['total']);
        self::assertSame('issued', db()->scalar('SELECT status FROM invoices WHERE id = ?', [$inv['id']]));

        $cn2 = $cns->issue((int) $inv['id'], CreditNoteReason::Cancellation, 'Booking cancelled', null, $fin);
        self::assertSame('CN/2026-27/0002', $cn2['credit_note_no']);
        $after = (array) db()->first('SELECT * FROM invoices WHERE id = ?', [$inv['id']]);
        self::assertSame('cancelled', $after['status']);
        self::assertEqualsWithDelta((float) $after['total'], (float) $after['credited_total'], 0.001, 'full credit reverses the invoice exactly');
        self::assertEqualsWithDelta((float) $inv['cgst'], (float) $cn1['cgst'] + (float) $cn2['cgst'], 0.001);
        // the invoice itself is untouched apart from the credit bookkeeping
        self::assertSame($inv['total'], $after['total']);
        self::assertSame($inv['invoice_no'], $after['invoice_no']);
        try {
            $cns->issue((int) $inv['id'], CreditNoteReason::Other, 'One more try', 1.0, $fin);
            self::fail('nothing left to credit');
        } catch (FinanceException $e) {
            self::assertStringContainsString('fully credited', $e->getMessage());
        }
    }

    public function testEarlyExitSuggestsTheUnusedShare(): void
    {
        $this->clock()->freeze(new DateTimeImmutable('2026-09-20 10:00:00'));
        $b = $this->book($this->customer(), ['G-FX-06'], '2026-09-21', '2026-11-19'); // 60 days
        $this->verify((int) $this->pay($b, 'advance', (float) $b['grand_total'])['id']);
        $inv = $this->svc(InvoiceService::class)->issue((int) $b['id'], 'advance', $this->staff('finance_admin'));
        $this->clock()->freeze(new DateTimeImmutable('2026-09-27 10:00:00'));
        $wf = $this->svc(BookingWorkflow::class);
        $wf->activate((int) $b['id'], $this->staff('centre_manager'));
        $wf->earlyExit((int) $b['id'], $this->staff('centre_manager'), '2026-10-21', 'Moving away'); // last day 20 Oct → 30 unused
        $s = $this->svc(CreditNoteService::class)->suggest((array) db()->first('SELECT * FROM invoices WHERE id = ?', [$inv['id']]));
        self::assertNotNull($s);
        self::assertSame('early_exit', $s['reason']);
        self::assertEqualsWithDelta(round((float) $inv['taxable_value'] * 30 / 60, 2), $s['taxable'], 0.001);
    }

    public function testQueriedPaymentsAreSkippedByBulkVerify(): void
    {
        $b = $this->book($this->customer(), ['G-FX-08'], '2026-10-01', '2026-10-10');
        $p1 = $this->pay($b, 'advance', 100.0);
        $p2 = $this->pay($b, 'advance', 200.0);
        $v = $this->svc(PaymentVerificationService::class);
        $v->query((int) $p1['id'], $this->staff('finance_admin'), 'Reference not in statement');
        self::assertSame(1, $v->counts()['queried']);
        $r = $v->bulkVerify([(int) $p1['id'], (int) $p2['id']], $this->staff('finance_admin'));
        self::assertCount(1, $r['verified']);
        self::assertArrayHasKey((int) $p1['id'], $r['failed']);
        $v->resolve((int) $p1['id'], $this->staff('receptionist'), 'Corrected UTR 1234');
        self::assertSame(0, $v->counts()['queried']);
        self::assertSame('Corrected UTR 1234', db()->scalar('SELECT query_reply FROM payments WHERE id = ?', [$p1['id']]));
        self::assertSame('RCPT/2026-27/0002', $this->verify((int) $p1['id'])['receipt_no']);
    }

    public function testDepositRefundVoucherAfterTheBookingEnds(): void
    {
        $b = $this->book($this->customer(), ['G-DD-03'], '2026-10-01', '2027-05-31');
        $this->verify((int) $this->pay($b, 'deposit', (float) $b['deposit_amount'])['id']);
        $refunds = $this->svc(DepositRefundService::class);
        $fin = $this->staff('finance_admin');
        try {
            $refunds->issue((int) $b['id'], [], ['mode' => 'bank_transfer'], $fin);
            self::fail('booking has not ended');
        } catch (FinanceException) {
        }
        $this->svc(BookingWorkflow::class)->cancel((int) $b['id'], $this->staff('centre_manager'), 'Visitor withdrew');
        self::assertCount(1, $refunds->eligible());
        try {
            $refunds->issue((int) $b['id'], [['label' => 'Damages', 'amount' => (float) $b['deposit_amount'] + 1]], ['mode' => 'cash'], $fin);
            self::fail('adjustments above the deposit');
        } catch (FinanceException) {
        }
        $v = $refunds->issue((int) $b['id'], [['label' => 'Lost locker key', 'amount' => 300]], ['mode' => 'bank_transfer', 'reference_no' => 'NEFT1'], $fin);
        self::assertSame('DRV/2026-27/0001', $v['voucher_no']);
        self::assertEqualsWithDelta((float) $b['deposit_amount'] - 300, (float) $v['refund_amount'], 0.001);
        self::assertSame(0.0, $this->svc(FinanceReportService::class)->depositsHeld());
        $this->expectException(FinanceException::class);
        $refunds->issue((int) $b['id'], [], ['mode' => 'cash'], $fin);
    }

    // ------------------------------------------------------------------ PDFs

    public function testFinancePdfsRender(): void
    {
        $b = $this->book($this->customer('33', '33AAHCM4821R1Z2'), ['G-FX-09'], '2026-10-01', '2026-10-31');
        $receipt = $this->verify((int) $this->pay($b, 'advance', (float) $b['grand_total'])['id']);
        $inv = $this->svc(InvoiceService::class)->issue((int) $b['id'], 'advance', $this->staff('finance_admin'));
        $cn = $this->svc(CreditNoteService::class)->issue((int) $inv['id'], CreditNoteReason::Discount, 'Opening offer', 500.0, $this->staff('finance_admin'));
        $docs = $this->svc(FinanceDocuments::class);
        foreach ([['invoice', $inv, 'KDISC/CMN/2026-27/0001'], ['receipt', $receipt, 'RCPT/2026-27/0001'], ['credit_note', $cn, 'CN/2026-27/0001']] as [$type, $row, $no]) {
            $pdf = $docs->render($type, (array) $docs->find($type, (int) $row['id']), false);
            self::assertStringStartsWith('%PDF', $pdf);
            $text = self::pdfText($pdf);
            if ($text !== null) {
                self::assertStringContainsString($no, $text);
            }
        }
        $dup = $docs->render('invoice', (array) $docs->find('invoice', (int) $inv['id']), true);
        $text = self::pdfText($dup);
        if ($text !== null) {
            self::assertStringContainsString('DUPLICATE COPY', $text);
            self::assertStringContainsString('IGST', $text);
            self::assertStringContainsString('Tamil Nadu (33)', $text);
        }
        $booking = (array) $this->svc(BookingDirectory::class)->findByNo((string) $b['booking_no']);
        self::assertTrue(BookingDocuments::allotmentAvailable($booking));
        self::assertStringStartsWith('%PDF', $this->svc(BookingDocuments::class)->allotmentLetter($booking));
        self::assertStringStartsWith('%PDF', $this->svc(BookingDocuments::class)->idCard((array) db()->first('SELECT * FROM customers WHERE id = ?', [$b['customer_id']])));
        self::assertSame(0, (int) $this->svc(PaymentLedger::class)->dues($booking)['due_now']);
    }

    private static function pdfText(string $pdf): ?string
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            return null;
        }
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, $pdf);
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' - 2>/dev/null');
        unlink($file);
        return $text;
    }
}
