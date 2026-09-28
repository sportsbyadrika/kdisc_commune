<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Database;

/**
 * Generic fixed-window rate limiter backed by the `rate_limits` table (works across PHP-FPM workers and servers
 * sharing the database; no APCu/Redis needed). Buckets are "name|key" hashed with SHA-256 — the key is usually the
 * client IP plus the signed-in user id, never a secret.
 *
 *   $wait = $limiter->attempt('quote', $ip, 60, 60);   // count a hit; 0 = allowed, else seconds to wait
 *   $wait = $limiter->availableIn('quote', $ip, 60);    // peek without counting
 *   $limiter->clear('quote', $ip);
 *
 * Routes use the `throttle:name,max,minutes` middleware (App\Middleware\Throttle). Sign-in keeps its own
 * LoginThrottle (per email + IP, login_attempts) because it also drives the captcha.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function bucket(string $name, string $key): string
    {
        return hash('sha256', $name . '|' . $key);
    }

    /** Count one hit. Returns 0 when the hit is within the limit, otherwise the seconds until the window resets. */
    public function attempt(string $name, string $key, int $max, int $decaySeconds): int
    {
        $state = $this->hit($name, $key, $decaySeconds);
        if ($state['hits'] > $max) {
            return max(1, $state['reset_in']);
        }
        return 0;
    }

    /**
     * Count one hit and return the window state.
     *
     * @return array{hits: int, reset_in: int}
     */
    public function hit(string $name, string $key, int $decaySeconds): array
    {
        $now = time();
        $nowSql = date('Y-m-d H:i:s', $now);
        $resetSql = date('Y-m-d H:i:s', $now + max(1, $decaySeconds));
        $bucket = self::bucket($name, $key);
        // Assignments run left to right: `hits` must read the OLD reset_at before it is moved forward.
        $this->db->execute(
            'INSERT INTO rate_limits (bucket, name, hits, reset_at) VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE hits = IF(reset_at <= ?, 1, hits + 1), reset_at = IF(reset_at <= ?, ?, reset_at)',
            [$bucket, mb_substr($name, 0, 40), $resetSql, $nowSql, $nowSql, $resetSql],
        );
        $row = $this->db->first('SELECT hits, reset_at FROM rate_limits WHERE bucket = ?', [$bucket]);
        if (random_int(1, 100) === 1) {
            $this->purge();
        }
        return [
            'hits' => (int) ($row['hits'] ?? 1),
            'reset_in' => max(0, (int) strtotime((string) ($row['reset_at'] ?? $resetSql)) - $now),
        ];
    }

    /** Seconds until another hit is allowed (0 = allowed now), without counting. */
    public function availableIn(string $name, string $key, int $max): int
    {
        $row = $this->db->first('SELECT hits, reset_at FROM rate_limits WHERE bucket = ?', [self::bucket($name, $key)]);
        if ($row === null) {
            return 0;
        }
        $resetIn = (int) strtotime((string) $row['reset_at']) - time();
        return $resetIn > 0 && (int) $row['hits'] >= $max ? $resetIn : 0;
    }

    public function clear(string $name, string $key): void
    {
        $this->db->execute('DELETE FROM rate_limits WHERE bucket = ?', [self::bucket($name, $key)]);
    }

    /** Drop windows that ended more than an hour ago. */
    public function purge(): int
    {
        return $this->db->execute('DELETE FROM rate_limits WHERE reset_at < ?', [date('Y-m-d H:i:s', time() - 3600)]);
    }
}
