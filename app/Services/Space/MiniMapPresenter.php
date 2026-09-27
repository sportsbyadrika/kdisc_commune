<?php

declare(strict_types=1);

namespace App\Services\Space;

/**
 * Compact read-only seat-map configs for resources/js/frontdesk.js (Alpine `seatMiniMap`), drawn with the shared
 * space-render.js primitives: booking detail (highlight the booking's seats), handover (pick a free seat of the
 * same category) and the front-desk live occupancy map.
 *
 * Seats are matched to bookings by seat_key (`key`), never by row id, so highlights survive layout publishes.
 */
final class MiniMapPresenter
{
    public function __construct(private readonly FloorMapService $maps)
    {
    }

    /**
     * @param array<string, mixed> $floor floors row
     * @param list<int> $highlightKeys seat_keys to emphasise (the booking's seats)
     * @param array<string, mixed> $extra mode (view|pick|occupancy), pickCategory, labels…
     * @return array<string, mixed>
     */
    public function floor(array $floor, BookingPeriod $period, array $highlightKeys = [], array $extra = []): array
    {
        $map = $this->maps->map($floor, $period, null, true);
        $seats = [];
        $hl = array_flip($highlightKeys);
        $stats = ['units' => 0, 'occupied' => 0, 'free' => 0, 'checked_in' => 0];
        foreach ($map['seats'] as $s) {
            $row = array_intersect_key($s, array_flip(['id', 'key', 'code', 'label', 'kind', 'parent', 'zone', 'category', 'capacity', 'x', 'y', 'w', 'h', 'rotation', 'status']));
            $row['mine'] = isset($hl[$s['key']]);
            if (isset($s['occupant'])) {
                $row['occupant'] = array_intersect_key($s['occupant'], array_flip(['name', 'unique_id', 'booking_no', 'status', 'from', 'to', 'checked_in']));
            }
            if ($s['parent'] === null && $s['category'] !== null) {
                $stats['units']++;
                if ($s['status'] === AvailabilityService::OCCUPIED) {
                    $stats['occupied']++;
                } elseif ($s['status'] === AvailabilityService::AVAILABLE) {
                    $stats['free']++;
                }
                $stats['checked_in'] += !empty($s['occupant']['checked_in']) ? 1 : 0;
            }
            $seats[] = $row;
        }
        return array_replace([
            'floor' => $map['floor'],
            'zones' => array_values(array_map(static fn (array $z) => array_intersect_key($z, array_flip(['id', 'code', 'name', 'category', 'colour', 'rect', 'polygon'])), array_filter($map['zones'], static fn (array $z) => $z['category'] !== null))),
            'seats' => $seats,
            'period' => $map['period'],
            'stats' => $stats,
            'mode' => 'view',
        ], $extra);
    }
}
