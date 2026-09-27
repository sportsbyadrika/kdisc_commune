<?php

declare(strict_types=1);

namespace Database\Seeds;

use App\Core\Seeder;
use App\Enums\SeatCategory;

/** Seat categories with their booking rules and default category rates effective 2026-01-01 (spec 7.1). */
final class SeatCategorySeeder extends Seeder
{
    public const EFFECTIVE_FROM = '2026-01-01';

    public function run(): void
    {
        $meta = [
            SeatCategory::Flexi->value => [
                'description' => 'Hot desks in the bright open area. Walk in, pick any free desk — by the day or the month.',
                'colour' => '#22c55e', 'image' => 'media/space-flexi.svg',
                'rates' => ['day' => 500, 'month' => 4000],
            ],
            SeatCategory::Dedicated->value => [
                'description' => 'Your own desk in a quiet enclosed room. Ideal for teams — institutions can book several seats together.',
                'colour' => '#1d4ed8', 'image' => 'media/space-dedicated.svg',
                'rates' => ['month' => 5000],
            ],
            SeatCategory::Cabin->value => [
                'description' => 'Private executive cabins B, D and E with three seats each. Booked as a whole cabin.',
                'colour' => '#7c3aed', 'image' => 'media/space-cabin.svg',
                'rates' => ['month' => 8000],
            ],
            SeatCategory::Conference->value => [
                'description' => 'Conference room F seats nine, with projector and whiteboard. Booked by the hour.',
                'colour' => '#f59e0b', 'image' => 'media/space-conference.svg',
                'rates' => ['hour' => 500],
            ],
        ];

        foreach (SeatCategory::cases() as $i => $category) {
            $m = $meta[$category->value];
            $this->db->execute(
                'INSERT INTO seat_categories (code, name, short_name, description, billing_units, whole_unit_only, hourly_only, multi_select, colour, icon, image_path, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), image_path = VALUES(image_path)',
                [
                    $category->value, $category->label(), $category->shortLabel(), $m['description'],
                    implode(',', array_map(static fn ($u) => $u->value, $category->billingUnits())),
                    $category->wholeUnitOnly(), $category->hourlyOnly(), $category->multiSelect(),
                    $m['colour'], $category->icon(), $m['image'], $i,
                ],
            );
            $categoryId = (int) $this->db->scalar('SELECT id FROM seat_categories WHERE code = ?', [$category->value]);

            foreach ($m['rates'] as $unit => $amount) {
                $exists = $this->db->scalar(
                    "SELECT id FROM rates WHERE scope = 'category' AND scope_id = ? AND unit = ? AND effective_from = ?",
                    [$categoryId, $unit, self::EFFECTIVE_FROM],
                );
                if ($exists === null) {
                    $this->db->insert('rates', [
                        'scope' => 'category', 'scope_id' => $categoryId, 'unit' => $unit,
                        'amount' => $amount, 'gst_rate' => 18, 'effective_from' => self::EFFECTIVE_FROM,
                    ]);
                }
            }
        }
    }
}
