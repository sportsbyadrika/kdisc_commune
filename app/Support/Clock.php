<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Injectable "now" (resolved as a per-request singleton by the container). Time-based rules — seat-hold
 * expiry, availability — read the time from here so tests can freeze it:
 *   App::container()->get(Clock::class)->freeze(new DateTimeImmutable('2026-10-01 09:00'));
 */
final class Clock
{
    private ?DateTimeImmutable $frozen = null;

    public function now(): DateTimeImmutable
    {
        return $this->frozen ?? new DateTimeImmutable();
    }

    public function freeze(?DateTimeImmutable $at): void
    {
        $this->frozen = $at;
    }

    public function advance(string $modifier): void
    {
        $this->frozen = $this->now()->modify($modifier);
    }

    /** "Y-m-d H:i:s" in the app timezone (matches the DB session timezone). */
    public function sql(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    public function today(): string
    {
        return $this->now()->format('Y-m-d');
    }
}
