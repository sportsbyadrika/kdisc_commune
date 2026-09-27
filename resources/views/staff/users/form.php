<?php
/**
 * Add / edit a staff user.
 *
 * @var App\Core\Template $this
 * @var array<string, mixed> $user
 * @var array<string, string> $roles role => "Label — description" the manager may assign
 * @var bool $self editing your own account (role locked)
 */
$editing = isset($user['id']);
$this->layout('layouts/staff', [
    'title' => $editing ? 'Edit ' . $user['name'] : 'Add staff user',
    'breadcrumb' => [['Dashboard', url('staff.dashboard')], ['Staff users', url('staff.users.index')], [$editing ? (string) $user['name'] : 'New']],
]);
?>
<form method="post" action="<?= e($editing ? url('staff.users.update', ['id' => (int) $user['id']]) : url('staff.users.store')) ?>" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]" novalidate>
    <?= csrf_field() ?>
    <?php if ($editing): ?><?= method_field('PUT') ?><?php endif ?>
    <div class="card space-y-5 p-6">
        <?= $this->component('input', ['name' => 'name', 'label' => 'Full name', 'value' => $user['name'], 'required' => true, 'autocomplete' => 'off']) ?>
        <div class="grid gap-5 sm:grid-cols-2">
            <?= $this->component('input', ['name' => 'email', 'label' => 'Work email', 'type' => 'email', 'value' => $user['email'], 'required' => true, 'autocomplete' => 'off', 'help' => $editing ? 'Changing it cancels links sent to the old address.' : 'The invite to set a password goes here.']) ?>
            <?= $this->component('input', ['name' => 'mobile', 'label' => 'Mobile', 'type' => 'tel', 'value' => (string) ($user['mobile'] ?? ''), 'autocomplete' => 'off', 'placeholder' => '+91 94000 00000']) ?>
        </div>
        <?php if ($self): ?>
            <input type="hidden" name="role" value="<?= e((string) $user['role']) ?>">
            <?= $this->component('alert', ['tone' => 'info', 'message' => 'You cannot change your own role — ask another manager.']) ?>
        <?php else: ?>
            <fieldset>
                <legend class="label">Role <span class="text-accent-500" aria-hidden="true">*</span></legend>
                <div class="grid gap-2">
                    <?php foreach ($roles as $value => $text): [$label, $desc] = explode(' — ', $text, 2); $checked = (string) old('role', (string) $user['role']) === $value; ?>
                        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-line p-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50/60">
                            <input type="radio" name="role" value="<?= e($value) ?>" <?= $checked ? 'checked' : '' ?> class="mt-1 size-4 accent-brand-600">
                            <span><span class="block text-sm font-bold"><?= e($label) ?></span><span class="block text-xs text-muted"><?= e($desc) ?></span></span>
                        </label>
                    <?php endforeach ?>
                </div>
                <?php if (errors('role')): ?><p class="error-text"><?= icon('circle-alert', 'size-3.5') ?><?= e(errors('role')) ?></p><?php endif ?>
            </fieldset>
        <?php endif ?>
    </div>
    <aside class="space-y-4">
        <div class="card space-y-3 p-5 text-sm">
            <h2 class="font-bold"><?= $editing ? 'Account' : 'What happens next' ?></h2>
            <?php if ($editing): ?>
                <p><span class="text-muted">Status:</span> <?= (int) $user['is_active'] === 1 ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-neutral">Deactivated</span>' ?></p>
                <p><span class="text-muted">Last sign-in:</span> <?= $user['last_login_at'] ? e(format_date((string) $user['last_login_at'], 'd M Y, H:i')) : 'Never' ?></p>
                <p><span class="text-muted">Password changed:</span> <?= $user['password_changed_at'] ? e(format_date((string) $user['password_changed_at'], 'd M Y, H:i')) : '—' ?></p>
                <p class="text-xs text-muted">Passwords are never visible to managers. Use “Reset link” on the list to email a single-use link.</p>
            <?php else: ?>
                <ol class="list-decimal space-y-1.5 pl-4 text-muted">
                    <li>We email a single-use link to set a password (valid <?= (int) setting('staff_invite_hours', 72) ?> hours).</li>
                    <li>They sign in at <span class="font-mono text-xs"><?= e(absolute_url('staff.login')) ?></span>.</li>
                    <li>Their menu shows only what their role allows.</li>
                </ol>
            <?php endif ?>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-brand flex-1"><?= icon($editing ? 'save' : 'send', 'size-4') ?><?= $editing ? 'Save changes' : 'Add & send invite' ?></button>
            <a href="<?= e(url('staff.users.index')) ?>" class="btn btn-outline">Cancel</a>
        </div>
    </aside>
</form>
