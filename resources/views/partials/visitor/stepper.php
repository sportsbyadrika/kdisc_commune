<?php
/**
 * Wizard stepper with progress bar. Completed steps link back; future steps are locked.
 *
 * @var int $step      current step
 * @var int $maxStep   furthest reachable step
 * @var array<string, mixed> $customer
 */
use App\Services\Visitors\ProfileService;

$done = (int) ($customer['profile_step'] ?? 0);
$pct = (int) round(min(4, max($done, $step - 1)) / 4 * 100);
?>
<div class="card card-body">
    <div class="flex items-center justify-between text-sm">
        <p class="font-semibold">Step <?= $step ?> of 4 · <span class="text-muted"><?= e(ProfileService::STEPS[$step]) ?></span></p>
        <p class="font-semibold tabular-nums text-brand-700"><?= $pct ?>%</p>
    </div>
    <div class="mt-3 h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Profile completion">
        <div class="h-full rounded-full bg-gradient-to-r from-brand-600 to-accent-500 transition-all duration-700" style="width: <?= $pct ?>%"></div>
    </div>
    <ol class="mt-5 grid grid-cols-4 gap-2">
        <?php foreach (ProfileService::STEPS as $n => $label):
            $isDone = $n <= $done;
            $isCurrent = $n === $step;
            $reachable = $n <= $maxStep;
        ?>
            <li>
                <?php if ($reachable && !$isCurrent): ?><a href="<?= e(url('portal.wizard', ['step' => $n])) ?>" class="group flex flex-col items-center gap-2 text-center sm:flex-row sm:text-left"><?php else: ?><span class="flex flex-col items-center gap-2 text-center sm:flex-row sm:text-left" <?= $isCurrent ? 'aria-current="step"' : '' ?>><?php endif ?>
                    <span class="<?= e(class_names('grid size-9 shrink-0 place-items-center rounded-full text-sm font-bold transition', match (true) {
                        $isCurrent => 'bg-brand-600 text-white ring-4 ring-brand-100',
                        $isDone => 'bg-emerald-500 text-white group-hover:bg-emerald-600',
                        default => 'bg-surface-2 text-muted',
                    })) ?>"><?= $isDone && !$isCurrent ? icon('check', 'size-4') : $n ?></span>
                    <span class="<?= e(class_names('text-xs font-semibold leading-tight sm:text-sm', $isCurrent ? 'text-ink' : 'text-muted', ['group-hover:text-ink' => $reachable])) ?>"><?= e($label) ?></span>
                <?= $reachable && !$isCurrent ? '</a>' : '</span>' ?>
            </li>
        <?php endforeach ?>
    </ol>
</div>
