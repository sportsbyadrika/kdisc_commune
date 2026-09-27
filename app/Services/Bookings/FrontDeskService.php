<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Core\Database;
use App\Services\Payments\PaymentLedger;
use App\Services\Space\BookingPeriod;
use App\Services\Space\FloorMapService;
use App\Services\Space\MiniMapPresenter;
use App\Support\Clock;

/**
 * Front-desk dashboard data (spec 6.2 / 6.3) for receptionists and Centre Managers: today's arrivals and
 * departures, who is checked in, requests / payments waiting, renewals due, top outstanding dues and a live
 * occupancy mini-map per floor.
 */
final class FrontDeskService
{
    private const LIST = "SELECT b.id, b.booking_no, b.status, b.start_date, b.end_date, b.start_time, b.end_time, b.grand_total, b.payment_due_by,
                                 c.name AS customer_name, c.unique_id, sc.name AS category_name,
                                 (SELECT GROUP_CONCAT(s.code ORDER BY s.code SEPARATOR ', ') FROM booking_seats bs JOIN seats s ON s.id = bs.seat_id WHERE bs.booking_id = b.id AND bs.transferred_to_id IS NULL) AS seat_codes,
                                 (SELECT COUNT(*) FROM checkins ci WHERE ci.booking_id = b.id AND ci.checked_out_at IS NULL) AS checked_in
                          FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN seat_categories sc ON sc.id = b.seat_category_id";

    public function __construct(
        private readonly Database $db,
        private readonly PaymentLedger $ledger,
        private readonly FloorMapService $maps,
        private readonly MiniMapPresenter $mini,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $today = $this->clock->today();
        $arrivals = $this->db->select(self::LIST . " WHERE b.start_date = ? AND b.status IN ('approved', 'confirmed', 'active') ORDER BY b.start_time IS NULL, b.start_time, b.id", [$today]);
        $expected = $this->db->select(self::LIST . " WHERE b.start_date < ? AND b.end_date >= ? AND b.status IN ('confirmed', 'active') AND b.start_time IS NULL
                                                    AND NOT EXISTS (SELECT 1 FROM checkins ci WHERE ci.booking_id = b.id AND ci.checked_out_at IS NULL) ORDER BY b.start_date LIMIT 12", [$today, $today]);
        $departures = $this->db->select(self::LIST . " WHERE b.end_date = ? AND b.status IN ('confirmed', 'active') ORDER BY b.id", [$today]);
        $requests = $this->db->select(self::LIST . " WHERE b.status = 'requested' ORDER BY b.created_at LIMIT 6");
        $awaiting = $this->db->select(self::LIST . " WHERE b.status = 'approved' ORDER BY b.payment_due_by IS NULL, b.payment_due_by, b.id LIMIT 6");
        $renewals = [];
        foreach ([7, 15, 30] as $d) {
            $renewals[$d] = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM bookings b WHERE b.status IN ('confirmed', 'active') AND b.start_time IS NULL AND b.end_date >= ? AND b.end_date <= DATE_ADD(?, INTERVAL ? DAY)
                   AND NOT EXISTS (SELECT 1 FROM bookings r WHERE r.renewed_from_id = b.id AND r.status NOT IN ('cancelled', 'rejected'))",
                [$today, $today, $d],
            );
        }
        return [
            'today' => $today,
            'arrivals' => $arrivals,
            'expected' => $expected,
            'departures' => $departures,
            'requests' => $requests,
            'awaiting' => $awaiting,
            'counts' => [
                'arrivals' => count($arrivals),
                'departures' => count($departures),
                'checked_in' => (int) $this->db->scalar('SELECT COUNT(*) FROM checkins WHERE checked_out_at IS NULL'),
                'requests' => (int) $this->db->scalar("SELECT COUNT(*) FROM bookings WHERE status = 'requested'"),
                'awaiting' => (int) $this->db->scalar("SELECT COUNT(*) FROM bookings WHERE status = 'approved'"),
                'active' => (int) $this->db->scalar("SELECT COUNT(*) FROM bookings WHERE status = 'active'"),
                'kyc_pending' => (int) $this->db->scalar("SELECT COUNT(*) FROM customers WHERE kyc_status = 'pending'"),
            ],
            'renewals' => $renewals,
            'dues' => $this->ledger->topOutstanding(6),
        ];
    }

    /**
     * Live occupancy mini-maps (today), one per floor.
     *
     * @return list<array<string, mixed>>
     */
    public function occupancyMaps(): array
    {
        $period = BookingPeriod::days($this->clock->today(), $this->clock->today());
        $out = [];
        foreach ($this->maps->floors() as $f) {
            $floor = $this->maps->findFloor((string) $f['slug']);
            if ($floor !== null) {
                $out[] = $this->mini->floor($floor, $period, [], ['mode' => 'occupancy']);
            }
        }
        return $out;
    }
}
