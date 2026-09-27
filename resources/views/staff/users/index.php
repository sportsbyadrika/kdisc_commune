<?php
/**
 * Staff user management (/staff/users): list with role, status, last sign-in and actions.
 *
 * @var App\Core\Template $this
 * @var list<array<string, mixed>> $users
 * @var array{q: string, role: string, status: string} $filters
 * @var array<string, int> $counts active users per role
 * @var list<string> $manageable role values the signed-in manager may manage
 * @var int $me
 */
use App\Enums\StaffRole;

$this->layout('layouts/staff', ['title' => 'Staff users', 'subtitle' => $subtitle ?? null, 'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Staff users']]]);
$highlight = session()->getFlash('highlight');
?>
<?php $this->start('actions') ?>
<a href="<?= e(url('staff.users.create')) ?>" class="btn btn-brand" data-test="staff-new"><?= icon('user-plus', 'size-4') ?>Add staff user</a>
<?php $this->stop() ?>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <?php foreach (StaffRole::cases() as $role): ?>
        <div class="card flex items-center gap-3 p-4">
            <span class="badge badge-<?= e($role->tone()) ?>"><?= e($role->label()) ?></span>
            <span class="ml-auto text-2xl font-extrabold tabular-nums"><?= (int) ($counts[$role->value] ?? 0) ?></span>
            <span class="sr-only">active</span>
        </div>
    <?php endforeach ?>
</div>

<form method="get" class="card mb-5 flex flex-col gap-2 p-3 sm:flex-row sm:items-end sm:p-4" role="search" aria-label="Filter staff users">
    <label class="flex min-w-0 flex-1 items-center gap-2 rounded-2xl bg-surface px-3 ring-1 ring-line focus-within:bg-white focus-within:ring-2 focus-within:ring-brand-600/30">
        <?= icon('search', 'size-4 shrink-0 text-muted') ?><span class="sr-only">Search staff</span>
        <input name="q" value="<?= e($filters['q']) ?>" class="w-full border-0 bg-transparent py-2.5 text-sm focus:ring-0 focus:outline-none" placeholder="Name, email or mobile">
    </label>
    <label class="sm:w-48"><span class="sr-only">Role</span>
        <select name="role" class="input">
            <option value="">All roles</option>
            <?php foreach (StaffRole::cases() as $role): ?><option value="<?= e($role->value) ?>" <?= $filters['role'] === $role->value ? 'selected' : '' ?>><?= e($role->label()) ?></option><?php endforeach ?>
        </select>
    </label>
    <label class="sm:w-40"><span class="sr-only">Status</span>
        <select name="status" class="input">
            <option value="">Any status</option>
            <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Deactivated</option>
        </select>
    </label>
    <button class="btn btn-outline"><?= icon('filter', 'size-4') ?>Filter</button>
</form>

<?php if ($users === []): ?>
    <?= $this->component('empty', ['icon' => 'users-round', 'title' => 'No staff users match', 'text' => 'Try another search or clear the filters.']) ?>
