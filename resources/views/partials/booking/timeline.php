<?php
/**
 * Booking status timeline (BookingDirectory::timeline()).
 *
 * @var list<array{status: App\Enums\BookingStatus, state: string, at: ?string, note: string}> $timeline
 */
?>
<ol class="relative space-y-5">
    <?php foreach ($timeline as $i => $step): $last = $i === count($timeline) - 1; ?>
        <li class="relative flex gap-4">
            <?php if (!$last): ?><span class="absolute top-8 bottom-[-1.25rem] left-[15px] w-0.5 <?= $step['state'] === 'done' ? 'bg-emerald-400' : 'bg-line' ?>" aria-hidden="true"></span><?php endif ?>
            <span class="relative z-10 grid size-8 shrink-0 place-items-center rounded-full <?= match ($step['state']) {
                'done' => 'bg-emerald-500 text-white',
                'current' => 'bg-brand-600 text-white ring-4 ring-brand-100',
                'stopped' => 'bg-red-500 text-white',
                default => 'bg-white text-muted ring-2 ring-line',
            } ?>">
                <?= match ($step['state']) {
                    'done' => icon('check', 'size-4'),
                    'current' => icon('hourglass', 'size-4'),
                    'stopped' => icon('x', 'size-4'),
                    default => '<span class="size-2 rounded-full bg-line"></span>',
                } ?>
            </span>
            <div class="min-w-0 pt-0.5">
                <p class="text-sm font-bold <?= $step['state'] === 'upcoming' ? 'text-muted' : '' ?>"><?= e($step['status']->label()) ?>
                    <?php if ($step['state'] === 'current'): ?><span class="ml-1 text-xs font-semibold text-brand-600">· current</span><?php endif ?></p>
                <p class="text-xs text-muted"><?= e($step['note']) ?><?= $step['at'] ? ' · ' . e(format_date($step['at'], 'd M Y, g:i a')) : '' ?></p>
            </div>
        </li>
    <?php endforeach ?>
</ol>
