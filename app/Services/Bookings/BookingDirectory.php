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
                                   (SELECT GROUP_CONCAT(s.code ORDER BY s.code SEPARATOR ', ') FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id WHERE bs.booking_id = b.id AND bs.transferred_to_id IS NULL) AS seat_codes,
                                   (SELECT f.name FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id JOIN zones z ON z.id = s.zone_id
                                      JOIN layout_versions lv ON lv.id = z.layout_version_id JOIN floors f ON f.id = lv.floor_id WHERE bs.booking_id = b.id AND bs.transferred_to_id IS NULL LIMIT 1) AS floor_name
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

    /** Console tabs (spec 6.2/6.3): key => [label, icon]. */
    public const TABS = [
        'requests' => ['Requests', 'inbox'],
        'payment' => ['Awaiting payment', 'wallet'],
        'upcoming' => ['Upcoming', 'calendar-clock'],
        'active' => ['Active', 'armchair'],
        'renewals' => ['Renewals due', 'refresh-cw'],
        'completed' => ['Completed', 'circle-check'],
        'cancelled' => ['Cancelled', 'circle-x'],
        'all' => ['All', 'list'],
    ];

    /**
     * SQL condition for a console tab. Renewals = confirmed/active ending within $within days without a
     * follow-on booking.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function tabWhere(string $tab, string $today, int $within): array
    {
        return match ($tab) {
            'requests' => ["b.status = 'requested'", []],
            'payment' => ["b.status = 'approved'", []],
            'upcoming' => ["b.status = 'confirmed'", []],
            'active' => ["b.status = 'active'", []],
            'renewals' => [
                "b.status IN ('confirmed', 'active') AND b.start_time IS NULL AND b.end_date >= ? AND b.end_date <= DATE_ADD(?, INTERVAL ? DAY)
                 AND NOT EXISTS (SELECT 1 FROM bookings r WHERE r.renewed_from_id = b.id AND r.status NOT IN ('cancelled', 'rejected'))",
                [$today, $today, $within],
            ],
            'completed' => ["b.status = 'completed'", []],
            'cancelled' => ["b.status IN ('cancelled', 'rejected')", []],
            default => ['1 = 1', []],
        };
    }

    /**
     * Staff bookings console: one tab + filters, paginated.
     *
     * @param array{category?: string, floor?: string, from?: string, to?: string, source?: string, q?: string, within?: int|string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function console(string $tab, array $filters, string $today, int $page = 1, int $perPage = 20): array
    {
        [$where, $bind] = $this->consoleWhere($tab, $filters, $today);
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM bookings b JOIN seat_categories sc ON sc.id = b.seat_category_id JOIN customers c ON c.id = b.customer_id WHERE ' . $where, $bind);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $order = match ($tab) {
            'requests', 'payment' => 'b.created_at ASC, b.id ASC',
            'upcoming' => 'b.start_date ASC, b.id ASC',
            'active', 'renewals' => 'b.end_date ASC, b.id ASC',
            default => 'b.created_at DESC, b.id DESC',
        };
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->select(self::SELECT . ' WHERE ' . $where . " ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}", $bind);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    /**
     * Counts per tab for the same filters.
     *
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public function tabCounts(array $filters, string $today): array
    {
        $out = [];
        foreach (array_keys(self::TABS) as $tab) {
            [$where, $bind] = $this->consoleWhere($tab, $filters, $today);
            $out[$tab] = (int) $this->db->scalar('SELECT COUNT(*) FROM bookings b JOIN seat_categories sc ON sc.id = b.seat_category_id JOIN customers c ON c.id = b.customer_id WHERE ' . $where, $bind);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function consoleWhere(string $tab, array $filters, string $today): array
    {
        $within = in_array((int) ($filters['within'] ?? 30), [7, 15, 30], true) ? (int) $filters['within'] : 30;
        [$w, $bind] = $this->tabWhere($tab, $today, $within);
        $where = [$w];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $digits = preg_replace('/\D+/', '', $q);
            $where[] = '(b.booking_no LIKE ? OR c.name LIKE ? OR c.unique_id LIKE ? OR c.email LIKE ?' . ($digits !== '' && strlen((string) $digits) >= 4 ? ' OR c.mobile LIKE ?' : '') . ')';
            array_push($bind, $like, $like, $like, $like);
            if ($digits !== '' && strlen((string) $digits) >= 4) {
                $bind[] = '%' . $digits . '%';
            }
        }
        if (($filters['category'] ?? '') !== '') {
            $where[] = 'sc.code = ?';
            $bind[] = strtoupper((string) $filters['category']);
        }
        if (in_array($filters['source'] ?? '', ['online', 'reception'], true)) {
            $where[] = 'b.source = ?';
            $bind[] = $filters['source'];
        }
        if (($filters['floor'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM booking_seats fbs JOIN seats fs ON fs.id = fbs.seat_id JOIN zones fz ON fz.id = fs.zone_id
                        JOIN layout_versions flv ON flv.id = fz.layout_version_id JOIN floors ff ON ff.id = flv.floor_id
                        WHERE fbs.booking_id = b.id AND ff.slug = ?)';
            $bind[] = $filters['floor'];
        }
        $date = static fn (mixed $d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
        if ($date($filters['from'] ?? null)) {
            $where[] = 'b.end_date >= ?';
            $bind[] = $filters['from'];
        }
        if ($date($filters['to'] ?? null)) {
            $where[] = 'b.start_date <= ?';
            $bind[] = $filters['to'];
        }
        return [implode(' AND ', $where), $bind];
    }

    /**
     * Audit trail of a booking (status changes, payments, check-ins, handovers) with actor names.
     *
     * @return list<array<string, mixed>>
     */
    public function activity(int $bookingId, int $limit = 40): array
    {
        return $this->db->select(
            "SELECT a.*, COALESCE(s.name, CASE a.actor_type WHEN 'account' THEN 'Visitor' WHEN 'system' THEN 'System' ELSE '—' END) AS actor_name
             FROM audit_logs a LEFT JOIN staff_users s ON a.actor_type = 'staff' AND s.id = a.actor_id
             WHERE a.entity_type = 'booking' AND a.entity_id = ? ORDER BY a.id DESC LIMIT {$limit}",
            [$bookingId],
        );
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
            'SELECT bs.*, s.code, s.label, s.kind, s.capacity, z.name AS zone_name, f.name AS floor_name, f.slug AS floor_slug, f.id AS floor_id,
                    ts.code AS transferred_to_code
             FROM booking_seats bs
             JOIN seats s ON s.id = bs.seat_id
             JOIN zones z ON z.id = s.zone_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id
             JOIN floors f ON f.id = lv.floor_id
             LEFT JOIN booking_seats tb ON tb.id = bs.transferred_to_id
             LEFT JOIN seats ts ON ts.id = tb.seat_id
             WHERE bs.booking_id = ? ORDER BY bs.transferred_to_id IS NOT NULL, s.code, bs.id',
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
            BookingStatus::Confirmed->value => 'Payment received — seats allotted.',
            BookingStatus::Active->value => 'Checked in and working.',
            BookingStatus::Completed->value => 'Tenure completed.',
        ];
        $at = [
            BookingStatus::Requested->value => $booking['created_at'] ?? null,
            BookingStatus::Approved->value => $booking['approved_at'] ?? null,
            BookingStatus::Confirmed->value => $booking['confirmed_at'] ?? null,
            BookingStatus::Active->value => $booking['activated_at'] ?? null,
            BookingStatus::Completed->value => $booking['completed_at'] ?? null,
        ];
        $stopped = in_array($status, [BookingStatus::Cancelled, BookingStatus::Rejected], true);
        $pos = array_search($status, $flow, true);
        // how far a cancelled booking got before it stopped
        $reached = 0;
        foreach ($flow as $i => $step) {
            if ($i === 0 || !empty($at[$step->value])) {
                $reached = $i;
            }
        }
        $out = [];
        foreach ($flow as $i => $step) {
            $state = match (true) {
                $stopped => $i <= $reached ? 'done' : 'upcoming',
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
                'note' => $status === BookingStatus::Rejected ? (string) ($booking['rejected_reason'] ?? 'The request was not approved.') : (!empty($booking['cancel_reason']) ? (string) $booking['cancel_reason'] : 'The booking was cancelled.'),
            ];
        }
        return $out;
    }
}
