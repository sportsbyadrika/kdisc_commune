<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Exceptions\ValidationException;
use App\Core\Router;
use App\Enums\DocumentType;
use App\Enums\HolderType;
use App\Services\AuditLog;
use App\Services\Demo\DemoSeeder;
use App\Services\Finance\FinanceDocuments;
use App\Services\Imports\ImportService;
use App\Services\Kyc\AadhaarVault;
use App\Services\Kyc\DocumentStore;
use App\Services\Reports\Export\ReportExporter;
use App\Services\Reports\ReportFilters;
use App\Services\Reports\ReportRegistry;
use App\Services\Security\RateLimiter;
use App\Services\System\EnvironmentCheck;
use App\Support\Clock;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Support\HttpKernel;

/**
 * Batch 8: rate limiter + throttle middleware, route-dependent URLs from the CLI (the old "Route [portal.invoices]
 * is not defined" log error), no full Aadhaar in logs / audit / notifications / exports, upload bomb guards and
 * the environment check.
 */
final class HardeningTest extends TestCase
{
    use HttpKernel;

    private static TestHandler $log;

    public static function setUpBeforeClass(): void
    {
        self::$booted = self::bootTestApp();
        if (self::$booted) {
            self::$log = new TestHandler();
            /** @var Monolog $logger */
            $logger = App::container()->get(LoggerInterface::class);
            $logger->pushHandler(self::$log);
        }
    }

    protected function setUp(): void
    {
        if (!self::$booted) {
            self::markTestSkipped('commune_test database not available.');
        }
        $this->signOut();
    }

    public function testRateLimiterFixedWindow(): void
    {
        $limiter = App::container()->get(RateLimiter::class);
        $limiter->clear('unit', 'k1');
        for ($i = 1; $i <= 3; $i++) {
            self::assertSame(0, $limiter->attempt('unit', 'k1', 3, 60), "hit {$i} is allowed");
        }
        $wait = $limiter->attempt('unit', 'k1', 3, 60);
        self::assertGreaterThan(0, $wait);
        self::assertLessThanOrEqual(60, $wait);
        self::assertGreaterThan(0, $limiter->availableIn('unit', 'k1', 3));
        self::assertSame(0, $limiter->attempt('unit', 'other-client', 3, 60), 'buckets are per key');
        // an expired window starts again at 1
        db()->execute('UPDATE rate_limits SET reset_at = ? WHERE bucket = ?', [date('Y-m-d H:i:s', time() - 1), RateLimiter::bucket('unit', 'k1')]);
        self::assertSame(0, $limiter->attempt('unit', 'k1', 3, 60));
        self::assertSame(1, (int) db()->scalar('SELECT hits FROM rate_limits WHERE bucket = ?', [RateLimiter::bucket('unit', 'k1')]));
        $limiter->clear('unit', 'k1');
        self::assertSame(0, $limiter->availableIn('unit', 'k1', 3));
    }

    public function testThrottleMiddlewareAnswers429(): void
    {
        $ip = ['REMOTE_ADDR' => '198.51.100.20'];
        $statuses = [];
        for ($i = 0; $i < 7; $i++) {
            $this->signOut();
            $statuses[] = $this->http('POST', '/contact', ['_token' => $this->csrf(), 'name' => 'x'], $ip)->status();
        }
        self::assertSame([302, 302, 302, 302, 302, 429, 429], $statuses, 'throttle:contact,5,10');
        $this->signOut();
        $json = $this->http('POST', '/api/space/quote', ['_token' => $this->csrf()], $ip + ['HTTP_ACCEPT' => 'application/json']);
        self::assertNotSame(429, $json->status(), 'limits are per limiter name');
        $this->signOut();
        $r = $this->http('POST', '/contact', ['_token' => $this->csrf()], $ip);
        self::assertNotNull($r->getHeader('Retry-After'));
    }

