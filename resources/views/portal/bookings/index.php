<?php
/**
 * My bookings (visitor portal).
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $customer
 * @var list<array<string, mixed>> $bookings
 */
use App\Enums\BookingStatus;
use App\Enums\SeatCategory;

$this->layout('layouts/portal', ['heading' => 'My bookings', 'subheading' => 'Requests, approvals and your active seats.']);
?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm text-muted"><?= count($bookings) ?> <?= count($bookings) === 1 ? 'booking' : 'bookings' ?></p>
    <a href="<?= e(url('spaces.explore')) ?>" class="btn btn-primary"><?= icon('plus', 'size-4') ?>Book a seat</a>
</div>
<?php if ($bookings === []): ?>
    <?= $this->component('empty', ['icon' => 'calendar-check', 'title' => 'No bookings yet', 'text' => 'Pick a floor and a seat in the Space Explorer — your requests show up here with their status.', 'action' => ['label' => 'Open the Space Explorer', 'href' => url('spaces.explore'), 'variant' => 'brand']]) ?>
<?php else: ?>
    <div class="grid gap-4">
        <?php foreach ($bookings as $b):
            $status = BookingStatus::from((string) $b['status']);
            $cat = SeatCategory::tryFrom((string) $b['category']);
            $flow = [BookingStatus::Requested, BookingStatus::Approved, BookingStatus::Confirmed, BookingStatus::Active, BookingStatus::Completed];
            $pos = array_search($status, $flow, true);
        ?>
            <a href="<?= e(url('portal.bookings.show', ['no' => $b['booking_no']])) ?>" class="card card-hover group flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
                <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-brand-50 text-brand-700 transition group-hover:bg-brand-600 group-hover:text-white"><?= icon($cat?->icon() ?? 'armchair', 'size-6') ?></span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-mono text-sm font-bold text-brand-900"><?= e($b['booking_no']) ?></p>
                        <?= $this->component('badge', ['label' => $status->label(), 'tone' => $status->tone(), 'dot' => true]) ?>
                    </div>
                    <p class="mt-1 font-bold"><?= e($cat?->label() ?? $b['category_name']) ?> · <?= e((string) $b['seat_codes']) ?></p>
                    <p class="text-sm text-muted"><?= e((string) $b['floor_name']) ?> ·
                        <?= $b['start_time'] ? e(format_date($b['start_date'], 'D, d M Y') . ' · ' . substr((string) $b['start_time'], 0, 5) . '–' . substr((string) $b['end_time'], 0, 5)) : e(format_date($b['start_date'], 'd M') . ' → ' . format_date($b['end_date'])) ?></p>
                    <?php if ($pos !== false): ?>
                        <div class="mt-3 flex max-w-sm gap-1" aria-hidden="true">
                            <?php foreach ($flow as $i => $st): ?><span class="h-1.5 flex-1 rounded-full <?= $i <= $pos ? 'bg-emerald-500' : 'bg-surface-2' ?>"></span><?php endforeach ?>
                        </div>
                    <?php endif ?>
                </div>
                <div class="shrink-0 sm:text-right">
                    <p class="font-display text-xl font-extrabold"><?= e(money($b['grand_total'])) ?></p>
                    <p class="text-xs text-muted">incl. GST · <?= e(App\Enums\PaymentRule::from((string) $b['payment_rule'])->label()) ?></p>
                </div>
                <?= icon('chevron-right', 'hidden size-5 text-muted sm:block') ?>
            </a>
        <?php endforeach ?>
    </div>
<?php endif ?>
