<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use App\Services\Kyc\IdValidator;
use App\Services\Kyc\KycRules;
use App\Services\Kyc\Verhoeff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdValidatorTest extends TestCase
{
    public function testVerhoeffCheckDigitRoundTrip(): void
    {
        self::assertSame('6', Verhoeff::checkDigit('23412341234'));
        self::assertTrue(Verhoeff::validate('234123412346'));
        self::assertFalse(Verhoeff::validate('234123412345'));
        // every single-digit error is detected
        foreach (range(0, 9) as $d) {
            if ($d !== 6) {
                self::assertFalse(Verhoeff::validate('23412341234' . $d));
            }
        }
        // adjacent transposition is detected
        self::assertFalse(Verhoeff::validate('243123412346'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function aadhaars(): iterable
    {
        yield 'valid' => ['234123412346', true];
        yield 'valid with spaces' => ['2341 2341 2346', true];
        yield 'valid with dashes' => ['4991-2345-6783', true];
        yield 'bad checksum' => ['234123412345', false];
        yield 'starts with 1' => ['1' . '2341234123' . Verhoeff::checkDigit('12341234123'), false];
        yield 'starts with 0' => ['0' . '2341234123' . Verhoeff::checkDigit('02341234123'), false];
        yield 'eleven digits' => ['23412341234', false];
        yield 'letters' => ['23412341234A', false];
    }

    #[DataProvider('aadhaars')]
    public function testAadhaar(string $value, bool $valid): void
    {
        self::assertSame($valid, IdValidator::aadhaar($value));
    }

    public function testPan(): void
    {
        self::assertTrue(IdValidator::pan('ABCPE1234F'));
        self::assertTrue(IdValidator::pan('aabck1234l'));      // normalised to upper case
        self::assertFalse(IdValidator::pan('ABCXE1234F'));     // 4th char must be a valid holder type
        self::assertFalse(IdValidator::pan('ABCP1234F'));
        self::assertFalse(IdValidator::pan('ABCPE12345'));
    }

    public function testTanAndPassport(): void
    {
        self::assertTrue(IdValidator::tan('TVDK12345E'));
        self::assertFalse(IdValidator::tan('TVD123456E'));
        self::assertTrue(IdValidator::passport('C01X00T47'));
        self::assertFalse(IdValidator::passport('AB12'));
        self::assertFalse(IdValidator::passport('AB12/3456'));
    }

    public function testGstinChecksumPanAndState(): void
    {
        self::assertTrue(IdValidator::gstin('27AAPFU0939F1ZV'));          // published sample GSTIN
        self::assertSame('V', IdValidator::gstinCheckChar('27AAPFU0939F1Z'));
        self::assertTrue(IdValidator::gstin('32AABCK1234L1ZV'));
        self::assertFalse(IdValidator::gstin('32AABCK1234L1ZW'));         // wrong check character
        self::assertFalse(IdValidator::gstin('39AABCK1234L1ZV'));         // no such state code
        self::assertFalse(IdValidator::gstin('32AABCK1234L1V'));          // too short
        self::assertTrue(IdValidator::gstinMatchesPan('32AABCK1234L1ZV', 'AABCK1234L'));
        self::assertFalse(IdValidator::gstinMatchesPan('32AABCK1234L1ZV', 'ABCPE1234F'));
        self::assertTrue(IdValidator::gstinMatchesState('32AABCK1234L1ZV', '32'));
        self::assertFalse(IdValidator::gstinMatchesState('32AABCK1234L1ZV', '29'));
    }

    public function testMobileNormalisation(): void
    {
        self::assertSame('+919847012345', IdValidator::normalizeMobile('+91 98470 12345'));
        self::assertSame('+919847012345', IdValidator::normalizeMobile('09847012345'));
        self::assertNull(IdValidator::normalizeMobile('5847012345'));
        self::assertTrue(IdValidator::phone('0474 2450000'));
        self::assertFalse(IdValidator::phone('12345'));
        self::assertSame('XXXX XXXX 2346', IdValidator::maskAadhaar('2346'));
    }

    public function testValidatorRulesAreRegistered(): void
    {
        KycRules::register();
        $ok = Validator::make(
            ['aadhaar' => '2341 2341 2346', 'pan' => 'AABCK1234L', 'gstin' => '32AABCK1234L1ZV', 'tan' => 'TVDK12345E', 'state_code' => '32', 'phone' => '0474 2450000'],
            ['aadhaar' => 'aadhaar', 'pan' => 'pan', 'gstin' => 'gstin|gstin_pan:pan|gstin_state:state_code', 'tan' => 'tan', 'phone' => 'phone_in'],
        );
        self::assertTrue($ok->passes(), json_encode($ok->errors()) ?: '');

        $bad = Validator::make(['pan' => 'ABCPE1234F', 'gstin' => '32AABCK1234L1ZV', 'state_code' => '29'], ['gstin' => 'gstin|gstin_pan:pan']);
        self::assertTrue($bad->fails());
        self::assertSame(['gstin' => ['The GSTIN does not contain the PAN you entered.']], $bad->errors());

        $state = Validator::make(['gstin' => '32AABCK1234L1ZV', 'state_code' => '29'], ['gstin' => 'gstin|gstin_state:state_code']);
        self::assertTrue($state->fails());
    }
}
