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
use App\Services\Bookings\BookingService;
use App\Services\Bookings\BookingWorkflow;
use App\Services\Finance\CreditNoteService;
use App\Services\Finance\FinanceReportService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentVerificationService;
use App\Services\Imports\ImportService;
use App\Services\Imports\TemplateBuilder;
use App\Services\Kyc\IdValidator;
use App\Services\Kyc\Verhoeff;
use App\Services\Payments\PaymentLedger;
use App\Services\Payments\PaymentService;
use App\Services\Reports\Definitions\DuesAgeingReport;
use App\Services\Reports\Definitions\GstSummaryReport;
use App\Services\Reports\Export\XlsxExporter;
use App\Services\Reports\OccupancyService;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportRegistry;
use App\Services\Space\BookingPeriod;
use App\Services\Space\SeatHolder;
use App\Support\Clock;
use Database\Seeds\DatabaseSeeder;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

/**
 * Batch 7: report queries on fixture data (occupancy %, ageing buckets, GST summary = invoices − credit notes), XLSX
 * export round-trip, and the bulk importer (validation, in-file + database duplicates, masked preview, confirm
 * through the services, error report, all-or-nothing) — against the seeded commune_test database.
 */
final class ReportsImportsTest extends TestCase
{
    private static bool $booted = false;
    private static int $maxRate = 0;
    private static int $n = 0;

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
            self::$maxRate = (int) $db->scalar('SELECT COALESCE(MAX(id), 0) FROM rates');
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

    private static function clean(): void
    {
        FinanceTest::clean();
        db()->execute('DELETE FROM import_batches');
        db()->execute('DELETE FROM rates WHERE id > ?', [self::$maxRate]);
        foreach (['customer_documents', 'customer_signatories'] as $t) {
            db()->execute("DELETE FROM {$t} WHERE customer_id IN (SELECT id FROM customers WHERE email LIKE '%@rpt.test')");
        }
        db()->execute("DELETE FROM customers WHERE email LIKE '%@rpt.test'");
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
        return (int) db()->scalar("SELECT s.id FROM seats s JOIN zones z ON z.id = s.zone_id JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published' WHERE s.code = ?", [$code]);
    }

    /** @return array<string, mixed> */
    private function customer(string $state = '32', ?string $gstin = null, string $kyc = 'verified'): array
    {
        self::$n++;
        $id = db()->insert('customers', [
            'centre_id' => (int) db()->scalar('SELECT id FROM centres LIMIT 1'), 'type' => 'individual', 'unique_id' => sprintf('CMN-KTR-I-2026-7%04d', self::$n),
            'name' => 'Report Visitor ' . self::$n, 'email' => 'rv' . self::$n . '@rpt.test', 'mobile' => '+9199470' . sprintf('%05d', self::$n), 'state_code' => $state, 'gstin' => $gstin,
            'address' => 'Test Road', 'city' => 'Kottarakara', 'pincode' => '691506', 'kyc_status' => $kyc,
        ]);
        return (array) db()->first('SELECT * FROM customers WHERE id = ?', [$id]);
    }

    private function staff(string $role): Actor
    {
        return Actor::staff($this->staffRow($role));
    }

    /** @return array<string, mixed> */
    private function staffRow(string $role): array
    {
        return (array) db()->first('SELECT * FROM staff_users WHERE role = ? LIMIT 1', [$role]);
    }

    /**
     * @param list<string> $codes
     * @return array<string, mixed>
     */
    private function book(array $customer, array $codes, BookingPeriod $period, BookingStatus $status = BookingStatus::Approved): array
    {
        return $this->svc(BookingService::class)->create($customer, SeatHolder::staff(1, 'rpt-' . uniqid(), (int) $customer['id']), array_map(fn ($c) => $this->seat($c), $codes), $period, [],
            $status === BookingStatus::Requested ? BookingSource::Online : BookingSource::Reception, ['status' => $status, 'created_by' => $status === BookingStatus::Requested ? null : 1])['booking'];
    }

    /** @param array<string, mixed> $b */
    private function pay(array $b, string $kind, float $amount): int
    {
        return (int) $this->svc(PaymentService::class)->log($this->svc(BookingWorkflow::class)->find((int) $b['id']), [
            'kind' => $kind, 'mode' => 'upi', 'reference_no' => 'UTR' . random_int(100000, 999999), 'amount' => $amount, 'paid_on' => $this->clock()->today(),
        ], $this->staff('receptionist'))['payment']['id'];
    }

    // ------------------------------------------------------------------ report queries

