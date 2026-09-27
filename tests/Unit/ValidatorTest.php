<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Core\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredAndEmail(): void
    {
        $v = Validator::make(['email' => 'bad'], ['name' => 'required', 'email' => 'required|email']);
        self::assertTrue($v->fails());
        self::assertSame(['name', 'email'], array_keys($v->errors()));
        self::assertSame('The name field is required.', $v->errors()['name'][0]);
    }

    public function testValidateReturnsOnlyValidatedTrimmedKeys(): void
    {
        $data = Validator::make(['name' => '  Asha ', 'extra' => 'x'], ['name' => 'required|string'])->validate();
        self::assertSame(['name' => 'Asha'], $data);
    }

    public function testValidateThrowsWithErrors(): void
    {
        try {
            Validator::make([], ['email' => 'required'])->validate();
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
        }
    }

    public function testNullableSkipsOtherRulesWhenEmpty(): void
    {
        self::assertTrue(Validator::make(['mobile' => ''], ['mobile' => 'nullable|mobile_in'])->passes());
        self::assertTrue(Validator::make(['mobile' => '+919876543210'], ['mobile' => 'nullable|mobile_in'])->passes());
        self::assertTrue(Validator::make(['mobile' => '12345'], ['mobile' => 'nullable|mobile_in'])->fails());
    }

    public function testMinMaxAreLengthForStringsAndValueForNumbers(): void
    {
        self::assertTrue(Validator::make(['n' => 'abc'], ['n' => 'min:4'])->fails());
        self::assertTrue(Validator::make(['n' => 'abcd'], ['n' => 'min:4|max:4'])->passes());
        self::assertTrue(Validator::make(['n' => '12'], ['n' => 'integer|min:1|max:9'])->fails());
        self::assertTrue(Validator::make(['n' => '9'], ['n' => 'integer|min:1|max:9'])->passes());
        self::assertTrue(Validator::make(['n' => ['a', 'b']], ['n' => 'array|max:1'])->fails());
    }

    public function testMessagesUseParametersAndFriendlyNames(): void
    {
        $v = Validator::make(['seats_count' => '0'], ['seats_count' => 'integer|min:1'], [], ['seats_count' => 'number of seats']);
        $v->passes();
        self::assertSame('The number of seats must be at least 1.', $v->errors()['seats_count'][0]);
    }

    public function testCustomMessagesOverrideDefaults(): void
    {
        $v = Validator::make([], ['email' => 'required'], ['email.required' => 'We need your email.']);
        $v->passes();
        self::assertSame('We need your email.', $v->errors()['email'][0]);
    }

    public function testInAndRegex(): void
    {
        self::assertTrue(Validator::make(['t' => 'individual'], ['t' => 'in:individual,institution'])->passes());
        self::assertTrue(Validator::make(['t' => 'other'], ['t' => 'in:individual,institution'])->fails());
        // regex may contain "|" and ":" when rules are given as an array
        self::assertTrue(Validator::make(['pan' => 'ABCDE1234F'], ['pan' => ['required', 'regex:/^[A-Z]{5}\d{4}[A-Z]$|^NA:$/']])->passes());
        self::assertTrue(Validator::make(['pan' => 'ABCD1234F'], ['pan' => ['regex:/^[A-Z]{5}\d{4}[A-Z]$/']])->fails());
    }

    public function testDatesAndComparisons(): void
    {
        $rules = ['start' => 'required|date', 'end' => 'required|date|after_or_equal:start'];
        self::assertTrue(Validator::make(['start' => '2026-10-01', 'end' => '2026-10-31'], $rules)->passes());
        self::assertTrue(Validator::make(['start' => '2026-10-01', 'end' => '2026-09-30'], $rules)->fails());
        self::assertTrue(Validator::make(['start' => '2026-02-30', 'end' => '2026-03-01'], $rules)->fails());
        self::assertTrue(Validator::make(['d' => '01/10/2026'], ['d' => 'date_format:d/m/Y'])->passes());
        self::assertTrue(Validator::make(['d' => '2000-01-01'], ['d' => 'after:today'])->fails());
    }

    public function testConfirmedAndPasswordStrength(): void
    {
        $ok = ['password' => 'Str0ng!pass', 'password_confirmation' => 'Str0ng!pass'];
        self::assertTrue(Validator::make($ok, ['password' => 'required|password|confirmed'])->passes());
        self::assertTrue(Validator::make(['password' => 'weakpass', 'password_confirmation' => 'weakpass'], ['password' => 'password'])->fails());
        self::assertTrue(Validator::make(['password' => 'Str0ng!pass', 'password_confirmation' => 'x'], ['password' => 'confirmed'])->fails());
    }

    public function testAcceptedAndRequiredIf(): void
    {
        self::assertTrue(Validator::make([], ['consent' => 'accepted'])->fails());
        self::assertTrue(Validator::make(['consent' => 'on'], ['consent' => 'accepted'])->passes());
        $rules = ['passport_no' => 'required_if:nationality,foreign'];
        self::assertTrue(Validator::make(['nationality' => 'foreign'], $rules)->fails());
        self::assertTrue(Validator::make(['nationality' => 'indian'], $rules)->passes());
    }

    public function testClosureRules(): void
    {
        $even = fn ($v) => ((int) $v) % 2 === 0 ?: 'Seats must be booked in pairs.';
        self::assertTrue(Validator::make(['n' => '4'], ['n' => ['required', $even]])->passes());
        $v = Validator::make(['n' => '3'], ['n' => ['required', $even]]);
        self::assertTrue($v->fails());
        self::assertSame('Seats must be booked in pairs.', $v->errors()['n'][0]);
    }

    public function testExtendRegistersReusableRule(): void
    {
        Validator::extend('tan', static fn ($value) => (bool) preg_match('/^[A-Z]{4}\d{5}[A-Z]$/', (string) $value), 'Enter a valid TAN.');
        self::assertTrue(Validator::make(['tan' => 'ABCD12345E'], ['tan' => 'tan'])->passes());
        $v = Validator::make(['tan' => 'bad'], ['tan' => 'tan']);
        self::assertTrue($v->fails());
        self::assertSame('Enter a valid TAN.', $v->errors()['tan'][0]);
    }

    public function testUnknownRuleThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['x' => '1'], ['x' => 'no_such_rule'])->passes();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function mobiles(): iterable
    {
        yield 'with +91' => ['+919876543210', true];
        yield 'with +91 and space' => ['+91 9876543210', true];
        yield 'bare 10 digits' => ['9876543210', true];
        yield 'starts with 5' => ['5876543210', false];
        yield 'too short' => ['98765', false];
    }

    #[DataProvider('mobiles')]
    public function testMobileIn(string $mobile, bool $valid): void
    {
        self::assertSame($valid, Validator::make(['m' => $mobile], ['m' => 'mobile_in'])->passes());
    }
}
