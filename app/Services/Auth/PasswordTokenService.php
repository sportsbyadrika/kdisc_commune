<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Database;
use App\Enums\HolderType;
use App\Enums\TokenPurpose;

/**
 * Single-use password links (spec 4.1 / 12): set-password (doubles as email verification), reset, invite.
 *
 *  - The emailed token is 32 random bytes (base64url, 43 chars); only its SHA-256 is stored.
 *  - Expires after setting('password_token_minutes', 60) minutes; issuing a new token for the same
 *    subject revokes older unused ones.
 *  - consume() marks it used atomically (UPDATE … WHERE used_at IS NULL), so a link works exactly once.
 *
 *   $token = $tokens->issue(HolderType::Account, $accountId, TokenPurpose::Set);
 *   $row   = $tokens->find($token);      // peek (render the form) — null when invalid/expired/used
 *   $row   = $tokens->consume($token);   // on submit
 */
final class PasswordTokenService
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function issue(HolderType $subject, int $subjectId, TokenPurpose $purpose, ?int $minutes = null): string
    {
        $minutes ??= $this->lifetimeMinutes();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = date('Y-m-d H:i:s');
        $this->db->transaction(function (Database $db) use ($subject, $subjectId, $purpose, $token, $minutes, $now): void {
            $db->execute(
                'UPDATE password_tokens SET used_at = ? WHERE subject_type = ? AND subject_id = ? AND used_at IS NULL',
                [$now, $subject->value, $subjectId],
            );
            $db->insert('password_tokens', [
                'subject_type' => $subject->value,
                'subject_id' => $subjectId,
                'token_hash' => self::hash($token),
                'purpose' => $purpose->value,
                'expires_at' => date('Y-m-d H:i:s', time() + $minutes * 60),
            ]);
        });
        return $token;
    }

    /** @return array<string, mixed>|null the valid (unused, unexpired) token row */
    public function find(string $token): ?array
    {
        if (!self::looksValid($token)) {
            return null;
        }
        return $this->db->first(
            'SELECT * FROM password_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? LIMIT 1',
            [self::hash($token), date('Y-m-d H:i:s')],
        );
    }

    /** @return array<string, mixed>|null the token row, now marked used; null when invalid/expired/already used */
    public function consume(string $token): ?array
    {
        $row = $this->find($token);
        if ($row === null) {
            return null;
        }
        $affected = $this->db->execute(
            'UPDATE password_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL',
            [date('Y-m-d H:i:s'), $row['id']],
        );
        return $affected === 1 ? $row : null;
    }

    /** Why a token cannot be used: 'expired' | 'used' | 'invalid' (for friendlier error pages). */
    public function failureReason(string $token): string
    {
        if (!self::looksValid($token)) {
            return 'invalid';
        }
        $row = $this->db->first('SELECT used_at, expires_at FROM password_tokens WHERE token_hash = ?', [self::hash($token)]);
        return match (true) {
            $row === null => 'invalid',
            $row['used_at'] !== null => 'used',
            default => 'expired',
        };
    }

    public function lifetimeMinutes(): int
    {
        try {
            return max(5, (int) setting('password_token_minutes', 60));
        } catch (\Throwable) {
            return 60;
        }
    }

    private static function looksValid(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }
}