    public function testOccupancyCountsCommittedSeatDaysAndConferenceHours(): void
    {
        $this->clock()->freeze(new DateTimeImmutable('2026-09-01 07:00:00'));
        $this->book($this->customer(), ['G-FX-05'], BookingPeriod::days('2026-09-11', '2026-09-20'));                        // 10 days, approved
        $this->book($this->customer(), ['G-FX-06'], BookingPeriod::days('2026-09-01', '2026-09-30'), BookingStatus::Requested); // a request: not counted
        $this->book($this->customer(), ['G-CF-F'], BookingPeriod::hours('2026-09-15', '10:00', '13:00'));                    // 3 of 12 opening hours

        $data = $this->svc(OccupancyService::class)->compute('2026-09-01', '2026-09-30');
        $units = array_column($data['units'], null, 'code');
        self::assertSame(30.0, $units['G-FX-05']['capacity']);
        self::assertSame(10.0, $units['G-FX-05']['occupied']);
        self::assertEqualsWithDelta(1 / 3, $units['G-FX-05']['pct'], 0.0001);
        self::assertSame(0.0, $units['G-FX-06']['occupied'], 'requested bookings are not occupancy');
        self::assertSame(270.0, $units['G-CF-F']['capacity'], '9 seats × 30 days');
        self::assertSame(2.25, $units['G-CF-F']['occupied'], '9 seats × 3/12 of a day');

        $flexi = array_column(OccupancyService::group($data['units'], 'category'), null, 'category_code')['FLEXI'];
        self::assertSame(42 * 30.0, $flexi['capacity']);
        self::assertSame(10.0, $flexi['occupied']);
        $day15 = array_column($data['daily'], null, 'date')['2026-09-15'];
        self::assertSame(1 + 2.25, $day15['occupied']);
        self::assertEqualsWithDelta((10 + 2.25) / $data['capacity'], $data['pct'], 0.0001);
    }

    public function testDuesAgeingBucketsOverdueRentPeriods(): void
    {
        $this->clock()->freeze(new DateTimeImmutable('2026-06-01 10:00:00'));
        $b = $this->book($this->customer(), ['G-CB-B'], BookingPeriod::days('2026-06-05', '2027-05-31'));
        self::assertSame('security_deposit', $b['payment_rule']);
        $schedule = $this->svc(PaymentLedger::class)->schedule($b);
        $this->pay($b, 'deposit', (float) $b['deposit_amount']);
        $this->pay($b, 'rent', (float) $schedule[0]['amount']);

        $this->clock()->freeze(new DateTimeImmutable('2026-09-27 10:00:00'));
        $row = array_column($this->svc(DuesAgeingReport::class)->rows('2026-09-27'), null, 'booking_no')[$b['booking_no']];
        $by = [];
        foreach (array_slice($schedule, 1, 3) as $p) {
            $days = (int) (new DateTimeImmutable((string) $p['due_on']))->diff(new DateTimeImmutable('2026-09-27'))->days;
            $k = $days <= 30 ? 'd0_30' : ($days <= 60 ? 'd31_60' : 'd61_90');
            $by[$k] = ($by[$k] ?? 0) + (float) $p['amount'];
        }
        foreach (['d0_30', 'd31_60', 'd61_90'] as $k) {
            self::assertEqualsWithDelta($by[$k] ?? 0.0, $row[$k], 0.001, $k);
        }
        self::assertSame(0.0, $row['d90']);
        $dues = $this->svc(PaymentLedger::class)->dues($this->svc(BookingWorkflow::class)->find((int) $b['id']));
        self::assertEqualsWithDelta($dues['due_now'], $row['total'], 0.001, 'buckets add up to the ledger\'s "due now"');
    }

