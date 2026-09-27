<?php

declare(strict_types=1);

namespace App\Services\Kyc;

/**
 * Format + checksum validation of Indian KYC identifiers (spec 4.3). Pure functions, no I/O.
 * The same rules are mirrored client-side in resources/js/app.js (Commune.kyc) for instant feedback.
 *
 *   IdValidator::aadhaar('2341 2341 2346')   IdValidator::pan('ABCPE1234F')
 *   IdValidator::gstin('32ABCPE1234F1Z5')    IdValidator::gstinMatchesPan($gstin, $pan)
 */
final class IdValidator
{
    public const PAN_REGEX = '/^[A-Z]{3}[ABCFGHJLPT][A-Z][0-9]{4}[A-Z]$/';
    public const TAN_REGEX = '/^[A-Z]{4}[0-9]{5}[A-Z]$/';
    public const GSTIN_REGEX = '/^[0-9]{2}[A-Z]{3}[ABCFGHJLPT][A-Z][0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';
    public const PASSPORT_REGEX = '/^[A-Z0-9]{6,12}$/';
    public const MOBILE_REGEX = '/^(?:\+?91[\s-]?|0)?([6-9][0-9]{9})$/';
    private const GST_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Strip spaces / dashes and upper-case: '32abc pe1234f-1z5' -> '32ABCPE1234F1Z5'. */
    public static function normalize(?string $value): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', (string) $value));
    }

    /** 12 digits, first digit 2-9, valid Verhoeff check digit. Spaces are ignored. */
    public static function aadhaar(?string $value): bool
    {
        $n = self::normalize($value);
        return preg_match('/^[2-9][0-9]{11}$/', $n) === 1 && Verhoeff::validate($n);
    }

    public static function pan(?string $value): bool
    {
        return preg_match(self::PAN_REGEX, self::normalize($value)) === 1;
    }

    public static function tan(?string $value): bool
    {
        return preg_match(self::TAN_REGEX, self::normalize($value)) === 1;
    }

    public static function passport(?string $value): bool
    {
        return preg_match(self::PASSPORT_REGEX, self::normalize($value)) === 1;
    }

    /** 15 characters: state code (01-38, 97, 99) + PAN + entity no. + 'Z' + check character. */
    public static function gstin(?string $value): bool
    {
        $g = self::normalize($value);
        if (preg_match(self::GSTIN_REGEX, $g) !== 1) {
            return false;
        }
        $state = (int) substr($g, 0, 2);
        if (!(($state >= 1 && $state <= 38) || $state === 97 || $state === 99)) {
            return false;
        }
        return self::gstinCheckChar(substr($g, 0, 14)) === $g[14];
    }

    /** GSTIN check character for the first 14 characters (mod-36 Luhn variant). */
    public static function gstinCheckChar(string $first14): string
    {
        $first14 = strtoupper($first14);
        $sum = 0;
        foreach (str_split($first14) as $i => $char) {
            $value = strpos(self::GST_CHARSET, $char);
            if ($value === false) {
                throw new \InvalidArgumentException('Invalid GSTIN character.');
            }
            $product = $value * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 36) + $product % 36;
        }
        return self::GST_CHARSET[(36 - $sum % 36) % 36];
    }

    /** Characters 3-12 of a GSTIN are the holder's PAN. */
    public static function gstinMatchesPan(?string $gstin, ?string $pan): bool
    {
        return substr(self::normalize($gstin), 2, 10) === self::normalize($pan);
    }

    /** First two characters of a GSTIN are the GST state code (e.g. 32 = Kerala). */
    public static function gstinMatchesState(?string $gstin, ?string $stateCode): bool
    {
        return substr(self::normalize($gstin), 0, 2) === str_pad((string) $stateCode, 2, '0', STR_PAD_LEFT);
    }

    /** Indian mobile: optional +91 / 91 / 0 prefix, then 10 digits starting 6-9. */
    public static function mobile(?string $value): bool
    {
        return preg_match(self::MOBILE_REGEX, (string) preg_replace('/[\s\-()]+/', '', (string) $value)) === 1;
    }

    /** Canonical storage format '+919876543210', or null when invalid. */
    public static function normalizeMobile(?string $value): ?string
    {
        $v = (string) preg_replace('/[\s\-()]+/', '', (string) $value);
        return preg_match(self::MOBILE_REGEX, $v, $m) === 1 ? '+91' . $m[1] : null;
    }

    /** Indian phone for institutions: a mobile, or a landline with STD code (10-11 digits after an optional +91 / 0). */
    public static function phone(?string $value): bool
    {
        if (self::mobile($value)) {
            return true;
        }
        $digits = (string) preg_replace('/\D+/', '', (string) $value);
        if (str_starts_with($digits, '91') && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }
        return preg_match('/^0?[1-9][0-9]{9}$/', $digits) === 1;
    }

    /** Mask a stored last-4: 'XXXX XXXX 1234'. */
    public static function maskAadhaar(?string $last4): string
    {
        return $last4 !== null && $last4 !== '' ? 'XXXX XXXX ' . $last4 : '—';
    }
}
