<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use App\Core\Database;
use App\Enums\BookingStatus;
use App\Services\SettingsService;
use App\Support\Clock;
use DateTimeImmutable;
use Throwable;

/**
 * `php bin/console bookings:tick` — the scheduled side of the state machine (run it every 15 min–1 h).
 * Idempotent: every step only picks rows still in the source state, and reminders are de-duplicated through
 * notifications.dedupe_key, so running it twice in a row changes nothing the second time.
 *
 *   1. confirmed & start_date ≤ today          → active     ("Start date reached")
 *   2. active & end_date < today                → completed  (open check-ins are closed)
 *   3. approved & payment_due_by < today, unpaid → cancelled (expired)
 *   4. confirmed/active bookings ending in 15 / 7 / 1 days (setting renewal_reminder_days) → renewal reminder,
 *      unless a follow-on booking exists; a missed run still sends the nearest pending reminder once.
 */
final class BookingTicker
{
    public function __construct(
        private readonly Database $db,
        private readonly BookingWorkflow $workflow,
        private readonly BookingNotifier $notifier,
        private readonly SettingsService $settings,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{activated: int, completed: int, expired: int, reminders: int, errors: list<string>} */
    public function run(): array
    {
        $today = $this->clock->today();
        $system = Actor::system();
        $out = ['activated' => 0, 'completed' => 0, 'expired' => 0, 'reminders' => 0, 'errors' => []];
        $each = function (string $sql, array $bind, callable $fn) use (&$out): void {
            foreach ($this->db->column($sql, $bind) as $id) {
                try {
                    $fn((int) $id);
                } catch (Throwable $e) {
                    $out['errors'][] = sprintf('#%d: %s', $id, $e->getMessage());
                }
            }
        };

        $each("SELECT id FROM bookings WHERE status = 'confirmed' AND start_date <= ? ORDER BY id", [$today], function (int $id) use ($system, &$out): void {
            $this->workflow->activate($id, $system, 'Start date reached');
            $out['activated']++;
        });
        $each("SELECT id FROM bookings WHERE status = 'active' AND end_date < ? ORDER BY id", [$today], function (int $id) use ($system, &$out): void {
            $this->workflow->complete($id, $system, 'End date passed');
            $out['completed']++;
        });
        $each("SELECT id FROM bookings WHERE status = 'approved' AND payment_due_by IS NOT NULL AND payment_due_by < ? ORDER BY id", [$today], function (int $id) use (&$out): void {
            if ($this->workflow->expire($id)) {
                $out['expired']++;
            }
        });

        $thresholds = $this->thresholds();
        if ($thresholds !== []) {
            $max = max($thresholds);
            $horizon = (new DateTimeImmutable($today))->modify("+{$max} days")->format('Y-m-d');
            $rows = $this->db->select(
                "SELECT b.* FROM bookings b
                 WHERE b.status IN ('confirmed', 'active') AND b.start_time IS NULL AND b.end_date >= ? AND b.end_date <= ?
                   AND DATEDIFF(b.end_date, b.start_date) >= 6
                   AND NOT EXISTS (SELECT 1 FROM bookings r WHERE r.renewed_from_id = b.id AND r.status NOT IN ('cancelled', 'rejected'))
                 ORDER BY b.end_date, b.id",
                [$today, $horizon],
            );
            foreach ($rows as $b) {
                $left = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable((string) $b['end_date']))->days;
                // the smallest threshold we have reached (15 → 7 → 1); send it once
                $due = array_values(array_filter($thresholds, static fn (int $t) => $left <= $t));
                if ($due === []) {
                    continue;
                }
                try {
                    if ($this->notifier->renewalDue($b, min($due))) {
                        $out['reminders']++;
                    }
                } catch (Throwable $e) {
                    $out['errors'][] = sprintf('#%d reminder: %s', $b['id'], $e->getMessage());
                }
            }
        }
        return $out;
    }

    /** @return list<int> e.g. [15, 7, 1] */
    public function thresholds(): array
    {
        $raw = (string) $this->settings->get('renewal_reminder_days', '15,7,1');
        $out = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn (int $d) => $d > 0)));
        rsort($out);
        return $out;
    }

    /**
     * Status values touched by the ticker (documentation / tests).
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [BookingStatus::Confirmed->value, BookingStatus::Active->value, BookingStatus::Approved->value];
    }
}
