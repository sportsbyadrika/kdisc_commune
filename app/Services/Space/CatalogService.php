<?php

declare(strict_types=1);

namespace App\Services\Space;

use App\Core\Database;
use App\Enums\SeatCategory;
use App\Services\Pricing\PriceResolver;

/**
 * Read-only queries describing the centre's inventory for public pages and dashboards.
 * Only PUBLISHED layout versions are counted.
 *
 * "Bookable units": a cabin or the conference room counts once (whole unit);
 * a flexi/dedicated seat counts once per chair.
 */
final class CatalogService
{
    public function __construct(private readonly Database $db, private readonly PriceResolver $prices)
    {
    }

    /**
     * Space types for cards: category row + rates + unit/seat counts.
     *
     * @return list<array<string, mixed>>
     */
    public function spaceTypes(): array
    {
        $card = $this->prices->categoryRateCard();
        $counts = [];
        foreach ($this->db->select(
            "SELECT z.seat_category_id AS cid,
                    SUM(CASE WHEN s.parent_id IS NULL THEN 1 ELSE 0 END) AS units,
                    SUM(CASE WHEN s.kind = 'seat' THEN 1 ELSE 0 END) AS chairs
             FROM seats s
             JOIN zones z ON z.id = s.zone_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
             WHERE z.seat_category_id IS NOT NULL
             GROUP BY z.seat_category_id",
        ) as $row) {
            $counts[(int) $row['cid']] = ['units' => (int) $row['units'], 'chairs' => (int) $row['chairs']];
        }
        $out = [];
        foreach ($this->db->select('SELECT * FROM seat_categories ORDER BY sort_order') as $cat) {
            $id = (int) $cat['id'];
            $enum = SeatCategory::tryFrom((string) $cat['code']);
            $rates = $card[$id] ?? [];
            // headline price: month if available, else first unit
            $headlineUnit = isset($rates['month']) ? 'month' : (array_key_first($rates) ?? null);
            $out[] = $cat + [
                'enum' => $enum,
                'rates' => $rates,
                'headline_unit' => $headlineUnit,
                'headline_amount' => $headlineUnit !== null ? $rates[$headlineUnit]['amount'] : null,
                'units' => $counts[$id]['units'] ?? 0,
                'chairs' => $counts[$id]['chairs'] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * Facilities grouped by kind (included / addon / landmark).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function facilitiesByKind(): array
    {
        $grouped = ['included' => [], 'addon' => [], 'landmark' => []];
        foreach ($this->db->select('SELECT * FROM facilities WHERE is_active = 1 ORDER BY sort_order') as $f) {
            $grouped[(string) $f['kind']][] = $f;
        }
        return $grouped;
    }

    /**
     * Floors of the (first) building with bookable-seat counts per category.
     *
     * @return list<array<string, mixed>>
     */
    public function floors(): array
    {
        $floors = $this->db->select('SELECT * FROM floors ORDER BY sort_order, level');
        $stats = [];
        foreach ($this->db->select(
            "SELECT lv.floor_id, sc.code, COUNT(*) AS chairs
             FROM seats s
             JOIN zones z ON z.id = s.zone_id
             JOIN seat_categories sc ON sc.id = z.seat_category_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
             WHERE s.kind = 'seat'
             GROUP BY lv.floor_id, sc.code, sc.sort_order
             ORDER BY lv.floor_id, sc.sort_order",
        ) as $row) {
            $stats[(int) $row['floor_id']][(string) $row['code']] = (int) $row['chairs'];
        }
        foreach ($floors as &$floor) {
            $floor['by_category'] = $stats[(int) $floor['id']] ?? [];
            $floor['total_seats'] = array_sum($floor['by_category']);
            $floor['hotspot'] = json_decode((string) ($floor['hotspot_polygon'] ?? '[]'), true) ?: [];
        }
        return $floors;
    }

    /** @return array<string, mixed>|null */
    public function building(): ?array
    {
        return $this->db->first('SELECT b.*, c.name AS centre_name, c.code AS centre_code FROM buildings b JOIN centres c ON c.id = b.centre_id ORDER BY b.id LIMIT 1');
    }

    /** Total chairs across published layouts. */
    public function totalSeats(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM seats s JOIN zones z ON z.id = s.zone_id
             JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
             WHERE s.kind = 'seat'",
        );
    }
}
