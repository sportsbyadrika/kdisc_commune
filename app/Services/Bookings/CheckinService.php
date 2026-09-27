<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Core\Database;
use App\Enums\BookingStatus;
use App\Services\AuditLog;
use App\Support\Clock;

/**
 * Front-desk check-in / check-out per booked seat (spec 6.2), writing `checkins`.
 *
 * Seat identity: a check-in row stores the booked row (seat_id), the booking seat (booking_seat_id) and the
 * stable seats.seat_key. Everything that asks "who is checked in at this seat" matches on seat_key, so a
 * layout publish never loses who is sitting where.
 *
 *   checkIn()   confirmed/active bookings on a day inside their dates; the first check-in of a confirmed
 *               booking activates it (BookingWorkflow::activate)
 *   checkOut()  closes open check-ins; checking out the last seat on/after the end date completes the booking
 *   bySeatKey() open check-ins today for the explorer popover / occupancy map
 *   forVisitor() today's bookings of a visitor (QR / Unique ID check-in page)
 */
final class CheckinService
{
    public function __construct(
        private readonly Database $db,
        private readonly BookingWorkflow $workflow,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Check in all (or one) of a booking's current seats.
     *
     * @return array{checked_in: list<string>, activated: bool}
     */
    public function checkIn(int $bookingId, ?int $bookingSeatId, Actor $actor, string $method = 'desk'): array
    {
        if (!$actor->can('checkins.manage')) {
            throw new WorkflowException('Your role cannot check visitors in.', 'permission');
        }
        $today = $this->clock->today();
        $booking = $this->workflow->find($bookingId);
        $status = BookingStatus::from((string) $booking['status']);
        if (!in_array($status, [BookingStatus::Confirmed, BookingStatus::Active], true)) {
            throw new WorkflowException(match ($status) {
                BookingStatus::Requested, BookingStatus::Approved => sprintf('Booking %s is not confirmed yet — log the payment first.', $booking['booking_no']),
                default => sprintf('Booking %s is %s.', $booking['booking_no'], strtolower($status->label())),
            });
        }
        if ((string) $booking['start_date'] > $today || (string) $booking['end_date'] < $today) {
            throw new WorkflowException(sprintf('Booking %s runs %s → %s — check-in is only possible within those dates.', $booking['booking_no'], format_date((string) $booking['start_date']), format_date((string) $booking['end_date'])));
        }
        $seats = $this->currentSeats($bookingId, $bookingSeatId);
        if ($seats === []) {
            throw new WorkflowException('No seat of this booking is allotted for today.');
        }
        $done = $this->db->transaction(function (Database $db) use ($seats, $bookingId, $actor, $method): array {
            $done = [];
            foreach ($seats as $s) {
                if ($s['open_checkin_id'] !== null) {
                    continue;
                }
                $db->insert('checkins', [
                    'booking_id' => $bookingId,
                    'booking_seat_id' => (int) $s['id'],
                    'seat_id' => (int) $s['seat_id'],
                    'seat_key' => (int) $s['seat_key'],
                    'checked_in_at' => $this->clock->sql(),
                    'method' => $method,
                    'handled_by' => $actor->staffId(),
                ]);
                $done[] = (string) $s['code'];
            }
            if ($done !== []) {
                $this->audit->record('checkin.in', 'booking', $bookingId, null, ['seats' => $done, 'method' => $method], null, $actor->type, $actor->id);
            }
            return $done;
        });
        if ($done === [] && $bookingSeatId !== null) {
            throw new WorkflowException('That seat is already checked in.');
        }
        $activated = false;
        if ($done !== [] && $status === BookingStatus::Confirmed) {
            $this->workflow->activate($bookingId, $actor, 'First check-in');
            $activated = true;
        }
        return ['checked_in' => $done, 'activated' => $activated];
    }

    /**
     * @return array{checked_out: list<string>, completed: bool}
     */
    public function checkOut(int $bookingId, ?int $bookingSeatId, Actor $actor): array
    {
        if (!$actor->can('checkins.manage')) {
            throw new WorkflowException('Your role cannot check visitors out.', 'permission');
        }
        $open = $this->db->select(
            'SELECT ci.id, s.code FROM checkins ci JOIN seats s ON s.id = ci.seat_id WHERE ci.booking_id = ? AND ci.checked_out_at IS NULL' . ($bookingSeatId !== null ? ' AND ci.booking_seat_id = ?' : ''),
            $bookingSeatId !== null ? [$bookingId, $bookingSeatId] : [$bookingId],
        );
        if ($open === []) {
            throw new WorkflowException('Nobody is checked in on this booking' . ($bookingSeatId !== null ? ' seat.' : '.'));
        }
        $this->db->execute(
            'UPDATE checkins SET checked_out_at = ?, checked_out_by = ? WHERE id IN (' . implode(',', array_fill(0, count($open), '?')) . ')',
            [$this->clock->sql(), $actor->staffId(), ...array_map(static fn (array $r) => (int) $r['id'], $open)],
        );
        $codes = array_map(static fn (array $r) => (string) $r['code'], $open);
        $this->audit->record('checkin.out', 'booking', $bookingId, null, ['seats' => $codes], null, $actor->type, $actor->id);

        $booking = $this->workflow->find($bookingId);
        $completed = false;
        $stillIn = (int) $this->db->scalar('SELECT COUNT(*) FROM checkins WHERE booking_id = ? AND checked_out_at IS NULL', [$bookingId]);
        if ($stillIn === 0 && $booking['status'] === BookingStatus::Active->value && (string) $booking['end_date'] <= $this->clock->today()) {
            $this->workflow->complete($bookingId, $actor, 'Checked out on the last day');
            $completed = true;
        }
        return ['checked_out' => $codes, 'completed' => $completed];
    }

    /**
     * Check in / out by the seat clicked on the map (a live seat row id of the published layout).
     *
     * @return array{booking_no: string, action: string, seats: list<string>, activated?: bool, completed?: bool}
     */
    public function toggleBySeat(int $seatId, string $action, Actor $actor): array
    {
        $today = $this->clock->today();
        $st = [BookingStatus::Confirmed->value, BookingStatus::Active->value];
        $row = $this->db->first(
            'SELECT bs.id, bs.booking_id, b.booking_no FROM booking_seats bs JOIN bookings b ON b.id = bs.booking_id
             WHERE bs.seat_key = (SELECT seat_key FROM seats WHERE id = ?) AND bs.released_at IS NULL AND b.status IN (?, ?)
               AND bs.start_date <= ? AND bs.end_date >= ? ORDER BY bs.id DESC LIMIT 1',
            [$seatId, ...$st, $today, $today],
        ) ?? throw new WorkflowException('No confirmed booking occupies this seat today.');
        if ($action === 'out') {
            $r = $this->checkOut((int) $row['booking_id'], (int) $row['id'], $actor);
            return ['booking_no' => (string) $row['booking_no'], 'action' => 'out', 'seats' => $r['checked_out'], 'completed' => $r['completed']];
        }
        $r = $this->checkIn((int) $row['booking_id'], (int) $row['id'], $actor, 'map');
        return ['booking_no' => (string) $row['booking_no'], 'action' => 'in', 'seats' => $r['checked_in'], 'activated' => $r['activated']];
    }

    /**
     * Current (unreleased, covering today) seats of a booking with their open check-in.
     *
     * @return list<array<string, mixed>>
     */
    public function currentSeats(int $bookingId, ?int $bookingSeatId = null): array
    {
        $today = $this->clock->today();
        $sql = 'SELECT bs.id, bs.seat_id, bs.seat_key, s.code, s.label, s.kind,
                       (SELECT ci.id FROM checkins ci WHERE ci.booking_seat_id = bs.id AND ci.checked_out_at IS NULL ORDER BY ci.id DESC LIMIT 1) AS open_checkin_id,
                       (SELECT ci.checked_in_at FROM checkins ci WHERE ci.booking_seat_id = bs.id AND ci.checked_out_at IS NULL ORDER BY ci.id DESC LIMIT 1) AS checked_in_at
                FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id
                WHERE bs.booking_id = ? AND bs.released_at IS NULL AND bs.start_date <= ? AND bs.end_date >= ?';
        $bind = [$bookingId, $today, $today];
        if ($bookingSeatId !== null) {
            $sql .= ' AND bs.id = ?';
            $bind[] = $bookingSeatId;
        }
        return $this->db->select($sql . ' ORDER BY s.code', $bind);
    }

    /**
     * Open check-ins keyed by seat_key (explorer popover, occupancy maps).
     *
     * @return array<int, array{booking_no: string, checked_in_at: string}>
     */
    public function bySeatKey(): array
    {
        $out = [];
        foreach ($this->db->select(
            'SELECT ci.seat_key, ci.checked_in_at, b.booking_no FROM checkins ci JOIN bookings b ON b.id = ci.booking_id WHERE ci.checked_out_at IS NULL',
        ) as $r) {
            $out[(int) $r['seat_key']] = ['booking_no' => (string) $r['booking_no'], 'checked_in_at' => (string) $r['checked_in_at']];
        }
        return $out;
    }

    /**
     * A visitor's bookings relevant today (QR check-in page): approved/confirmed/active bookings covering today,
     * with their seats and check-in state.
     *
     * @return list<array<string, mixed>>
     */
    public function forVisitor(int $customerId): array
    {
        $today = $this->clock->today();
        $rows = $this->db->select(
            "SELECT b.*, sc.name AS category_name FROM bookings b JOIN seat_categories sc ON sc.id = b.seat_category_id
             WHERE b.customer_id = ? AND b.status IN ('approved', 'confirmed', 'active') AND b.start_date <= ? AND b.end_date >= ?
             ORDER BY b.start_date, b.id",
            [$customerId, $today, $today],
        );
        foreach ($rows as &$r) {
            $r['seats'] = $this->currentSeats((int) $r['id']);
        }
        unset($r);
        return $rows;
    }

    public function checkedInCount(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM checkins WHERE checked_out_at IS NULL');
    }

    /**
     * Check-in history of a booking.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $bookingId): array
    {
        return $this->db->select(
            'SELECT ci.*, s.code, h.name AS handled_by_name, o.name AS checked_out_by_name FROM checkins ci JOIN seats s ON s.id = ci.seat_id
             LEFT JOIN staff_users h ON h.id = ci.handled_by LEFT JOIN staff_users o ON o.id = ci.checked_out_by
             WHERE ci.booking_id = ? ORDER BY ci.checked_in_at DESC, ci.id DESC LIMIT 50',
            [$bookingId],
        );
    }
}
