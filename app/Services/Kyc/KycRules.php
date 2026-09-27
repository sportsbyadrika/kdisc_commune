<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Core\Validator;

/**
 * Registers the KYC validation rules with the Validator (called from App::boot()).
 *
 *   'aadhaar' => ['required', 'aadhaar']
 *   'pan'     => ['nullable', 'pan']
 *   'gstin'   => ['nullable', 'gstin', 'gstin_pan:pan', 'gstin_state:state_code']
 *   'tan'     => ['required', 'tan']
 *   'passport_no' => ['required_if:nationality_type,foreign', 'passport']
 *   'mobile'  => ['required', 'mobile_in']      // built in
 *   'phone'   => ['required', 'phone_in']       // mobile or landline with STD code
 */
final class KycRules
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        Validator::extend('aadhaar', static fn (mixed $v): bool => is_string($v) && IdValidator::aadhaar($v),
            'Enter a valid 12-digit Aadhaar number.');
        Validator::extend('pan', static fn (mixed $v): bool => is_string($v) && IdValidator::pan($v),
            'Enter a valid PAN (format AAAAA9999A).');
        Validator::extend('tan', static fn (mixed $v): bool => is_string($v) && IdValidator::tan($v),
            'Enter a valid TAN (format AAAA99999A).');
        Validator::extend('gstin', static fn (mixed $v): bool => is_string($v) && IdValidator::gstin($v),
            'Enter a valid 15-character GSTIN.');
        Validator::extend('passport', static fn (mixed $v): bool => is_string($v) && IdValidator::passport($v),
            'Enter a valid passport number (6–12 letters and digits).');
        Validator::extend('phone_in', static fn (mixed $v): bool => is_string($v) && IdValidator::phone($v),
            'Enter a valid Indian phone number with STD code, or a mobile number.');
        // gstin_pan:pan_field — the PAN embedded in the GSTIN must equal the PAN entered in pan_field.
        Validator::extend('gstin_pan', static function (mixed $v, array $params, array $data): bool {
            $pan = $data[$params[0] ?? 'pan'] ?? '';
            return !is_string($pan) || $pan === '' || IdValidator::gstinMatchesPan((string) $v, $pan);
        }, 'The GSTIN does not contain the PAN you entered.');
        // gstin_state:state_field — the GSTIN state code must match the selected state.
        Validator::extend('gstin_state', static function (mixed $v, array $params, array $data): bool {
            $state = $data[$params[0] ?? 'state_code'] ?? '';
            return !is_string($state) || $state === '' || IdValidator::gstinMatchesState((string) $v, $state);
        }, 'The GSTIN state code does not match the selected state.');
    }
}
