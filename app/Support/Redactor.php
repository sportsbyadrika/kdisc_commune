<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Last line of defence for sensitive data in logs and the audit trail (spec 12, Aadhaar Act): whatever a caller
 * passes, full Aadhaar numbers, passwords, tokens and secrets never reach storage/logs or audit_logs.
 *
 *  - keys named like aadhaar / aadhaar_number / aadhaar_enc / aadhaar_hash / sig_aadhaar → removed
 *    (aadhaar_last4 is already masked and kept)
 *  - keys named like password / token / secret / api_key / authorization / captcha → "[redacted]"
 *  - any 12-digit number that looks like an Aadhaar (4-4-4 with optional spaces/dashes, first digit 2–9) inside a
 *    string → "XXXX XXXX 1234"
 */
final class Redactor
{
    private const DROP = '/^(sig_)?aadhaar(_number|_no|_enc|_hash)?$|_aadhaar$/i';

    private const HIDE = '/(pass(word)?|token|secret|api_?key|authorization|captcha|app_key)/i';

    private const AADHAAR = '/(?<![\d+])([2-9]\d{3})[\s-]?(\d{4})[\s-]?(\d{4})(?![\d])/';

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function array(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $key !== 'aadhaar_last4' && preg_match(self::DROP, $key) === 1) {
                continue;
            }
            if (is_string($key) && preg_match(self::HIDE, $key) === 1 && $key !== 'password_changed_at') {
                $out[$key] = '[redacted]';
                continue;
            }
            $out[$key] = match (true) {
                is_array($value) => self::array($value),
                is_string($value) => self::string($value),
                default => $value,
            };
        }
        return $out;
    }

    public static function string(string $value): string
    {
        return (string) preg_replace_callback(self::AADHAAR, static fn (array $m) => 'XXXX XXXX ' . $m[3], $value);
    }
}
