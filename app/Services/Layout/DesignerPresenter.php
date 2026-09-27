<?php

declare(strict_types=1);

namespace App\Services\Layout;

use App\Core\Database;
use App\Enums\RateScope;
use App\Enums\SeatCategory;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\RateService;
use App\Support\Clock;

/**
 * View data / JS config for the Layout & Pricing Designer (resources/views/staff/layout/designer.php +
 * resources/js/designer.js), and the pricing payload of a layout version for the inspector.
 */
final class DesignerPresenter
{
    public function __construct(
        private readonly Database $db,
        private readonly LayoutDraftService $drafts,
        private readonly PriceResolver $prices,
        private readonly RateService $rates,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT * FROM seat_categories ORDER BY sort_order') as $c) {
            $cat = SeatCategory::tryFrom((string) $c['code']);
            if ($cat === null) {
                continue;
            }
            $out[] = [
                'id' => (int) $c['id'], 'code' => $cat->value, 'name' => (string) $c['name'], 'short' => $cat->shortLabel(),
                'colour' => (string) ($c['colour'] ?? '#64748b'), 'segment' => $cat->codeSegment(), 'icon' => $cat->icon(),
                'units' => array_map(static fn ($u) => $u->value, $cat->billingUnits()),
                'whole_unit' => $cat->wholeUnitOnly(), 'hourly' => $cat->hourlyOnly(),
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function facilities(): array
    {
        return array_map(static fn (array $f) => [
            'id' => (int) $f['id'], 'code' => (string) $f['code'], 'name' => (string) $f['name'], 'emoji' => $f['emoji'],
            'icon' => $f['icon'] ?: 'info', 'kind' => (string) $f['kind'], 'unit' => $f['unit'], 'price' => (float) $f['price'],
            'active' => (bool) $f['is_active'],
        ], $this->db->select('SELECT * FROM facilities ORDER BY is_active DESC, sort_order, name'));
    }

    /**
     * Effective prices for the inspector: every unit's resolved rate (seat -> zone -> category) and where it
     * comes from, the zone overrides and the category base rates, on $date.
     *
     * @return array<string, mixed>
     */
    public function pricing(int $versionId, ?string $date = null): array
    {
        $date ??= $this->clock->today();
        $units = $this->db->select(
            'SELECT s.id, s.seat_key, z.id AS zone_id, z.zone_key FROM seats s JOIN zones z ON z.id = s.zone_id
             WHERE z.layout_version_id = ? AND s.parent_id IS NULL',
            [$versionId],
        );
        $resolved = $this->prices->forSeats(array_map(static fn (array $u) => (int) $u['id'], $units), $date);
        $seatRows = $this->rates->current(RateScope::Seat, array_map(static fn (array $u) => (int) $u['seat_key'], $units), $date);
        $zones = $this->db->select('SELECT id, zone_key FROM zones WHERE layout_version_id = ?', [$versionId]);
        $zoneRows = $this->rates->current(RateScope::Zone, array_map(static fn (array $z) => (int) $z['zone_key'], $zones), $date);
        $fmt = static fn (array $r): array => [
            'id' => (int) $r['id'], 'amount' => (float) $r['amount'], 'gst_rate' => (float) $r['gst_rate'],
            'from' => (string) $r['effective_from'], 'to' => $r['effective_to'],
        ];

        $seats = [];
        foreach ($units as $u) {
            $row = [];
            foreach ($resolved[(int) $u['id']] ?? [] as $unit => $r) {
                $row[$unit] = ['amount' => $r['amount'], 'gst_rate' => $r['gst_rate'], 'source' => $r['scope'], 'rate_id' => $r['rate_id']];
            }
            $seats[(int) $u['id']] = [
                'resolved' => (object) $row,
                'override' => (object) array_map($fmt, $seatRows[(int) $u['seat_key']] ?? []),
            ];
        }
        $zoneOut = [];
        foreach ($zones as $z) {
            $zoneOut[(int) $z['id']] = (object) array_map($fmt, $zoneRows[(int) $z['zone_key']] ?? []);
        }
        $cats = [];
        foreach ($this->db->select(
            "SELECT * FROM rates WHERE scope = 'category' AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from, id",
            [$date, $date],
        ) as $r) {
            $cats[(int) $r['scope_id']][(string) $r['unit']] = $fmt($r);
        }
        return ['date' => $date, 'seats' => (object) $seats, 'zones' => (object) $zoneOut, 'categories' => (object) array_map(static fn ($x) => (object) $x, $cats)];
    }

    /**
     * Full client config for the designer page.
     *
     * @param array<string, mixed> $floor
     * @param array<string, mixed> $urls
     * @return array<string, mixed>
     */
    public function config(array $floor, array $urls, bool $canPrice): array
    {
        $floorId = (int) $floor['id'];
        $published = $this->drafts->published($floorId);
        $draft = $this->drafts->draft($floorId);
        $current = $draft ?? $published;
        $editor = $draft !== null && $draft['updated_by'] !== null ? $this->db->scalar('SELECT name FROM staff_users WHERE id = ?', [(int) $draft['updated_by']]) : null;
        return [
            'floor' => [
                'id' => $floorId, 'slug' => (string) $floor['slug'], 'name' => (string) $floor['name'], 'code' => (string) $floor['code'],
                'image' => media((string) $floor['photo_path']), 'width' => (int) ($floor['photo_w'] ?: 1600), 'height' => (int) ($floor['photo_h'] ?: 1000),
            ],
            'published' => $published !== null ? ['id' => (int) $published['id'], 'no' => (int) $published['version_no'], 'at' => $published['published_at']] : null,
            'draft' => $draft !== null ? [
                'id' => (int) $draft['id'], 'no' => (int) $draft['version_no'], 'revision' => (int) $draft['revision'],
                'updated_at' => $draft['updated_at'], 'updated_by' => $editor,
            ] : null,
            'doc' => $current !== null ? $this->drafts->document((int) $current['id']) : ['zones' => [], 'seats' => [], 'placements' => []],
            'pricing' => $current !== null ? $this->pricing((int) $current['id']) : null,
            'categories' => $this->categories(),
            'facilities' => $this->facilities(),
            'today' => $this->clock->today(),
            'canPrice' => $canPrice,
            'gstDefault' => (float) setting('gst_rate', 18),
            'urls' => $urls,
        ];
    }
}
