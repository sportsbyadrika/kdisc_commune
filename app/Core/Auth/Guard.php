<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Database;
use App\Core\Session;

/**
 * Session-based authentication guard. Two guards exist (config/auth.php):
 *   'staff'   -> staff_users table, staff session cookie
 *   'visitor' -> accounts table,    site session cookie
 *
 *   auth('staff')->user(), auth('staff')->check(), auth('staff')->login($row), auth('staff')->logout()
 */
final class Guard
{
    /** @var array<string, mixed>|null|false false = not loaded yet */
    private array|null|false $user = false;

    /**
     * @param array{table: string, active_column?: string|null} $config
     */
    public function __construct(
        private readonly string $name,
        private readonly array $config,
        private readonly Session $session,
        private readonly Database $db,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    private function sessionKey(): string
    {
        return '_auth_' . $this->name;
    }

    public function id(): ?int
    {
        $id = $this->session->get($this->sessionKey());
        return is_int($id) ? $id : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    /** @return array<string, mixed>|null the user row (password hash removed) */
    public function user(): ?array
    {
        if ($this->user !== false) {
            return $this->user;
        }
        $id = $this->id();
        if ($id === null) {
            return $this->user = null;
        }
        $table = $this->db->quoteIdentifier($this->config['table']);
        $row = $this->db->first("SELECT * FROM {$table} WHERE id = ?", [$id]);
        $active = $this->config['active_column'] ?? null;
        if ($row === null || ($active !== null && isset($row[$active]) && !$this->isActive($row[$active])) || $this->staleAfterPasswordChange($row)) {
            $this->session->forget($this->sessionKey());
            return $this->user = null;
        }
        unset($row['password_hash']);
        return $this->user = $row;
    }

    /** @param array<string, mixed> $user */
    public function login(array $user): void
    {
        $this->session->regenerate();
        $this->session->put($this->sessionKey(), (int) $user['id']);
        $this->session->put('_auth_' . $this->name . '_at', time());
        unset($user['password_hash']);
        $this->user = $user;
    }

    public function logout(): void
    {
        $this->session->forget($this->sessionKey());
        $this->session->invalidate();
        $this->user = null;
    }

    /**
     * Sessions that signed in before the password was last changed (reset link, staff admin reset) are dropped, so a
     * password change signs out every other browser.
     *
     * @param array<string, mixed> $row
     */
    private function staleAfterPasswordChange(array $row): bool
    {
        $changed = $row['password_changed_at'] ?? null;
        $loggedInAt = $this->session->get('_auth_' . $this->name . '_at');
        if (!is_string($changed) || $changed === '' || !is_int($loggedInAt)) {
            return false;
        }
        return $loggedInAt < (int) strtotime($changed);
    }

    private function isActive(mixed $value): bool
    {
        return $value === 1 || $value === '1' || $value === true || $value === 'active';
    }
}
