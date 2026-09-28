<?php
/**
 * Layout version history of a floor: who created / published each version and when, counts, publish summary,
 * preview any version, restore an old version as a new draft.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $floor
 * @var list<array<string, mixed>> $floors
 * @var list<array<string, mixed>> $versions
 * @var bool $hasDraft
 */
$this->layout('layouts/staff', [
    'title' => 'Version history',
    'wide' => true,
    'hideTitle' => true,
    'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Layout & pricing', url('staff.layout.index')], ['Version history']],
]);
?>
<div class="mb-6 flex flex-wrap items-center gap-x-4 gap-y-3">
    <div>
        <p class="text-[11px] font-bold tracking-[0.16em] text-muted uppercase">Layout &amp; pricing</p>
        <h1 class="text-2xl font-extrabold">Version history</h1>
    </div>
    <nav class="flex rounded-full bg-surface-2 p-1" aria-label="Floors">
        <?php foreach ($floors as $f): $on = $f['slug'] === $floor['slug']; ?>
            <a href="<?= e(url('staff.layout.history', ['floor' => (string) $f['slug']])) ?>" class="<?= $on ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink' ?> inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-bold whitespace-nowrap transition"><?= icon('layers', 'size-4') ?><?= e(str_replace(' Floor', '', (string) $f['name'])) ?></a>
        <?php endforeach ?>
    </nav>
    <?= $this->partial('partials/layout/nav', ['active' => 'history', 'floorSlug' => (string) $floor['slug']]) ?>
</div>

<div class="card overflow-hidden">
    <ol class="divide-y divide-line">
        <?php foreach ($versions as $v):
            $status = App\Enums\LayoutStatus::from((string) $v['status']);
            $summary = $v['summary'] !== null ? (array) json_decode((string) $v['summary'], true) : [];
        ?>
            <li class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-[4.5rem_minmax(0,1fr)_auto] sm:items-center">
                <div class="flex items-center gap-3 sm:block">
                    <span class="grid size-12 place-items-center rounded-2xl font-display text-lg font-extrabold <?= $status === App\Enums\LayoutStatus::Published ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : ($status === App\Enums\LayoutStatus::Draft ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200' : 'bg-surface-2 text-ink/60') ?>">v<?= (int) $v['version_no'] ?></span>
                </div>
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2">
                        <?= $this->component('badge', ['label' => $status->label(), 'tone' => $status->tone(), 'dot' => true]) ?>
                        <span class="text-sm font-semibold"><?= (int) $v['units'] ?> units · <?= (int) $v['chairs'] ?> chairs · <?= (int) $v['zones'] ?> zones</span>
                    </p>
                    <p class="mt-1.5 text-sm text-muted">
                        <?php if ($v['published_at'] !== null): ?>
                            Published <b class="text-ink"><?= e(format_date((string) $v['published_at'], 'd M Y, h:i A')) ?></b> by <?= e((string) ($v['published_by_name'] ?? '—')) ?>
                            <?php if ($v['archived_at'] !== null): ?> · replaced <?= e(format_date((string) $v['archived_at'], 'd M Y, h:i A')) ?><?php endif ?>
                        <?php else: ?>
                            Draft started <?= e(format_date((string) $v['created_at'], 'd M Y, h:i A')) ?> by <?= e((string) ($v['created_by_name'] ?? '—')) ?> · last edit <?= e(format_date((string) $v['updated_at'], 'd M Y, h:i A')) ?> by <?= e((string) ($v['updated_by_name'] ?? '—')) ?>
                        <?php endif ?>
                    </p>
                    <?php if ($summary !== []): ?>
                        <p class="mt-2 flex flex-wrap gap-1.5 text-xs">
                            <?php foreach (['added' => 'added', 'removed' => 'removed', 'moved' => 'moved', 'recoded' => 'renumbered', 'recategorised' => 'changed type', 'status_changed' => 'status changes'] as $k => $l): if (empty($summary[$k])) { continue; } ?>
                                <span class="rounded-full bg-surface px-2 py-0.5 font-semibold text-ink/70 ring-1 ring-line"><?= (int) $summary[$k] ?> <?= e($l) ?></span>
                            <?php endforeach ?>
                            <?php if (!empty($summary['warnings'])): ?><span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200"><?= (int) $summary['warnings'] ?> warnings confirmed</span><?php endif ?>
                        </p>
                    <?php endif ?>
                    <?php if (!empty($v['notes'])): ?><p class="mt-2 text-sm italic text-ink/70">“<?= e((string) $v['notes']) ?>”</p><?php endif ?>
                </div>
                <div class="flex flex-wrap gap-2 sm:justify-end">
                    <a href="<?= e(url('staff.layout.preview', ['floor' => (string) $floor['slug'], 'version' => (int) $v['id']])) ?>" class="btn btn-outline btn-sm"><?= icon('eye', 'size-4') ?>Preview</a>
                    <?php if ($status === App\Enums\LayoutStatus::Draft): ?>
                        <a href="<?= e(url('staff.layout.floor', ['floor' => (string) $floor['slug']])) ?>" class="btn btn-brand btn-sm"><?= icon('pencil', 'size-4') ?>Continue editing</a>
                    <?php elseif ($status === App\Enums\LayoutStatus::Archived && !$hasDraft): ?>
                        <form method="post" action="<?= e(url('staff.layout.restore', ['floor' => (string) $floor['slug'], 'version' => (int) $v['id']])) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-outline btn-sm" title="Creates a new draft from this version — publish it to go back"><?= icon('rotate-ccw', 'size-4') ?>Restore as draft</button>
                        </form>
                    <?php endif ?>
                </div>
            </li>
        <?php endforeach ?>
    </ol>
</div>
<p class="mt-4 flex items-center gap-2 text-xs text-muted"><?= icon('info', 'size-4') ?>Old versions are kept unchanged: bookings always point at the exact seat that was booked, and keep occupying it in newer versions (matched by the seat’s stable key).</p>
