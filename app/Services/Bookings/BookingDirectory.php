<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Core\Database;
use App\Enums\BookingStatus;

/**
 * Read side for bookings: portal "My bookings", staff lists, detail pages and the status timeline.
 */
final class BookingDirectory
{
    private const SELECT = "SELECT b.*, sc.code AS category, sc.name AS category_name, c.name AS customer_name, c.unique_id, c.email AS customer_email,
                                   c.mobile AS customer_mobile, c.kyc_status,
                                   (SELECT GROUP_CONCAT(s.code ORDER BY s.code SEPARATOR ', ') FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id WHERE bs.booking_id = b.id) AS seat_codes,
                                   (SELECT f.name FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id JOIN zones z ON z.id = s.zone_id
                                      JOIN layout_versions lv ON lv.id = z.layout_version_id JOIN floors f ON f.id = lv.floor_id WHERE bs.booking_id = b.id LIMIT 1) AS floor_name
                            FROM bookings b
                            JOIN seat_categories sc ON sc.id = b.seat_category_id
                            JOIN customers c ON c.id = b.customer_id";

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forCustomer(int $customerId): array
    {
        return $this->db->select(self::SELECT . ' WHERE b.customer_id = ? ORDER BY b.created_at DESC, b.id DESC', [$customerId]);
    }

    /**
     * @param array{q?: string, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters = [], int $limit = 100): array
    {
        $where = ['1 = 1'];
        $bind = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(b.booking_no LIKE ? OR c.name LIKE ? OR c.unique_id LIKE ?)';
            array_push($bind, $like, $like, $like);
        }
        if (BookingStatus::tryFrom((string) ($filters['status'] ?? '')) !== null) {
            $where[] = 'b.status = ?';
            $bind[] = $filters['status'];
        }
        return $this->db->select(self::SELECT . ' WHERE ' . implode(' AND ', $where) . " ORDER BY b.created_at DESC, b.id DESC LIMIT {$limit}", $bind);
    }

    /** @return array<string, int> status => count */
    public function statusCounts(?int $customerId = null): array
    {
        $rows = $customerId === null
            ? $this->db->select('SELECT status, COUNT(*) AS n FROM bookings GROUP BY status')
            : $this->db->select('SELECT status, COUNT(*) AS n FROM bookings WHERE customer_id = ? GROUP BY status', [$customerId]);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function findByNo(string $no, ?int $customerId = null): ?array
    {
        $sql = self::SELECT . ' WHERE b.booking_no = ?';
        $bind = [$no];
        if ($customerId !== null) {
            $sql .= ' AND b.customer_id = ?';
            $bind[] = $customerId;
        }
        return $this->db->first($sql, $bind);
    }

    /** @return list<array<string, mixed>> */
    public function seats(int $bookingId): array
    {
        return $this->db->select(
            'SELECT bs.*, s.code, s.label, s.kind, s.capacity, z.name AS zone_name, f.name AS floor_name, f.slug AS floor_slug
             FROM booking_seats bs
             JOIN seats s ON s.id = bs.seat_id
             JOIN zones z ON z.id = s.zone_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id
             JOIN floors f ON f.id = lv.floor_id
             WHERE bs.booking_id = ? ORDER BY s.code',
            [$bookingId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function facilities(int $bookingId): array
    {
        return $this->db->select(
            'SELECT bf.*, f.name, f.emoji, f.icon, f.unit FROM booking_facilities bf JOIN facilities f ON f.id = bf.facility_id WHERE bf.booking_id = ? ORDER BY f.sort_order',
            [$bookingId],
        );
    }

    /**
     * Status timeline requested → approved → confirmed → active → completed (spec 6.1).
     *
     * @param array<string, mixed> $booking
     * @return list<array{status: BookingStatus, state: string, at: ?string, note: string}> state = done|current|upcoming|stopped
     */
    public static function timeline(array $booking): array
    {
        $status = BookingStatus::from((string) $booking['status']);
        $flow = [BookingStatus::Requested, BookingStatus::Approved, BookingStatus::Confirmed, BookingStatus::Active, BookingStatus::Completed];
        $notes = [
            BookingStatus::Requested->value => 'We received your request.',
            BookingStatus::Approved->value => 'The Centre Manager approved the seats.',
            BookingStatus::Confirmed->value => 'Payment logged — your seats are allotted.',
            BookingStatus::Active->value => 'Checked in and working.',
            BookingStatus::Completed->value => 'Tenure completed.',
        ];
        $at = [
            BookingStatus::Requested->value => $booking['created_at'] ?? null,
            BookingStatus::Approved->value => $booking['approved_at'] ?? null,
        ];
        $stopped = in_array($status, [BookingStatus::Cancelled, BookingStatus::Rejected], true);
        $pos = array_search($status, $flow, true);
        $out = [];
        foreach ($flow as $i => $step) {
            $state = match (true) {
                $stopped => $i === 0 ? 'done' : 'upcoming',
                $pos === false => 'upcoming',
                $i < $pos => 'done',
                $i === $pos => $step === BookingStatus::Completed ? 'done' : 'current',
                default => 'upcoming',
            };
            $out[] = ['status' => $step, 'state' => $state, 'at' => $at[$step->value] ?? null, 'note' => $notes[$step->value]];
        }
        if ($stopped) {
            $out[] = [
                'status' => $status,
                'state' => 'stopped',
                'at' => $booking['cancelled_at'] ?? $booking['updated_at'] ?? null,
                'note' => $status === BookingStatus::Rejected ? (string) ($booking['rejected_reason'] ?? 'The request was not approved.') : 'The booking was cancelled.',
            ];
        }
        return $out;
    }
}
