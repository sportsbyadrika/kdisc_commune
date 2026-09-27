<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Core\App;
use RuntimeException;

/**
 * Aadhaar protection (spec 12, Aadhaar Act / UIDAI):
 *  - the number is encrypted at rest with libsodium secretbox (XSalsa20-Poly1305) → `aadhaar_enc`
 *  - a keyed HMAC-SHA256 → `aadhaar_hash`, used ONLY for duplicate detection (no plain SHA: 12-digit space is tiny)
 *  - the last 4 digits → `aadhaar_last4`, the only part ever shown ("XXXX XXXX 1234")
 *
 * Two sub-keys are derived from APP_KEY with the libsodium KDF so the encryption key and the
 * HMAC key are independent. Rotating APP_KEY requires re-encrypting every row (not automated yet).
 *
 *   $cols = $vault->protect('2341 2341 2346');  // ['aadhaar_enc' => …, 'aadhaar_hash' => …, 'aadhaar_last4' => '2346']
 *   $vault->decrypt($row['aadhaar_enc']);       // for authorised exports only — never render it
 */
final class AadhaarVault
{
    private const CONTEXT = 'aadhaar_';

    private readonly string $encKey;

    private readonly string $macKey;

    public function __construct(?string $appKey = null)
    {
        $raw = self::decodeKey($appKey ?? (string) App::config('app.key', ''));
        $this->encKey = sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 1, self::CONTEXT, $raw);
        $this->macKey = sodium_crypto_kdf_derive_from_key(32, 2, self::CONTEXT, $raw);
        sodium_memzero($raw);
    }

    public static function decodeKey(string $key): string
    {
        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set. Run "php bin/console key:generate" and add it to .env.');
        }
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
            throw new RuntimeException('APP_KEY must be 32 bytes (use "php bin/console key:generate").');
        }
        return $raw;
    }

    public static function normalize(string $aadhaar): string
    {
        return (string) preg_replace('/\D+/', '', $aadhaar);
    }

    /** Binary nonce||ciphertext (24 + 12 + 16 bytes) for the VARBINARY column. */
    public function encrypt(string $aadhaar): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox(self::normalize($aadhaar), $nonce, $this->encKey);
    }

    public function decrypt(string $payload): string
    {
        if (strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid Aadhaar ciphertext.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->encKey,
        );
        if ($plain === false) {
            throw new RuntimeException('Aadhaar ciphertext could not be decrypted (wrong APP_KEY?).');
        }
        return $plain;
    }

    /** Deterministic keyed hash (hex) for duplicate checks. */
    public function hash(string $aadhaar): string
    {
        return hash_hmac('sha256', self::normalize($aadhaar), $this->macKey);
    }

    public static function last4(string $aadhaar): string
    {
        return substr(self::normalize($aadhaar), -4);
    }

    public static function mask(?string $last4): string
    {
        return IdValidator::maskAadhaar($last4);
    }

    /**
     * Column values for customers / customer_signatories.
     * @return array{aadhaar_enc: string, aadhaar_hash: string, aadhaar_last4: string}
     */
    public function protect(string $aadhaar): array
    {
        return [
            'aadhaar_enc' => $this->encrypt($aadhaar),
            'aadhaar_hash' => $this->hash($aadhaar),
            'aadhaar_last4' => self::last4($aadhaar),
        ];
    }
}
