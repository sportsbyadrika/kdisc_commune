<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\App;
use App\Core\Database;

/**
 * Brute-force protection backed by the login_attempts table.
 * Blocks after N failures per (guard, email, IP) or M failures per (guard, IP)
 * within the decay window (config/auth.php "throttle").
 */
final class LoginThrottle
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Seconds until the next attempt is allowed (0 = allowed). */
    public function availableIn(string $guard, string $email, string $ip): int
    {
        $cfg = $this->config();
        $since = date('Y-m-d H:i:s', time() - $cfg['decay_minutes'] * 60);
        $email = mb_strtolower(trim($email));

        $byEmail = $this->db->first(
            'SELECT COUNT(*) AS n, MIN(attempted_at) AS first_at FROM login_attempts
             WHERE guard = ? AND identifier = ? AND ip = ? AND succeeded = 0 AND attempted_at >= ?',
            [$guard, $email, $ip, $since],
        );
        $byIp = $this->db->first(
            'SELECT COUNT(*) AS n, MIN(attempted_at) AS first_at FROM login_attempts
             WHERE guard = ? AND ip = ? AND succeeded = 0 AND attempted_at >= ?',
            [$guard, $ip, $since],
        );

        $wait = 0;
        foreach ([[$byEmail, $cfg['max_attempts']], [$byIp, $cfg['max_attempts_ip']]] as [$row, $max]) {
            if ((int) ($row['n'] ?? 0) >= $max && $row['first_at'] !== null) {
                $wait = max($wait, strtotime((string) $row['first_at']) + $cfg['decay_minutes'] * 60 - time());
            }
        }
        return max(0, $wait);
    }

    /**
     * Recent failures for this email + IP and for the IP alone (drives the sign-in captcha).
     *
     * @return array{email: int, ip: int}
     */
    public function failures(string $guard, string $email, string $ip): array
    {
        $since = date('Y-m-d H:i:s', time() - $this->config()['decay_minutes'] * 60);
        $email = mb_strtolower(trim($email));
        return [
            'email' => $email === '' ? 0 : (int) $this->db->scalar(
                'SELECT COUNT(*) FROM login_attempts WHERE guard = ? AND identifier = ? AND ip = ? AND succeeded = 0 AND attempted_at >= ?',
                [$guard, $email, $ip, $since],
            ),
            'ip' => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM login_attempts WHERE guard = ? AND ip = ? AND succeeded = 0 AND attempted_at >= ?',
                [$guard, $ip, $since],
            ),
        ];
    }

    public function tooManyAttempts(string $guard, string $email, string $ip): bool
    {
        return $this->availableIn($guard, $email, $ip) > 0;
    }

    public function hit(string $guard, string $email, string $ip): void
    {
        $this->db->insert('login_attempts', [
            'guard' => $guard, 'identifier' => mb_strtolower(trim($email)), 'ip' => $ip, 'succeeded' => 0,
        ]);
    }

    /** Record a success and clear the failure counter for this email + IP. */
    public function clear(string $guard, string $email, string $ip): void
    {
        $email = mb_strtolower(trim($email));
        $this->db->execute('DELETE FROM login_attempts WHERE guard = ? AND identifier = ? AND ip = ? AND succeeded = 0', [$guard, $email, $ip]);
        $this->db->insert('login_attempts', ['guard' => $guard, 'identifier' => $email, 'ip' => $ip, 'succeeded' => 1]);
        // housekeeping: drop rows older than 30 days
        $this->db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', strtotime('-30 days'))]);
    }

    /** @return array{max_attempts: int, max_attempts_ip: int, decay_minutes: int} */
    private function config(): array
    {
        $c = (array) App::config('auth.throttle', []);
        return [
            'max_attempts' => (int) ($c['max_attempts'] ?? 5),
            'max_attempts_ip' => (int) ($c['max_attempts_ip'] ?? 20),
            'decay_minutes' => (int) ($c['decay_minutes'] ?? 15),
        ];
    }
}
