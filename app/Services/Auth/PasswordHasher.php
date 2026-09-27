<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\App;

/** Argon2id password hashing (spec 12). Use for staff and visitor passwords. */
final class PasswordHasher
{
    public function hash(string $password): string
    {
        return password_hash($password, $this->algo(), $this->options());
    }

    public function verify(string $password, ?string $hash): bool
    {
        return $hash !== null && $hash !== '' && password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo(), $this->options());
    }

    /** Burn comparable CPU time when the user does not exist (mitigates user enumeration by timing). */
    public function dummyVerify(string $password): void
    {
        static $dummy = null;
        $dummy ??= $this->hash('dummy-password-for-timing');
        password_verify($password, $dummy);
    }

    private function algo(): string
    {
        return (string) (App::isBooted() ? App::config('auth.password.algo', PASSWORD_ARGON2ID) : PASSWORD_ARGON2ID);
    }

    /** @return array<string, int> */
    private function options(): array
    {
        /** @var array<string, int> */
        return App::isBooted() ? (array) App::config('auth.password.options', []) : [];
    }
}
