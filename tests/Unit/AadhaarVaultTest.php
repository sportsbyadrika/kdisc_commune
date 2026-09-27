<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Kyc\AadhaarVault;
use PHPUnit\Framework\TestCase;

final class AadhaarVaultTest extends TestCase
{
    private function vault(?string $key = null): AadhaarVault
    {
        return new AadhaarVault($key ?? 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $vault = $this->vault();
        $cipher = $vault->encrypt('2341 2341 2346');
        self::assertStringNotContainsString('234123412346', $cipher);
        self::assertSame('234123412346', $vault->decrypt($cipher));
        self::assertLessThanOrEqual(255, strlen($cipher)); // fits VARBINARY(255)
        // random nonce: same input, different ciphertext
        self::assertNotSame($cipher, $vault->encrypt('234123412346'));
    }

    public function testHashIsDeterministicKeyedAndNormalised(): void
    {
        $a = $this->vault();
        self::assertSame($a->hash('234123412346'), $a->hash('2341-2341-2346'));
        self::assertSame(64, strlen($a->hash('234123412346')));
        self::assertNotSame(hash('sha256', '234123412346'), $a->hash('234123412346'));
        self::assertNotSame($a->hash('234123412346'), $this->vault('base64:' . base64_encode(str_repeat('z', 32)))->hash('234123412346'));
    }

    public function testProtectAndMask(): void
    {
        $cols = $this->vault()->protect('4991 2345 6783');
        self::assertSame('6783', $cols['aadhaar_last4']);
        self::assertSame('XXXX XXXX 6783', AadhaarVault::mask($cols['aadhaar_last4']));
        self::assertSame('499123456783', $this->vault()->decrypt($cols['aadhaar_enc']));
    }

    public function testWrongKeyCannotDecrypt(): void
    {
        $cipher = $this->vault()->encrypt('234123412346');
        $this->expectException(\RuntimeException::class);
        $this->vault('base64:' . base64_encode(str_repeat('x', 32)))->decrypt($cipher);
    }

    public function testRejectsBadKey(): void
    {
        $this->expectException(\RuntimeException::class);
        new AadhaarVault('base64:' . base64_encode('short'));
    }
}