    public function testGstSummaryEqualsInvoicesMinusCreditNotes(): void
    {
        $this->clock()->freeze(new DateTimeImmutable('2026-09-05 10:00:00'));
        $f14 = '33AABCM1234L1Z';
        $b2b = $this->customer('33', $f14 . IdValidator::gstinCheckChar($f14));
        $b2c = $this->customer();
        $inv = [];
        foreach ([[$b2b, 'G-FX-07'], [$b2c, 'G-FX-08']] as [$c, $code]) {
            $b = $this->book($c, [$code], BookingPeriod::days('2026-09-10', '2026-09-30'));
            $this->svc(PaymentVerificationService::class)->verify($this->pay($b, 'advance', (float) $b['grand_total']), $this->staff('finance_admin'));
            $inv[] = $this->svc(InvoiceService::class)->issue((int) $b['id'], 'advance', $this->staff('finance_admin'));
        }
        $cn = $this->svc(CreditNoteService::class);
        $n1 = $cn->issue((int) $inv[0]['id'], CreditNoteReason::Discount, 'Goodwill', 500.0, $this->staff('finance_admin'));
        $n2 = $cn->issue((int) $inv[1]['id'], CreditNoteReason::Discount, 'Goodwill', 200.0, $this->staff('finance_admin'));

        $report = $this->svc(GstSummaryReport::class);
        $f = ReportFilters::make($report, ['period' => '2026-09'], '2026-09-27');
        $r = $report->run($f);
        $taxable = array_sum(array_map(static fn ($i) => (float) $i['taxable_value'], $inv)) - (float) $n1['taxable_value'] - (float) $n2['taxable_value'];
        $tax = array_sum(array_map(static fn ($i) => (float) $i['cgst'] + (float) $i['sgst'] + (float) $i['igst'], $inv))
            - array_sum(array_map(static fn ($n) => (float) $n['cgst'] + (float) $n['sgst'] + (float) $n['igst'], [$n1, $n2]));
        self::assertEqualsWithDelta($taxable, (float) $r->totals['taxable'], 0.001);
        self::assertEqualsWithDelta($tax, (float) $r->totals['tax'], 0.001);
        $check = $this->svc(FinanceReportService::class)->gst('2026-09-01', '2026-09-30');
        self::assertEqualsWithDelta($check['taxable'], (float) $r->totals['taxable'], 0.001);
        self::assertEqualsWithDelta($check['igst'], (float) $r->totals['igst'], 0.001);

        [$b2bSheet, , $b2cs, $notes, $hsn] = $r->sheets;
        self::assertCount(1, $b2bSheet->rows);
        self::assertSame($inv[0]['invoice_no'], $b2bSheet->rows[0]['invoice_no']);
        self::assertSame('33-Tamil Nadu', $b2bSheet->rows[0]['pos']);
        self::assertGreaterThan(0, $b2bSheet->rows[0]['igst']);
        self::assertCount(1, $b2cs->rows);
        self::assertEqualsWithDelta((float) $inv[1]['taxable_value'] - 200.0, $b2cs->rows[0]['taxable'], 0.001, 'B2CS is net of its credit notes');
        self::assertSame(['CDNR', 'B2CS (netted)'], array_column($notes->rows, 'section'));
        self::assertEqualsWithDelta($taxable, (float) $hsn->totals['taxable'], 0.001, 'HSN summary nets the credit notes too');
    }

    // ------------------------------------------------------------------ XLSX export

    public function testXlsxExportRoundTrip(): void
    {
        $this->clock()->freeze(new DateTimeImmutable('2026-09-01 07:00:00'));
        $this->book($this->customer(), ['G-FX-09', 'G-FX-10'], BookingPeriod::days('2026-09-01', '2026-09-10'));
        $report = $this->svc(ReportRegistry::class)->find('occupancy');
        self::assertNotNull($report);
        $f = ReportFilters::make($report, ['range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30', 'group' => 'category'], '2026-09-27');
        $result = $report->run($f);
        $x = $this->svc(XlsxExporter::class);
        $tmp = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
        file_put_contents($tmp, $x->bytes($x->workbook($result, $f->describe(), '27 Sep 2026', 'Tester')));
        $book = IOFactory::load($tmp);
        @unlink($tmp);
        $ws = $book->getSheet(0);
        self::assertSame('Occupancy by space type', $ws->getCell('A1')->getValue());
        self::assertStringContainsString('Tester', (string) $ws->getCell('A2')->getValue());
        $header = (int) substr($ws->getFreezePane() ?? 'A0', 1) - 1;
        self::assertGreaterThan(3, $header);
        self::assertSame(['Space type', 'Units', 'Seats', 'Seat-days available', 'Seat-days occupied', 'Seat-days free', 'Occupancy'], $ws->rangeToArray("A{$header}:G{$header}")[0]);
        self::assertSame('1D4ED8', strtoupper($ws->getStyle("A{$header}")->getFill()->getStartColor()->getRGB()));
        self::assertSame("A{$header}:G" . ($header + 4), $ws->getAutoFilter()->getRange());
        $first = $header + 1;
        self::assertSame('Flexi / Hot desk', $ws->getCell("A{$first}")->getValue());
        self::assertSame(20.0, (float) $ws->getCell("E{$first}")->getValue());
        self::assertSame('#,##0.00', $ws->getStyle("D{$first}")->getNumberFormat()->getFormatCode());
        self::assertSame('0.0%', $ws->getStyle("G{$first}")->getNumberFormat()->getFormatCode());
        $total = $header + 5; // 4 categories + totals
        self::assertSame('Total', $ws->getCell("A{$total}")->getValue());
        self::assertStringStartsWith('=SUBTOTAL(9,', (string) $ws->getCell("E{$total}")->getValue());
        self::assertEqualsWithDelta(20.0, (float) $ws->getCell("E{$total}")->getCalculatedValue(), 0.001);
        self::assertEqualsWithDelta($result->totals['pct'], (float) $ws->getCell("G{$total}")->getValue(), 0.0001);
        self::assertTrue($ws->getStyle("A{$total}")->getFont()->getBold());

        // money + dates on a register export
        $reg = $this->svc(ReportRegistry::class)->find('register-invoices');
        self::assertNotNull($reg);
        $rf = ReportFilters::make($reg, [], '2026-09-27');
        $book2 = IOFactory::load((function () use ($x, $reg, $rf): string {
            $p = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
            file_put_contents($p, $x->bytes($x->workbook($reg->run($rf), $rf->describe(), 'now', '')));
            return $p;
        })());
        self::assertStringContainsString('No rows', (string) $book2->getSheet(0)->toArray()[6][0] ?? '');
    }