    public function testRouteNamesUsedInCodeExist(): void
    {
        /** @var Router $router */
        $router = App::container()->get(Router::class);
        $missing = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app'));
        $files = iterator_to_array($it);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/resources/views'));
        $files = array_merge($files, iterator_to_array($it));
        foreach ($files as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }
            $code = '';
            foreach (token_get_all((string) file_get_contents((string) $file)) as $t) {
                $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
            }
            preg_match_all("/\\b(?:absolute_url|url|redirectTo)\\('([a-z_]+(?:\\.[a-z_]+)+)'/", $code, $m);
            foreach ($m[1] as $name) {
                if (!$router->has($name)) {
                    $missing[] = $name . ' in ' . basename((string) $file);
                }
            }
        }
        self::assertSame([], array_values(array_unique($missing)));
    }

    public function testDocumentsIssuedFromTheConsoleBuildLinksAndLogNoErrors(): void
    {
        // Same context as bin/console demo:seed / cron: a freshly booted app, no HTTP request, routes resolved lazily
        // by the container the first time a link is needed.
        self::bootTestApp(false);
        App::container()->get(LoggerInterface::class)->pushHandler(self::$log);
        self::assertNull(App::request());
        self::assertSame(rtrim((string) App::config('app.url'), '/') . '/my/invoices', absolute_url('portal.invoices'));

        self::$log->clear();
        $clock = App::container()->get(Clock::class);
        $clock->freeze(new \DateTimeImmutable('2026-09-27 10:00:00'));
        $counts = App::container()->get(DemoSeeder::class)->run();
        $clock->freeze(null);
        self::assertGreaterThan(10, $counts['invoices']);
        $errors = array_filter(self::$log->getRecords(), static fn ($r) => $r->level->value >= \Monolog\Level::Warning->value);
        self::assertSame([], array_map(static fn ($r) => $r->message, array_values($errors)), 'no warnings/errors while issuing documents from the CLI');

        // issue-time storage + email, exactly what the finance controllers call after issuing (the batch 6 script that
        // logged "Route [portal.invoices] is not defined" called this from the CLI before the portal route existed)
        self::$log->clear();
        $docs = App::container()->get(FinanceDocuments::class);
        $ids = ['invoice' => db()->column('SELECT id FROM invoices ORDER BY id LIMIT 2'), 'receipt' => db()->column('SELECT id FROM receipts ORDER BY id LIMIT 2')];
        foreach ($ids as $type => $list) {
            foreach ($list as $id) {
                $docs->issued($type, (int) $id);
                self::assertNotNull(db()->scalar('SELECT pdf_path FROM ' . FinanceDocuments::TYPES[$type][0] . ' WHERE id = ?', [(int) $id]), "{$type} #{$id} PDF stored");
            }
        }
        $errors = array_filter(self::$log->getRecords(), static fn ($r) => $r->level->value >= \Monolog\Level::Warning->value);
        self::assertSame([], array_map(static fn ($r) => $r->message, array_values($errors)));
        self::assertSame(2, (int) db()->scalar("SELECT COUNT(*) FROM notifications WHERE type = 'invoice.issued'"));
    }

    #[Depends('testDocumentsIssuedFromTheConsoleBuildLinksAndLogNoErrors')]
    public function testFullAadhaarNeverReachesLogsAuditNotificationsOrExports(): void
    {
        $vault = new AadhaarVault();
        $numbers = array_map(static fn (string $enc) => $vault->decrypt($enc), db()->column('SELECT aadhaar_enc FROM customers WHERE aadhaar_enc IS NOT NULL'));
        $numbers = array_merge($numbers, array_map(static fn (string $enc) => $vault->decrypt($enc), db()->column('SELECT aadhaar_enc FROM customer_signatories WHERE aadhaar_enc IS NOT NULL')));
        self::assertGreaterThan(5, count($numbers), 'demo visitors carry Aadhaar numbers');

        // even a careless caller cannot leak one
        $n = $numbers[0];
        logger()->warning('Visitor typed {aadhaar} into the notes', ['aadhaar' => $n, 'note' => 'my aadhaar is ' . chunk_split($n, 4, ' '), 'password' => 'hunter2']);
        App::container()->get(AuditLog::class)->record('test.leak', 'customer', 1, ['aadhaar' => $n], ['aadhaar_number' => $n, 'aadhaar_last4' => substr($n, -4), 'note' => 'id ' . $n], 'reason ' . $n);

        $haystacks = [];
        foreach (self::$log->getRecords() as $r) {
            $haystacks[] = $r->message . ' ' . json_encode($r->context);
        }
        foreach (db()->select('SELECT old_values, new_values, reason FROM audit_logs') as $r) {
            $haystacks[] = implode(' ', array_map('strval', $r));
        }
        foreach (db()->select('SELECT title, body, data FROM notifications') as $r) {
            $haystacks[] = implode(' ', array_map('strval', $r));
        }
        foreach (glob(dirname(__DIR__, 2) . '/storage/logs/testing/*.log') ?: [] as $file) {
            $haystacks[] = (string) file_get_contents($file);
        }
        // the visitors XLSX export (all columns)
        $registry = App::container()->get(ReportRegistry::class);
        $report = $registry->find('visitors');
        self::assertNotNull($report);
        $filters = ReportFilters::make($report, [], date('Y-m-d'));
        $xlsx = App::container()->get(ReportExporter::class)->xlsxBytes($report->run($filters), $filters, 'test');
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $xlsx);
        $zip = new \ZipArchive();
        $zip->open($tmp);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $haystacks[] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($tmp);

        $all = implode("\n", $haystacks);
        foreach ($numbers as $number) {
            self::assertStringNotContainsString($number, $all);
            self::assertStringNotContainsString(chunk_split($number, 4, ' '), $all);
        }
        self::assertStringNotContainsString('hunter2', $all);
        self::assertStringContainsString('XXXX XXXX ' . substr($n, -4), $all, 'masked form is kept');
    }

    public function testUploadGuardsRejectBombsAndActivePdfs(): void
    {
        $store = App::container()->get(DocumentStore::class);
        $customerId = (int) db()->scalar('SELECT id FROM customers ORDER BY id LIMIT 1') ?: 1;

        // 1) PNG whose header claims 20000 × 20000 px (a decompression bomb) — rejected before decoding
        $tmp = tempnam(sys_get_temp_dir(), 'bomb');
        $im = imagecreatetruecolor(2, 2);
        imagepng($im, $tmp, 9);
        $png = (string) file_get_contents($tmp);
        // patch the IHDR width/height to 20000 × 20000 (getimagesize reads the header only; the CRC is not checked)
        $png = substr_replace($png, pack('NN', 20000, 20000), 16, 8);
        file_put_contents($tmp, $png);
        try {
            $store->store($customerId, DocumentType::Other, ['name' => 'bomb.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], HolderType::Account, null, trusted: true);
            self::fail('decompression bomb accepted');
        } catch (ValidationException $e) {
            self::assertStringContainsString('too large', implode(' ', $e->errors()['file'] ?? []));
        }

        // 2) PDF with JavaScript
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF");
        try {
            $store->store($customerId, DocumentType::Other, ['name' => 'scan.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], HolderType::Account, null, trusted: true);
            self::fail('PDF with JavaScript accepted');
        } catch (ValidationException $e) {
            self::assertStringContainsString('scripts', implode(' ', $e->errors()['file'] ?? []));
        }

        // 3) XLSX zip bomb: a tiny zip that expands to 80 MB
        $zipPath = tempnam(sys_get_temp_dir(), 'zipbomb') . '.xlsx';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat('A', 80 * 1024 * 1024));
        $zip->close();
        self::assertLessThan(1024 * 1024, filesize($zipPath));
        $staff = (array) db()->first("SELECT * FROM staff_users WHERE role = 'centre_manager' LIMIT 1");
        try {
            App::container()->get(ImportService::class)->upload('individuals', ['name' => 'visitors.xlsx', 'tmp_name' => $zipPath, 'error' => UPLOAD_ERR_OK], $staff, true);
            self::fail('zip bomb accepted');
        } catch (ValidationException $e) {
            self::assertMatchesRegularExpression('/expands|zip bomb|valid/', implode(' ', $e->errors()['file'] ?? []));
        } finally {
            @unlink($zipPath);
            @unlink($tmp);
        }
    }

    public function testEnvironmentCheckReportsFailuresForUnsafeProductionSettings(): void
    {
        $checks = (new EnvironmentCheck(dirname(__DIR__, 2)))->run();
        $by = [];
        foreach ($checks as $c) {
            $by[$c['name']] = $c['status'];
        }
        self::assertSame('ok', $by['APP_KEY']);
        self::assertSame('ok', $by['Connection']);
        self::assertSame('ok', $by['Migrations']);
        self::assertContains($by['Demo staff logins'], ['warn', 'fail'], 'seeded demo logins are flagged');
        self::assertArrayHasKey('bookings:tick', $by);
    }
}
