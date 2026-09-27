<?php

declare(strict_types=1);

namespace App\Services\Imports\Types;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Validator;
use App\Enums\FacilityKind;
use App\Enums\FacilityUnit;
use App\Services\Imports\ImportColumn;
use App\Services\Imports\ImportContext;
use App\Services\Imports\Importer;
use App\Services\Layout\FacilityService;

/**
 * Facility master in bulk: a row whose Code matches an existing facility UPDATES it, otherwise a new facility is
 * created — both through FacilityService (its rules(), code generation, audit log, "booked facilities keep their
 * code" rule).
 */
final class FacilitiesImporter extends Importer
{
    public function __construct(private readonly Database $db, private readonly FacilityService $facilities)
    {
    }

    public function key(): string
    {
        return 'facilities';
    }

    public function label(): string
    {
        return 'Facilities';
    }

    public function description(): string
    {
        return 'Included amenities, paid add-ons and landmarks — create new or update by code.';
    }

    public function icon(): string
    {
        return 'sparkles';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('name', 'Name', true, 'Standing desk', '', width: 22),
            new ImportColumn('code', 'Code', false, 'STANDING_DESK', 'Letters, digits, _ (2–30). An existing code updates that facility; empty = new facility with a generated code.', type: 'id', width: 18),
            new ImportColumn('kind', 'Kind', true, 'Add-on', 'Included (every seat), Add-on (chargeable) or Landmark (map marker).', array_values(FacilityKind::options()), width: 12),
            new ImportColumn('unit', 'Billing unit', false, 'Per month', 'Add-ons only.', array_values(FacilityUnit::options()), width: 12),
            new ImportColumn('price', 'Price (₹)', false, '400', 'Add-ons only — price per billing unit, before GST.', type: 'number', width: 10),
            new ImportColumn('gst_rate', 'GST rate (%)', true, '18', '0–28.', type: 'number', width: 10),
            new ImportColumn('stock_qty', 'Stock', false, '6', 'Add-ons only — how many can be booked at once (empty = unlimited).', type: 'number', width: 8),
            new ImportColumn('icon', 'Icon', true, 'monitor', 'A Lucide icon name from the facility master (see the Icons list).', FacilityService::icons(), width: 16),
            new ImportColumn('description', 'Description', false, 'Height-adjustable desk next to your seat', '', width: 32),
            new ImportColumn('is_active', 'Active', false, 'Yes', 'Yes / No (default Yes).', ['Yes', 'No'], width: 8),
        ];
    }

    public function validate(array $row, ImportContext $ctx): array
    {
        $errors = [];
        $code = strtoupper(trim($row['code'] ?? ''));
        $existing = $code !== '' ? $this->db->first('SELECT * FROM facilities WHERE code = ?', [$code]) : null;
        $data = [
            'name' => trim($row['name'] ?? ''),
            'code' => $code,
            'kind' => self::option($row['kind'] ?? '', FacilityKind::options()) ?? trim($row['kind'] ?? ''),
            'unit' => self::option($row['unit'] ?? '', FacilityUnit::options()) ?? trim($row['unit'] ?? ''),
            'price' => trim($row['price'] ?? ''),
            'gst_rate' => trim($row['gst_rate'] ?? ''),
            'stock_qty' => trim($row['stock_qty'] ?? ''),
            'icon' => trim($row['icon'] ?? ''),
            'description' => trim($row['description'] ?? ''),
            'is_active' => ($row['is_active'] ?? '') === '' || self::yes($row['is_active']) ? '1' : '0',
        ];
        if ($data['kind'] !== FacilityKind::Addon->value) {
            $data['unit'] = '';
        }
        try {
            Validator::make($data, $this->facilities->rules($existing !== null ? (int) $existing['id'] : null), [], ['gst_rate' => 'GST rate', 'stock_qty' => 'stock'])->validate();
            if ($data['kind'] === FacilityKind::Addon->value && (float) $data['price'] <= 0) {
                $errors['price'] = 'An add-on needs a price greater than 0.';
            }
        } catch (ValidationException $e) {
            $errors = self::errorsFrom($e);
        }
        $earlier = $ctx->claim('facility.code', $code, (int) ($row['_row'] ?? 0)) ?? $ctx->claim('facility.name', mb_strtolower($data['name']), (int) ($row['_row'] ?? 0));
        if ($earlier !== null) {
            $errors['name'] ??= "Duplicate of row {$earlier} of this file.";
        }
        if ($existing === null && $data['name'] !== '' && $this->db->scalar('SELECT id FROM facilities WHERE LOWER(name) = LOWER(?)', [$data['name']]) !== null) {
            $errors['name'] ??= 'A facility with this name exists — put its code in the Code column to update it.';
        }
        return ['data' => $data + ['id' => $existing['id'] ?? null], 'errors' => $errors, 'note' => $existing !== null ? 'Update ' . $existing['code'] : 'Create new'];
    }

    public function import(array $data, ImportContext $ctx): string
    {
        $id = $data['id'] ?? null;
        unset($data['id']);
        if ($id !== null) {
            $this->facilities->update((int) $id, $data);
            return 'Updated ' . $data['code'];
        }
        $new = $this->facilities->create($data);
        return 'Created ' . (string) $this->db->scalar('SELECT code FROM facilities WHERE id = ?', [$new]);
    }
}
