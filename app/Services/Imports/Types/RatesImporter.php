<?php

declare(strict_types=1);

namespace App\Services\Imports\Types;

use App\Core\Database;
use App\Enums\BillingUnit;
use App\Enums\RateScope;
use App\Enums\SeatCategory;
use App\Services\Imports\ImportColumn;
use App\Services\Imports\ImportContext;
use App\Services\Imports\Importer;
use App\Services\Layout\LayoutException;
use App\Services\Pricing\RateService;

/**
 * Effective-dated rates in bulk (category base rates, zone or seat overrides) through RateService::set() — the same
 * rules as the Designer's Rates tab: amounts are never edited (a new row from a date ≥ today closes the previous
 * one), the unit must be one the space type is billed in, a rate that already priced bookings cannot be replaced.
 * Targets: category code (FLEXI…), zone code of the current published layout, or seat code — stored by stable key.
 */
final class RatesImporter extends Importer
{
    public function __construct(private readonly Database $db, private readonly RateService $rates)
    {
    }

    public function key(): string
    {
        return 'rates';
    }

    public function label(): string
    {
        return 'Rates';
    }

    public function description(): string
    {
        return 'Space-type base rates and zone / seat price overrides, effective from a date.';
    }

    public function icon(): string
    {
        return 'badge-indian-rupee';
    }

    public function columns(): array
    {
        $cats = array_map(static fn (SeatCategory $c) => $c->value, SeatCategory::cases());
        return [
            new ImportColumn('scope', 'Applies to', true, 'Space type', '"Space type" (base rate), "Zone" or "Seat" (override).', ['Space type', 'Zone', 'Seat'], width: 12),
            new ImportColumn('target', 'Code', true, 'FLEXI', 'Space type: ' . implode(' / ', $cats) . '. Zone: zone code of the current layout (e.g. OPEN). Seat: seat code (e.g. G-DD-12, G-CB-B).', type: 'id', width: 14),
            new ImportColumn('unit', 'Per', true, 'Day', 'Day, Month or Hour — must be how the space type is billed.', array_values(BillingUnit::options()), width: 10),
            new ImportColumn('amount', 'Amount (₹)', true, '550', 'Before GST.', type: 'number', width: 12),
            new ImportColumn('gst_rate', 'GST rate (%)', false, '18', 'Default: the GST setting (18).', type: 'number', width: 10),
            new ImportColumn('effective_from', 'Effective from', true, '01-11-2026', 'dd-mm-yyyy — today or later.', type: 'date', width: 13),
            new ImportColumn('note', 'Note', false, 'Festival season revision', '', width: 26),
        ];
    }

    /** @return list<string> */
    public function instructions(): array
    {
        return [
            'A new rate closes the previous one of the same target and unit on the day before; rows starting later that never priced a booking are replaced.',
            'Zone and seat overrides are stored against the stable zone / seat identity, so they survive layout publishes.',
        ];
    }

    public function validate(array $row, ImportContext $ctx): array
    {
        $errors = [];
        $scope = match (mb_strtolower(trim($row['scope'] ?? ''))) {
            'space type', 'category', 'type' => RateScope::Category,
            'zone' => RateScope::Zone,
            'seat', 'cabin', 'room' => RateScope::Seat,
            default => null,
        };
        $target = strtoupper(trim($row['target'] ?? ''));
        $unit = self::option($row['unit'] ?? '', BillingUnit::options());
        $scopeId = null;
        $category = null;
        if ($scope === null) {
            $errors['scope'] = 'Choose Space type, Zone or Seat.';
        } elseif ($target === '') {
            $errors['target'] = 'Enter the code.';
        } else {
            $r = match ($scope) {
                RateScope::Category => $this->db->first('SELECT id AS scope_id, code AS category FROM seat_categories WHERE code = ?', [$target]),
                RateScope::Zone => $this->db->first(
                    "SELECT z.zone_key AS scope_id, sc.code AS category FROM zones z JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published'
                     JOIN seat_categories sc ON sc.id = z.seat_category_id WHERE z.code = ? LIMIT 1",
                    [$target],
                ),
                RateScope::Seat => $this->db->first(
                    "SELECT s.seat_key AS scope_id, sc.code AS category, s.parent_id FROM seats s JOIN zones z ON z.id = s.zone_id
                     JOIN layout_versions lv ON lv.id = z.layout_version_id AND lv.status = 'published' JOIN seat_categories sc ON sc.id = z.seat_category_id WHERE s.code = ? LIMIT 1",
                    [$target],
                ),
            };
            if ($r === null) {
                $errors['target'] = sprintf('No %s "%s" on the current layout.', $scope === RateScope::Category ? 'space type' : $scope->value, $target);
            } elseif (($r['parent_id'] ?? null) !== null) {
                $errors['target'] = 'That is a chair of a cabin / room — price the whole unit instead.';
            } else {
                $scopeId = (int) $r['scope_id'];
                $category = SeatCategory::tryFrom((string) $r['category']);
            }
        }
        if ($unit === null) {
            $errors['unit'] = 'Choose Day, Month or Hour.';
        } elseif ($category !== null && !in_array(BillingUnit::from($unit), $category->billingUnits(), true)) {
            $errors['unit'] = sprintf('%s is not billed per %s.', $category->shortLabel(), $unit);
        }
        $amount = str_replace([',', '₹', ' '], '', trim($row['amount'] ?? ''));
        if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 10_000_000) {
            $errors['amount'] = 'Enter an amount greater than 0.';
        }
        $gst = trim($row['gst_rate'] ?? '') !== '' ? trim($row['gst_rate']) : (string) setting('gst_rate', 18);
        if (!is_numeric($gst) || (float) $gst < 0 || (float) $gst > 28) {
            $errors['gst_rate'] = 'GST rate must be between 0 and 28 %.';
        }
        $from = trim($row['effective_from'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $errors['effective_from'] = 'Enter the date as dd-mm-yyyy.';
        } elseif ($from < $ctx->today) {
            $errors['effective_from'] = 'Rates start today or later — past rates are never changed.';
        }
        if ($scope !== null && $scopeId !== null && $unit !== null) {
            $earlier = $ctx->claim('rate', $scope->value . ':' . $scopeId . ':' . $unit . ':' . $from, (int) ($row['_row'] ?? 0));
            if ($earlier !== null) {
                $errors['target'] ??= "Same target, unit and date as row {$earlier} of this file.";
            }
        }
        $note = $category !== null && is_numeric($amount) ? sprintf('%s · %s per %s from %s', $category->shortLabel(), money((float) $amount), $unit, $from !== '' && !isset($errors['effective_from']) ? format_date($from) : '—') : '';
        return [
            'data' => ['scope' => $scope?->value, 'scope_id' => $scopeId, 'unit' => $unit, 'amount' => (float) $amount, 'gst_rate' => (float) $gst, 'from' => $from, 'note' => trim($row['note'] ?? ''), 'target' => $target],
            'errors' => $errors,
            'note' => $note,
        ];
    }

    public function import(array $data, ImportContext $ctx): string
    {
        try {
            $this->rates->set(RateScope::from((string) $data['scope']), [(int) $data['scope_id']], BillingUnit::from((string) $data['unit']), (float) $data['amount'], (float) $data['gst_rate'], (string) $data['from'], $ctx->staffId(), $data['note'] !== '' ? (string) $data['note'] : 'Bulk import #' . $ctx->batchId);
        } catch (LayoutException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        return sprintf('%s %s rate from %s', $data['target'], $data['unit'], format_date((string) $data['from']));
    }
}
