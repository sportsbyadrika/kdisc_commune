<?php

declare(strict_types=1);

namespace App\Services\Layout;

use App\Core\Database;
use App\Enums\FacilityKind;
use App\Enums\FacilityUnit;
use App\Services\AuditLog;

/**
 * Facility master (spec 5.3): included amenities, chargeable add-ons and map landmarks.
 *
 * Rules: code unique (A-Z, 0-9, _), add-ons need a unit and a price > 0 (included/landmarks are free and
 * have no unit), stock only for add-ons (NULL = unlimited), icon must be a vendored Lucide icon.
 * A facility that was ever booked or placed on a published/archived layout is never deleted — it is
 * deactivated instead (bookings keep their snapshot price in booking_facilities).
 */
final class FacilityService
{
    public function __construct(private readonly Database $db, private readonly AuditLog $audit)
    {
    }

    /** @return list<array<string, mixed>> with usage counts */
    public function all(): array
    {
        return $this->db->select(
            "SELECT f.*,
                    (SELECT COUNT(*) FROM facility_placements fp JOIN layout_versions lv ON lv.id = fp.layout_version_id AND lv.status = 'published' WHERE fp.facility_id = f.id) AS placed,
                    (SELECT COUNT(*) FROM booking_facilities bf WHERE bf.facility_id = f.id) AS booked
             FROM facilities f ORDER BY f.is_active DESC, FIELD(f.kind, 'included', 'addon', 'landmark'), f.sort_order, f.name",
        );
    }

    /**
     * Lucide icon names available to the map (resources/icons/*.svg, see bin/vendor-js.mjs).
     *
     * @return list<string>
     */
    public static function icons(): array
    {
        $names = array_map(static fn (string $f) => basename($f, '.svg'), glob(base_path('resources/icons/*.svg')) ?: []);
        sort($names);
        return $names;
    }

    /**
     * Validation rules shared by create + update.
     *
     * @return array<string, string|list<string|\Closure>>
     */
    public function rules(?int $ignoreId = null): array
    {
        $icons = self::icons();
        return [
            'name' => 'required|string|max:100',
            'code' => ['nullable', 'regex:/^[A-Za-z0-9_]{2,30}$/', 'unique:facilities,code' . ($ignoreId !== null ? ',' . $ignoreId : '')],
            'description' => 'nullable|string|max:255',
            'emoji' => 'nullable|string|max:16',
            'icon' => ['required', static fn ($v) => in_array($v, $icons, true) ?: 'Pick an icon from the list.'],
            'kind' => ['required', FacilityKind::rule()],
            'unit' => ['required_if:kind,addon', FacilityUnit::rule()],
            'price' => 'nullable|numeric|min:0|max:1000000',
            'gst_rate' => 'required|numeric|min:0|max:28',
            'stock_qty' => 'nullable|integer|min:0|max:100000',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:999',
        ];
    }

    /**
     * @param array<string, mixed> $data validated input
     * @return array<string, mixed> row values
     */
    private function row(array $data): array
    {
        $kind = FacilityKind::from((string) $data['kind']);
        $addon = $kind === FacilityKind::Addon;
        $price = $addon ? round((float) ($data['price'] ?? 0), 2) : 0.0;
        if ($addon && $price <= 0) {
            throw new \App\Core\Exceptions\ValidationException(['price' => ['An add-on needs a price greater than 0.']]);
        }
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            $code = substr((string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper((string) $data['name'])), 0, 24);
            $code = trim($code, '_') ?: 'FAC';
            $base = $code;
            for ($i = 2; $this->db->scalar('SELECT id FROM facilities WHERE code = ?', [$code]) !== null; $i++) {
                $code = $base . '_' . $i;
            }
        }
        return [
            'code' => $code,
            'name' => trim((string) $data['name']),
            'description' => ($d = trim((string) ($data['description'] ?? ''))) !== '' ? $d : null,
            'emoji' => ($e = trim((string) ($data['emoji'] ?? ''))) !== '' ? $e : null,
            'icon' => (string) $data['icon'],
            'kind' => $kind->value,
            'unit' => $addon ? (string) $data['unit'] : null,
            'price' => $price,
            'gst_rate' => round((float) $data['gst_rate'], 2),
            'stock_qty' => $addon && ($data['stock_qty'] ?? '') !== '' && $data['stock_qty'] !== null ? (int) $data['stock_qty'] : null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $row = $this->row($data);
        $id = $this->db->insert('facilities', $row);
        $this->audit->record('facility.create', 'facility', $id, null, $row);
        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $old = $this->db->first('SELECT * FROM facilities WHERE id = ?', [$id]) ?? throw new \App\Core\Exceptions\NotFoundException();
        $row = $this->row($data + ['code' => $old['code']]);
        if ((int) $this->db->scalar('SELECT COUNT(*) FROM booking_facilities WHERE facility_id = ?', [$id]) > 0 && $row['code'] !== $old['code']) {
            throw new \App\Core\Exceptions\ValidationException(['code' => ['This facility has been booked — its code can no longer change.']]);
        }
        $this->db->update('facilities', $row, ['id' => $id]);
        $this->audit->record('facility.update', 'facility', $id, array_intersect_key($old, $row), $row);
    }

    /**
     * Delete when unused, otherwise refuse (deactivate instead).
     *
     * @return bool true = deleted
     */
    public function delete(int $id): bool
    {
        $old = $this->db->first('SELECT * FROM facilities WHERE id = ?', [$id]) ?? throw new \App\Core\Exceptions\NotFoundException();
        $booked = (int) $this->db->scalar('SELECT COUNT(*) FROM booking_facilities WHERE facility_id = ?', [$id]);
        $history = (int) $this->db->scalar("SELECT COUNT(*) FROM facility_placements fp JOIN layout_versions lv ON lv.id = fp.layout_version_id AND lv.status <> 'draft' WHERE fp.facility_id = ?", [$id]);
        if ($booked > 0 || $history > 0) {
            return false;
        }
        $this->db->execute('DELETE FROM facilities WHERE id = ?', [$id]);
        $this->audit->record('facility.delete', 'facility', $id, $old, null);
        return true;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->execute('UPDATE facilities SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
        $this->audit->record($active ? 'facility.activate' : 'facility.deactivate', 'facility', $id);
    }
}
