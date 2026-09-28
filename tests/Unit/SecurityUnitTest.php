<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use App\Services\Security\UploadGuard;
use App\Support\Redactor;
use App\Support\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityUnitTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function redirects(): iterable
    {
        yield 'portal' => ['/my', true];
        yield 'portal page with query' => ['/my/bookings?tab=all&x=a%2Fb', true];
        yield 'explorer' => ['/spaces/explore/ground-floor?from=2026-10-01', true];
        yield 'protocol-relative' => ['//evil.example/my', false];
        yield 'absolute' => ['https://evil.example/my', false];
        yield 'backslash' => ['/\\evil.example/my', false];
        yield 'encoded slash' => ['/my%2F%2Fevil.example', false];
        yield 'encoded backslash' => ['/my/%5C%5Cevil.example', false];
        yield 'dot segments' => ['/my/../staff/users', false];
        yield 'prefix lookalike' => ['/myevil', false];
        yield 'other area' => ['/staff/dashboard', false];
        yield 'control char' => ["/my\r\nLocation: https://evil.example", false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('redirects')]
    public function testSafeRedirect(string $candidate, bool $allowed): void
    {
        self::assertSame($allowed ? $candidate : null, SafeRedirect::path($candidate, ['/my', '/spaces']));
    }

    public function testSafeRedirectRespectsTheBasePath(): void
    {
        self::assertSame('/commune/staff/bookings', SafeRedirect::path('/commune/staff/bookings', ['/staff/'], '/commune'));
        self::assertNull(SafeRedirect::path('/staff/bookings', ['/staff/'], '/commune'));
    }

    public function testSafeRefererOnlyAcceptsThisHost(): void
    {
        $req = static fn (string $ref) => Request::create('POST', '/contact', [], ['HTTP_HOST' => 'commune.example.org', 'HTTP_REFERER' => $ref]);
        self::assertSame('https://commune.example.org/contact?x=1', $req('https://commune.example.org/contact?x=1')->safeReferer());
        self::assertNull($req('https://evil.example/contact')->safeReferer());
        self::assertNull($req('https://commune.example.org.evil.example/')->safeReferer());
        self::assertNull($req('javascript:alert(1)//commune.example.org')->safeReferer());
        self::assertNull($req('//commune.example.org\\@evil.example/')->safeReferer());
    }

    public function testTrustedProxyHeaders(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        Request::setTrustedProxies([]);
        $r = Request::create('GET', '/', [], $server);
        self::assertSame('10.0.0.5', $r->ip(), 'forwarded headers are ignored without trusted proxies');
        self::assertFalse($r->isSecure());
        Request::setTrustedProxies(['10.0.0.5']);
        $r = Request::create('GET', '/', [], $server);
        self::assertSame('203.0.113.9', $r->ip(), 'right-most untrusted hop');
        self::assertTrue($r->isSecure());
        Request::setTrustedProxies([]);
    }

    public function testRedactorMasksAadhaarAndSecrets(): void
    {
        $in = [
            'aadhaar' => '234123412346',
            'aadhaar_number' => '2341 2341 2346',
            'sig_aadhaar' => '234123412346',
            'aadhaar_enc' => 'cipher',
            'aadhaar_hash' => 'hash',
            'aadhaar_last4' => '2346',
            'password' => 'hunter2',
            'password_confirmation' => 'hunter2',
            'token' => 'abc',
            'note' => 'my number is 2341-2341-2346, call me',
            'amount' => 234123412346.0,
            'booking_no' => 'BK-2026-000001',
            'mobile' => '+919400012345',
            'nested' => ['aadhaar' => '234123412346', 'ok' => 'fine'],
        ];
        $out = Redactor::array($in);
        self::assertArrayNotHasKey('aadhaar', $out);
        self::assertArrayNotHasKey('aadhaar_number', $out);
        self::assertArrayNotHasKey('sig_aadhaar', $out);
        self::assertArrayNotHasKey('aadhaar_enc', $out);
        self::assertArrayNotHasKey('aadhaar_hash', $out);
        self::assertSame('2346', $out['aadhaar_last4']);
        self::assertSame('[redacted]', $out['password']);
        self::assertSame('[redacted]', $out['password_confirmation']);
        self::assertSame('[redacted]', $out['token']);
        self::assertSame('my number is XXXX XXXX 2346, call me', $out['note']);
        self::assertSame('BK-2026-000001', $out['booking_no']);
        self::assertSame('+919400012345', $out['mobile'], 'phone numbers are not Aadhaar numbers');
        self::assertSame(['ok' => 'fine'], $out['nested']);
        self::assertSame('Rs 1,23,456 paid', Redactor::string('Rs 1,23,456 paid'));
    }

    public function testImageGuardUsesTheHeaderOnly(): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'img');
        $im = imagecreatetruecolor(4, 3);
        imagepng($im, $tmp);
        self::assertNull(UploadGuard::image($tmp));
        $png = (string) file_get_contents($tmp);
        file_put_contents($tmp, substr_replace($png, pack('NN', 9000, 9000), 16, 8));   // 81 MP claimed
        self::assertStringContainsString('too large', (string) UploadGuard::image($tmp));
        file_put_contents($tmp, 'not an image');
        self::assertNotNull(UploadGuard::image($tmp));
        unlink($tmp);
    }

    public function testPdfGuard(): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($tmp, "%PDF-1.7\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n%%EOF");
        self::assertNull(UploadGuard::pdf($tmp));
        foreach (['/JavaScript', '/JS (x)', '/Launch', '/EmbeddedFile'] as $key) {
            file_put_contents($tmp, "%PDF-1.7\n" . str_repeat('x', 2 << 20) . " {$key} " . "\n%%EOF");
            self::assertNotNull(UploadGuard::pdf($tmp), $key . ' deep inside the file');
        }
        file_put_contents($tmp, "%PDF-1.7\n/JSON-like-name\n%%EOF");
        self::assertNull(UploadGuard::pdf($tmp));
        unlink($tmp);
    }

    public function testZipGuard(): void
    {
        $path = sys_get_temp_dir() . '/guard-' . getmypid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('a.xml', str_repeat('<row/>', 1000));
        $zip->close();
        self::assertNull(UploadGuard::zip($path));
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('bomb.xml', str_repeat('0', 70 * 1024 * 1024));
        $zip->close();
        self::assertNotNull(UploadGuard::zip($path));
        file_put_contents($path, 'PK not really');
        self::assertNotNull(UploadGuard::zip($path));
        unlink($path);
    }
}
