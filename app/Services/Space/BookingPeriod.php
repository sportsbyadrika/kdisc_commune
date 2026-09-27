<?php

declare(strict_types=1);

namespace App\Services\Space;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The period a selection / hold / booking covers.
 *
 *   day-based (flexi, dedicated, cabin): from..to inclusive dates, no times
 *   hourly (conference room):            one date, start/end on whole hours ("09:00".."12:00")
 *
 * Used by AvailabilityService (overlaps), SeatHoldService (seat_holds.start_at/end_at) and the
 * pricing Duration.
 */
final class BookingPeriod
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $startTime = null,
        public readonly ?string $endTime = null,
    ) {
    }

    public static function days(string $from, string $to): self
    {
        self::assertDate($from);
        self::assertDate($to);
        if ($to < $from) {
            throw new InvalidArgumentException('The end date must be on or after the start date.');
        }
        return new self($from, $to);
    }

    public static function hours(string $date, string $start, string $end): self
    {
        self::assertDate($date);
        $start = self::normaliseTime($start);
        $end = self::normaliseTime($end);
        if ($end <= $start) {
            throw new InvalidArgumentException('The end time must be after the start time.');
        }
        return new self($date, $date, $start, $end);
    }

    /**
     * Build from loose request input: from, to, start_time, end_time. With both times it is an hourly period on "from".
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input, bool $hourly = false): self
    {
        $from = trim((string) ($input['from'] ?? ''));
        $to = trim((string) ($input['to'] ?? '')) ?: $from;
        $start = trim((string) ($input['start_time'] ?? ''));
        $end = trim((string) ($input['end_time'] ?? ''));
        if ($hourly || ($start !== '' && $end !== '')) {
            if ($start === '' || $end === '') {
                throw new InvalidArgumentException('Pick a start and end time for the conference room.');
            }
            return self::hours($from, $start, $end);
        }
        return self::days($from, $to);
    }

    public function isHourly(): bool
    {
        return $this->startTime !== null;
    }

    /** DATETIME lower bound (inclusive) — used for seat_holds.start_at. */
    public function startAt(): string
    {
        return $this->from . ' ' . ($this->startTime ?? '00:00:00');
    }

    /** DATETIME upper bound (exclusive) — used for seat_holds.end_at. */
    public function endAt(): string
    {
        if ($this->endTime !== null) {
            return $this->to . ' ' . $this->endTime;
        }
        return (new DateTimeImmutable($this->to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }

    /** Inclusive number of calendar days. */
    public function dayCount(): int
    {
        return (int) (new DateTimeImmutable($this->from))->diff(new DateTimeImmutable($this->to))->days + 1;
    }

    /** Whole hours for an hourly period (0 for day periods). */
    public function hourCount(): float
    {
        if ($this->startTime === null || $this->endTime === null) {
            return 0.0;
        }
        return (strtotime('1970-01-01 ' . $this->endTime . ' UTC') - strtotime('1970-01-01 ' . $this->startTime . ' UTC')) / 3600;
    }

    public function key(): string
    {
        return $this->startAt() . '|' . $this->endAt();
    }

    /** @return array{from: string, to: string, start_time: ?string, end_time: ?string} */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'start_time' => $this->startTime !== null ? substr($this->startTime, 0, 5) : null,
            'end_time' => $this->endTime !== null ? substr($this->endTime, 0, 5) : null,
        ];
    }

    public function label(): string
    {
        if ($this->isHourly()) {
            return format_date($this->from, 'D, d M Y') . ' · ' . self::hourLabel((string) $this->startTime) . ' – ' . self::hourLabel((string) $this->endTime);
        }
        return $this->from === $this->to ? format_date($this->from) : format_date($this->from) . ' → ' . format_date($this->to);
    }

    public static function hourLabel(string $time): string
    {
        return date('g:i a', (int) strtotime('2000-01-01 ' . $time));
    }

    private static function assertDate(string $d): void
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        if ($dt === false || $dt->format('Y-m-d') !== $d) {
            throw new InvalidArgumentException('Choose valid dates.');
        }
    }

    private static function normaliseTime(string $t): string
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::00)?$/', trim($t), $m)) {
            throw new InvalidArgumentException('Choose a valid time.');
        }
        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }
}
