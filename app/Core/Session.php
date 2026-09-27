<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Native PHP session with hardened cookie params, flash data and old input.
 *
 * Staff and visitors use DIFFERENT session cookies (see config/session.php):
 * requests under /staff start the "staff" session, everything else the "site"
 * session. A staff login therefore never leaks into the visitor portal.
 *
 * Flash data lives for exactly one subsequent request.
 */
final class Session
{
    private static ?Session $current = null;

    private bool $started = false;

    /** @param array<string, mixed> $options */
    public function __construct(private readonly array $options = [])
    {
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function setCurrent(?self $session): void
    {
        self::$current = $session;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (PHP_SAPI === 'cli' || headers_sent()) {
            // Tests / console: in-memory array session.
            $_SESSION ??= [];
        } else {
            $savePath = (string) ($this->options['save_path'] ?? '');
            if ($savePath !== '') {
                if (!is_dir($savePath)) {
                    @mkdir($savePath, 0770, true);
                }
                session_save_path($savePath);
            }
            session_name((string) ($this->options['name'] ?? 'commune_session'));
            session_set_cookie_params([
                'lifetime' => (int) ($this->options['lifetime'] ?? 0),
                'path' => (string) ($this->options['path'] ?? '/'),
                'domain' => (string) ($this->options['domain'] ?? ''),
                'secure' => (bool) ($this->options['secure'] ?? false),
                'httponly' => true,
                'samesite' => (string) ($this->options['samesite'] ?? 'Lax'),
            ]);
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.gc_maxlifetime', (string) (int) ($this->options['idle_timeout'] ?? 7200));
            session_start();
        }
        $this->started = true;
        $this->ageFlashData();
        $this->enforceIdleTimeout();
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function name(): string
    {
        return (string) ($this->options['name'] ?? 'commune_session');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $_SESSION ?? [];
    }

    public function id(): string
    {
        return session_id() ?: 'cli';
    }

    /** Regenerate the session id (call on login / privilege change). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->forget('_csrf_token');
    }

    /** Destroy all data and issue a fresh id (logout). */
    public function invalidate(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    // ---------------------------------------------------------------- flash

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash']['new'][$key] = $value;
    }

    /** Flash for the current request only (e.g. rendering a page right away). */
    public function now(string $key, mixed $value): void
    {
        $_SESSION['_flash']['old'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash']['new'][$key] ?? $_SESSION['_flash']['old'][$key] ?? $default;
    }

    public function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash']['new'][$key]) || isset($_SESSION['_flash']['old'][$key]);
    }

    public function reflash(): void
    {
        $_SESSION['_flash']['new'] = array_merge($_SESSION['_flash']['old'] ?? [], $_SESSION['_flash']['new'] ?? []);
    }

    /** @param array<string, mixed> $input */
    public function flashInput(array $input): void
    {
        $hidden = ['password', 'password_confirmation', 'current_password', '_token', 'aadhaar', 'aadhaar_number'];
        $this->flash('_old_input', array_diff_key($input, array_flip($hidden)));
    }

    public function old(string $key, mixed $default = null): mixed
    {
        $old = $this->getFlash('_old_input', []);
        return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        $errors = $this->getFlash('_errors', []);
        return is_array($errors) ? $errors : [];
    }

    private function ageFlashData(): void
    {
        $_SESSION['_flash'] = ['old' => $_SESSION['_flash']['new'] ?? [], 'new' => []];
    }

    private function enforceIdleTimeout(): void
    {
        $timeout = (int) ($this->options['idle_timeout'] ?? 0);
        $now = time();
        $last = (int) ($_SESSION['_last_activity'] ?? $now);
        if ($timeout > 0 && ($now - $last) > $timeout) {
            $_SESSION = ['_flash' => ['old' => ['warning' => 'Your session expired due to inactivity. Please sign in again.'], 'new' => []]];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
        }
        $_SESSION['_last_activity'] = $now;
    }
}
