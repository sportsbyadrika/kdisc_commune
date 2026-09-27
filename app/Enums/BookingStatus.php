<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Booking lifecycle (spec 8) — the allowed transitions live in transitions(); BookingWorkflow enforces them:
 *
 *   requested ──approve──▶ approved ──payment──▶ confirmed ──check-in / start date──▶ active ──check-out / end──▶ completed
 *       │ reject                │ cancel / expire      │ cancel
 *       ▼                       ▼                      ▼
 *   rejected                cancelled             cancelled         (requested can also be cancelled)
 */
enum BookingStatus: string
{
    use EnumHelpers;

    case Requested = 'requested';
    case Approved = 'approved';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Confirmed => 'Confirmed',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Rejected => 'Rejected',
        };
    }

    /** @return list<self> statuses this one may move to */
    public function transitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Active, self::Cancelled],
            self::Active => [self::Completed],
            self::Completed, self::Cancelled, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    /** Not started yet — can still be cancelled (the visitor may cancel requested/approved themselves). */
    public function isPreActive(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Confirmed], true);
    }

    public function visitorCanCancel(): bool
    {
        return in_array($this, [self::Requested, self::Approved], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Rejected], true);
    }

    /** Bookings whose money matters (dues are owed once a booking is approved). */
    public function billable(): bool
    {
        return in_array($this, [self::Approved, self::Confirmed, self::Active, self::Completed], true);
    }

    /** @return list<string> */
    public static function billableValues(): array
    {
        return [self::Approved->value, self::Confirmed->value, self::Active->value, self::Completed->value];
    }

    public function verb(): string
    {
        return match ($this) {
            self::Requested => 'requested',
            self::Approved => 'approved',
            self::Confirmed => 'confirmed',
            self::Active => 'started',
            self::Completed => 'completed',
            self::Cancelled => 'cancelled',
            self::Rejected => 'declined',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Requested => 'warning',
            self::Approved => 'info',
            self::Confirmed => 'brand',
            self::Active => 'success',
            self::Completed => 'neutral',
            self::Cancelled => 'neutral',
            self::Rejected => 'danger',
        };
    }
}
