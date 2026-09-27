<?php

declare(strict_types=1);

namespace App\Services\Space;

use App\Enums\SeatCategory;
use App\Services\Pricing\Duration;
use App\Services\Pricing\PriceResolver;
use App\Services\SettingsService;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Builds the view data / JS config for the Space Explorer pages (site /spaces/explore*, staff /staff/spaces),
 * so both audiences render the same component (resources/views/partials/space/explorer.php +
 * resources/js/explorer.js) from one place.
 */
final class ExplorerPresenter
{
    public function __construct(
        private readonly FloorMapService $maps,
        private readonly AvailabilityService $availability,
        private readonly SettingsService $settings,
        private readonly PriceResolver $prices,
        private readonly SeatHoldService $holds,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Normalised filters from the query string: from/to default to today + 1 month, type = category code.
     *
     * @param array<string, mixed> $query
     * @return array{from: string, to: string, type: string}
     */
    public function filters(array $query): array
    {
        $valid = static fn (mixed $d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
        $today = $this->clock->today();
        $from = $valid($query['from'] ?? null) && (string) $query['from'] >= $today ? (string) $query['from'] : $today;
        $defaultTo = Duration::addMonths(new DateTimeImmutable($from), 1)->modify('-1 day')->format('Y-m-d');
        $to = $valid($query['to'] ?? null) && (string) $query['to'] >= $from ? (string) $query['to'] : $defaultTo;
        $type = SeatCategory::tryFrom(strtoupper((string) ($query['type'] ?? ''))) !== null ? strtoupper((string) $query['type']) : '';
        return ['from' => $from, 'to' => $to, 'type' => $type];
    }

    /** @return list<array<string, mixed>> category chips with headline price */
    public function categories(?string $onDate = null): array
    {
        $card = $this->prices->categoryRateCard($onDate);
        $out = [];
        foreach (db()->select('SELECT id, code, name, short_name, colour, icon FROM seat_categories ORDER BY sort_order') as $c) {
            $cat = SeatCategory::tryFrom((string) $c['code']);
            if ($cat === null) {
                continue;
            }
            $rates = $card[(int) $c['id']] ?? [];
            $out[] = [
                'code' => $cat->value,
                'label' => $cat->label(),
                'short' => $cat->shortLabel(),
                'icon' => $cat->icon(),
                'whole_unit' => $cat->wholeUnitOnly(),
                'hourly' => $cat->hourlyOnly(),
                'multi' => $cat->multiSelect(),
                'rates' => array_map(static fn (array $r) => $r['amount'], $rates),
            ];
        }
        return $out;
    }

    /**
     * Level 1 data: floors with live free counts for the dates.
     *
     * @param array{from: string, to: string, type: string} $filters
     * @return list<array<string, mixed>>
     */
    public function buildingFloors(array $filters, ?SeatHolder $me = null): array
    {
        $summary = $this->availability->floorSummary(BookingPeriod::days($filters['from'], $filters['to']), $me);
        $out = [];
        foreach (db()->select('SELECT * FROM floors ORDER BY level DESC') as $f) {
            $s = $summary[(int) $f['id']] ?? ['chairs' => 0, 'free' => 0, 'by_category' => []];
            $out[] = [
                'id' => (int) $f['id'],
                'slug' => (string) $f['slug'],
                'name' => (string) $f['name'],
                'level' => (int) $f['level'],
                'hotspot' => json_decode((string) ($f['hotspot_polygon'] ?? '[]'), true) ?: [],
            ] + $s;
        }
        return $out;
    }

    /**
     * Client config for explorer.js (serialised into data-config).
     *
     * @param array<string, mixed> $floor
     * @param array{from: string, to: string, type: string} $filters
     * @param array<string, mixed> $extra overrides/additions (e.g. 'preview' => true for the Designer's draft preview)
     * @param ?int $versionId layout version to render (default: the published one) — the Designer previews drafts
     * @return array<string, mixed>
     */
    public function floorConfig(array $floor, array $filters, bool $staff, ?SeatHolder $holder, array $extra = [], ?int $versionId = null): array
    {
        $api = $staff ? '/staff/api/space' : '/api/space';
        $initial = $this->maps->map($floor, BookingPeriod::days($filters['from'], $filters['to']), $holder, $staff, $versionId);
        return array_replace([
            'api' => url($api),
            'floor' => (string) $floor['slug'],
            'from' => $filters['from'],
            'to' => $filters['to'],
            'type' => $filters['type'],
            'today' => $this->clock->today(),
            'staff' => $staff,
            'loggedIn' => $holder !== null,
            'pollSeconds' => max(5, (int) $this->settings->get('availability_poll_seconds', 20)),
            'holdMinutes' => max(1, (int) $this->settings->get('seat_hold_minutes', 10)),
            'maxSeats' => max(1, (int) $this->settings->get('max_seats_per_booking', 40)),
            'openHour' => (int) $this->settings->get('conference_open_hour', 8),
            'closeHour' => (int) $this->settings->get('conference_close_hour', 20),
            'categories' => $this->categories($filters['from']),
            'floorUrl' => $staff ? url('staff.explorer') . '?floor=__FLOOR__' : url('spaces.floor', ['floor' => '__FLOOR__']),
            'buildingUrl' => $staff ? null : url('spaces.explore'),
            'loginUrl' => url('portal.login'),
            'registerUrl' => url('portal.register'),
            'checkoutUrl' => $staff ? null : url('spaces.checkout.start'),
            'initial' => $initial + ['floors' => $this->maps->floors(), 'selection' => $holder !== null ? $this->holds->selectionPayload($holder) : null],
        ], $extra);
    }
}