<?php else: ?>
<section class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm" data-test="staff-table">
            <caption class="sr-only">Staff users</caption>
            <thead class="bg-surface text-left text-[11px] font-bold tracking-wide text-muted uppercase">
                <tr><th scope="col" class="px-5 py-2.5">Name</th><th scope="col" class="px-4 py-2.5">Role</th><th scope="col" class="px-4 py-2.5">Status</th><th scope="col" class="px-4 py-2.5">Last sign-in</th><th scope="col" class="px-4 py-2.5"><span class="sr-only">Actions</span></th></tr>
            </thead>
            <tbody class="divide-y divide-line">
                <?php foreach ($users as $u):
                    $role = StaffRole::from((string) $u['role']);
                    $can = in_array($role->value, $manageable, true);
                    $self = (int) $u['id'] === $me;
                    $active = (int) $u['is_active'] === 1;
                ?>
                    <tr class="<?= e(class_names(['bg-surface/60 text-ink/60' => !$active, 'bg-brand-50/60' => (int) $highlight === (int) $u['id']])) ?>" data-test="staff-row-<?= (int) $u['id'] ?>">
                        <td class="px-5 py-3">
                            <p class="font-bold"><?= e((string) $u['name']) ?><?php if ($self): ?> <span class="badge badge-neutral ml-1">You</span><?php endif ?></p>
                            <p class="text-xs text-muted"><?= e((string) $u['email']) ?><?= $u['mobile'] ? ' · ' . e(format_phone((string) $u['mobile'])) : '' ?></p>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap"><?= $this->component('badge', ['label' => $role->label(), 'tone' => $role->tone()]) ?></td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <?php if (!$active): ?>
                                <span class="badge badge-neutral">Deactivated</span>
                                <?php if ($u['deactivated_at']): ?><p class="mt-1 text-xs text-muted">since <?= e(format_date((string) $u['deactivated_at'])) ?></p><?php endif ?>
                            <?php elseif ($u['invite_pending_since'] !== null && $u['last_login_at'] === null): ?>
                                <span class="badge badge-warning">Invite sent</span>
                                <p class="mt-1 text-xs text-muted"><?= e(format_date((string) $u['invite_pending_since'], 'd M, H:i')) ?></p>
                            <?php else: ?>
                                <span class="badge badge-success">Active</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap">
                            <?php if ($u['last_login_at']): ?>
                                <span class="font-semibold"><?= e(format_date((string) $u['last_login_at'], 'd M Y, H:i')) ?></span>
                                <span class="block text-muted"><?= e((string) $u['last_login_ip']) ?></span>
                            <?php else: ?><span class="text-muted">Never</span><?php endif ?>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <?php if ($can): ?>
                                <a href="<?= e(url('staff.users.edit', ['id' => (int) $u['id']])) ?>" class="btn btn-ghost btn-sm" aria-label="Edit <?= e((string) $u['name']) ?>"><?= icon('pencil', 'size-4') ?><span class="hidden md:inline">Edit</span></a>
                                <?php if ($active): ?>
                                    <form method="post" action="<?= e(url('staff.users.password_link', ['id' => (int) $u['id']])) ?>" class="inline" data-confirm="Email a single-use password link to <?= e((string) $u['email']) ?>?">
                                        <?= csrf_field() ?><button class="btn btn-ghost btn-sm" aria-label="Send password link to <?= e((string) $u['name']) ?>" title="<?= $u['last_login_at'] === null ? 'Resend invite' : 'Send reset link' ?>"><?= icon('key-round', 'size-4') ?><span class="hidden md:inline"><?= $u['last_login_at'] === null ? 'Resend invite' : 'Reset link' ?></span></button>
                                    </form>
                                    <?php if (!$self): ?>
                                        <form method="post" action="<?= e(url('staff.users.deactivate', ['id' => (int) $u['id']])) ?>" class="inline" data-confirm="Deactivate <?= e((string) $u['name']) ?>? They are signed out and cannot sign in until reactivated.">
                                            <?= csrf_field() ?><button class="btn btn-ghost btn-sm text-red-700" aria-label="Deactivate <?= e((string) $u['name']) ?>"><?= icon('user-x', 'size-4') ?><span class="hidden md:inline">Deactivate</span></button>
                                        </form>
                                    <?php endif ?>
                                <?php else: ?>
                                    <form method="post" action="<?= e(url('staff.users.reactivate', ['id' => (int) $u['id']])) ?>" class="inline">
                                        <?= csrf_field() ?><button class="btn btn-outline btn-sm" aria-label="Reactivate <?= e((string) $u['name']) ?>"><?= icon('user-check', 'size-4') ?>Reactivate</button>
                                    </form>
                                <?php endif ?>
                            <?php else: ?>
                                <span class="text-xs text-muted">Managed by State Admin</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
</section>
<p class="mt-4 text-xs text-muted">Every change is recorded in the <a class="font-semibold text-brand-700 hover:underline" href="<?= e(url('staff.audit.index', ['entity' => 'staff_user'])) ?>">audit log</a>. Two-factor sign-in (TOTP) is not enabled yet.</p>
<?php endif ?>