    // ------------------------------------------------------------------ imports

    /**
     * Fill a real template and upload it (trusted local file).
     *
     * @param list<list<string>> $rows
     */
    private function upload(string $type, array $rows): int
    {
        $svc = $this->svc(ImportService::class);
        $book = $this->svc(TemplateBuilder::class)->build($svc->importer($type));
        $ws = $book->getSheetByName('Data');
        self::assertNotNull($ws);
        foreach ($rows as $i => $row) {
            foreach ($row as $c => $v) {
                $ws->setCellValueExplicit([$c + 1, $i + 3], $v, DataType::TYPE_STRING);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        try {
            return $svc->upload($type, ['tmp_name' => $path, 'name' => "{$type}.xlsx", 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)], $this->staffRow('centre_manager'), trusted: true);
        } finally {
            @unlink($path);
        }
    }

    private static function aadhaar(string $first11): string
    {
        return $first11 . Verhoeff::checkDigit($first11);
    }

    public function testTemplateHasDropdownsInstructionsAndMarkedRequiredColumns(): void
    {
        $book = $this->svc(TemplateBuilder::class)->build($this->svc(ImportService::class)->importer('individuals'));
        self::assertSame(['Data', 'Instructions', 'Lists'], $book->getSheetNames());
        $ws = $book->getSheetByName('Data');
        self::assertNotNull($ws);
        self::assertSame('Category *', $ws->getCell('A1')->getValue());
        self::assertSame('Country (foreign nationals)', $ws->getCell('K1')->getValue());
        self::assertSame('list', $ws->getDataValidation('A5')->getType());
        self::assertSame('list_sub_category', $ws->getDataValidation('A5')->getFormula1());
        self::assertSame('@', $ws->getStyle('M10')->getNumberFormat()->getFormatCode(), 'Aadhaar column is text');
        self::assertSame('A2', $ws->getFreezePane());
        self::assertSame('hidden', $book->getSheetByName('Lists')?->getSheetState());
    }

    public function testIndividualsImportValidatesMasksAndImportsThroughTheServices(): void
    {
        $existing = $this->customer();
        $good = self::aadhaar('56781234567');
        $base = static fn (string $name, string $email, string $mobile, string $aadhaar) => ['Freelancer', $name, $email, $mobile, '12 Beach Road', 'Kollam', '691001', 'Kerala', 'Illustrator working on books and brand identity work.', 'Indian', '', '', $aadhaar, 'Yes', '', ''];
        $id = $this->upload('individuals', [
            $base('Good Row', 'good@rpt.test', '9847100001', $good),
            $base('Bad Aadhaar', 'bad@rpt.test', '9847100002', '234123412345'),
            $base('Dup In File', 'good@rpt.test', '9847100003', self::aadhaar('56781234568')),
            $base('Dup In Db', 'other@rpt.test', substr((string) $existing['mobile'], 3), self::aadhaar('56781234569')),
        ]);
        $svc = $this->svc(ImportService::class);
        $batch = (array) $svc->batch($id);
        self::assertSame('validated', $batch['status']);
        self::assertSame([4, 1, 3], [(int) $batch['total_rows'], (int) $batch['valid_rows'], (int) $batch['error_rows']]);

        $tmp = ImportService::root((string) $batch['file_path']);
        self::assertFileExists($tmp);
        self::assertSame('0600', substr(sprintf('%o', fileperms($tmp)), -4));
        $previewJson = (string) file_get_contents(ImportService::root("tmp/{$batch['token']}.json"));
        self::assertStringNotContainsString($good, $previewJson, 'no full Aadhaar in the preview');
        self::assertStringContainsString('XXXX XXXX ' . substr($good, -4), $previewJson);

        $rows = array_column((array) $svc->preview($batch)['rows'], null, 'row');
        self::assertSame([], $rows[3]['errors']);
        self::assertStringContainsString('Aadhaar', $rows[4]['errors']['aadhaar']);
        self::assertStringContainsString('row 3 of this file', $rows[5]['errors']['email']);
        self::assertStringContainsString('Already registered: ' . $existing['name'], $rows[6]['errors']['mobile']);

        $r = $svc->confirm($id, $this->staffRow('centre_manager'), 'per_row', false);
        self::assertSame(['imported' => 1, 'failed' => 0, 'skipped' => 3], array_intersect_key($r, ['imported' => 1, 'failed' => 1, 'skipped' => 1]));
        $c = (array) db()->first("SELECT * FROM customers WHERE email = 'good@rpt.test'");
        self::assertStringStartsWith('CMN-KTR-I-', (string) $c['unique_id']);
        self::assertSame('pending', $c['kyc_status']);
        self::assertSame('reception', $c['registered_via']);
        self::assertSame(substr($good, -4), $c['aadhaar_last4']);
        self::assertNotEmpty($c['aadhaar_enc']);
        self::assertStringNotContainsString($good, (string) $c['aadhaar_enc']);

        $after = (array) $svc->batch($id);
        self::assertSame('partial', $after['status']);
        self::assertNull($after['token']);
        self::assertFileDoesNotExist($tmp, 'the uploaded workbook is deleted after confirm');

        // error report: the 3 bad rows in template order + Source row + Error, masked, red fill
        $report = $svc->errorReportFile($after);
        self::assertNotNull($report);
        $ws = IOFactory::load($report)->getSheet(0);
        $data = $ws->toArray();
        self::assertSame('Category *', $data[0][0]);
        self::assertSame(['Source row', 'Error'], array_slice($data[0], -2));
        self::assertCount(4, $data);
        self::assertSame('Bad Aadhaar', $data[1][1]);
        self::assertSame('XXXX XXXX 2345', $data[1][12]);
        self::assertSame(4, (int) $data[1][16]);
        self::assertStringContainsString('Aadhaar number:', (string) $data[1][17]);
        self::assertSame('FECACA', $ws->getStyle('M2')->getFill()->getStartColor()->getRGB());
        self::assertStringNotContainsString($good, (string) file_get_contents($report));
    }

    public function testBookingAndPaymentImportsGoThroughBookingAndPaymentServices(): void
    {
        $c = $this->customer();
        $id = $this->upload('bookings', [
            [(string) $c['unique_id'], 'G-FX-12', '01-11-2026', '30-11-2026', '', '', 'Personal locker', 'imported'],
            [(string) $c['unique_id'], 'G-FX-12', '15-11-2026', '20-11-2026', '', '', '', 'overlaps the row above'],
            ['CMN-KTR-I-2026-99999', 'G-FX-13', '01-11-2026', '', '', '', '', ''],
            [(string) $c['unique_id'], 'G-CB-B1', '01-11-2026', '30-11-2026', '', '', '', 'a chair of a cabin'],
        ]);
        $svc = $this->svc(ImportService::class);
        $rows = array_column((array) $svc->preview((array) $svc->batch($id))['rows'], null, 'row');
        self::assertSame([], $rows[3]['errors']);
        self::assertStringContainsString('row 3 of this file', $rows[4]['errors']['seats']);
        self::assertStringContainsString('No visitor', $rows[5]['errors']['visitor']);
        self::assertStringContainsString('book the whole unit G-CB-B', $rows[6]['errors']['seats']);
        self::assertSame(1, $svc->confirm($id, $this->staffRow('centre_manager'), 'per_row', false)['imported']);

        $b = (array) db()->first('SELECT * FROM bookings WHERE customer_id = ?', [$c['id']]);
        self::assertSame('approved', $b['status']);
        self::assertSame('reception', $b['source']);
        self::assertSame('2026-11-01', $b['start_date']);
        self::assertSame((int) db()->scalar("SELECT seat_key FROM seats WHERE id = ?", [$this->seat('G-FX-12')]), (int) db()->scalar('SELECT seat_key FROM booking_seats WHERE booking_id = ?', [$b['id']]));
        self::assertSame(1, (int) db()->scalar('SELECT COUNT(*) FROM booking_facilities WHERE booking_id = ?', [$b['id']]));

        $pid = $this->upload('payments', [
            [(string) $b['booking_no'], 'Advance', 'UPI', 'UPI/1234567890', (string) $b['grand_total'], '26-09-2026', ''],
            [(string) $b['booking_no'], 'Advance', 'Cash', '', '1', '26-09-2026', 'over the balance after the row above'],
            [(string) $b['booking_no'], 'Advance', 'Cheque', '', '1', '26-09-2026', 'no reference'],
        ]);
        $prow = array_column((array) $svc->preview((array) $svc->batch($pid))['rows'], null, 'row');
        self::assertSame([], $prow[3]['errors']);
        self::assertStringContainsString('after earlier rows', $prow[4]['errors']['amount']);
        self::assertStringContainsString('Cheque number', $prow[5]['errors']['reference_no']);
        self::assertSame(1, $svc->confirm($pid, $this->staffRow('centre_manager'), 'per_row', false)['imported']);
        self::assertSame('logged', db()->scalar('SELECT status FROM payments WHERE booking_id = ?', [$b['id']]));
        self::assertSame('confirmed', db()->scalar('SELECT status FROM bookings WHERE id = ?', [$b['id']]), 'the payment confirmed the booking (KYC verified)');
    }

    public function testAllOrNothingImportsNothingWhenARowIsInvalid(): void
    {
        $id = $this->upload('rates', [
            ['Space type', 'FLEXI', 'Day', '555', '18', '01-11-2026', 'rpt'],
            ['Zone', 'OPEN', 'Hour', '100', '18', '01-11-2026', 'flexi is not billed per hour'],
        ]);
        $svc = $this->svc(ImportService::class);
        $r = $svc->confirm($id, $this->staffRow('centre_manager'), 'all_or_nothing', false);
        self::assertSame(0, $r['imported']);
        self::assertSame('failed', $svc->batch($id)['status'] ?? null);
        self::assertSame(0, (int) db()->scalar('SELECT COUNT(*) FROM rates WHERE id > ?', [self::$maxRate]));

        $id2 = $this->upload('rates', [['Space type', 'FLEXI', 'Day', '555', '18', '01-11-2026', 'rpt']]);
        self::assertSame(1, $svc->confirm($id2, $this->staffRow('centre_manager'), 'all_or_nothing', false)['imported']);
        self::assertSame(555.0, (float) db()->scalar("SELECT amount FROM rates WHERE id > ? AND effective_from = '2026-11-01'", [self::$maxRate]));
    }

    public function testUploadRejectsNonXlsxAndWrongTemplates(): void
    {
        $svc = $this->svc(ImportService::class);
        $before = glob(ImportService::root('tmp') . '/*') ?: [];
        $csv = tempnam(sys_get_temp_dir(), 'c');
        file_put_contents($csv, "a,b\n1,2\n");
        try {
            $svc->upload('individuals', ['tmp_name' => $csv, 'name' => 'x.csv', 'error' => UPLOAD_ERR_OK], $this->staffRow('centre_manager'), trusted: true);
            self::fail('csv accepted');
        } catch (\App\Core\Exceptions\ValidationException $e) {
            self::assertStringContainsString('.xlsx', $e->errors()['file'][0]);
        } finally {
            @unlink($csv);
        }
        // a payments template uploaded as "individuals": required columns missing
        $book = $this->svc(TemplateBuilder::class)->build($svc->importer('payments'));
        $book->getSheetByName('Data')?->setCellValue('A3', 'BK-2026-000001');
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        try {
            $svc->upload('individuals', ['tmp_name' => $path, 'name' => 'p.xlsx', 'error' => UPLOAD_ERR_OK], $this->staffRow('centre_manager'), trusted: true);
            self::fail('wrong template accepted');
        } catch (\App\Core\Exceptions\ValidationException $e) {
            self::assertStringContainsString('template', $e->errors()['file'][0]);
        } finally {
            @unlink($path);
        }
        self::assertSame($before, glob(ImportService::root('tmp') . '/*') ?: [], 'rejected uploads leave no temp file');
    }
}
