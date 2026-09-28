<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Core\Database;
use App\Enums\HolderType;
use App\Enums\StaffRole;
use App\Enums\TokenPurpose;
use App\Models\StaffUser;
use App\Services\AuditLog;
use App\Services\Auth\PasswordHasher;
use App\Services\Auth\PasswordTokenService;
use App\Services\Notify\Mailer;

/**
 * Staff user management (/staff/users) and staff password links. THE rules:
 *
 *  - Centre Managers manage Receptionists, Finance Admins and Centre Managers; State Admins manage everyone
 *    (manageableRoles()). Nobody edits a role they could not assign.
 *  - New staff get an invite email with a single-use set-password link (PasswordTokenService, purpose invite,
 *    valid setting('staff_invite_hours', 72) hours). Until then the account has an unusable random password.
 *  - You cannot deactivate yourself or change your own role; the last active Centre Manager and the last active
 *    State Admin can be neither deactivated nor moved to another role.
 *  - Password reset links (staff "Forgot password", or a manager's "Send reset link") are emailed only — a manager
 *    never sees or sets another person's password. Setting a password stamps password_changed_at, which signs
 *    out every older session of that user (Guard).
 *  - Everything is audit-logged (staff.create / staff.update / staff.deactivate / staff.reactivate /
 *    staff.password_link / staff.password_set).
 */
final class StaffUserService
{
    public function __construct(
        private readonly Database $db,
        private readonly PasswordTokenService $tokens,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return list<StaffRole> */
    public static function manageableRoles(StaffRole $actor): array
    {
        return match ($actor) {
            StaffRole::StateAdmin => StaffRole::cases(),
            StaffRole::CentreManager => [StaffRole::Receptionist, StaffRole::CentreManager, StaffRole::FinanceAdmin],
            default => [],
        };
    }

    /** @param array<string, mixed> $actor staff row */
    public static function canManage(array $actor, StaffRole $role): bool
    {
        $actorRole = StaffRole::tryFrom((string) ($actor['role'] ?? ''));
        return $actorRole !== null && in_array($role, self::manageableRoles($actorRole), true);
    }

    /**
     * Staff list with status and last sign-in, filtered by role / status / search.
     *
     * @param array{q?: string, role?: string, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = ['1 = 1'];
        $bind = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($q, 0, 100)) . '%';
            $where[] = '(s.name LIKE ? OR s.email LIKE ? OR s.mobile LIKE ?)';
            array_push($bind, $like, $like, $like);
        }
        $role = StaffRole::tryFrom((string) ($filters['role'] ?? ''));
        if ($role !== null) {
            $where[] = 's.role = ?';
            $bind[] = $role->value;
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'active' || $status === 'inactive') {
            $where[] = 's.is_active = ?';
            $bind[] = $status === 'active' ? 1 : 0;
        }
        return $this->db->select(
            'SELECT s.id, s.name, s.email, s.mobile, s.role, s.is_active, s.last_login_at, s.last_login_ip, s.created_at, s.deactivated_at,
                    s.password_changed_at, c.name AS created_by_name,
                    (SELECT MAX(t.created_at) FROM password_tokens t WHERE t.subject_type = \'staff\' AND t.subject_id = s.id AND t.purpose = \'invite\' AND t.used_at IS NULL AND t.expires_at > NOW()) AS invite_pending_since
             FROM staff_users s LEFT JOIN staff_users c ON c.id = s.created_by
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY s.is_active DESC, FIELD(s.role, \'state_admin\', \'centre_manager\', \'finance_admin\', \'receptionist\'), s.name',
            $bind,
        );
    }

    /** @return array<string, mixed>|null safe row (no password hash) */
    public function find(int $id): ?array
    {
        $row = StaffUser::find($id);
        if ($row === null) {
            return null;
        }
        unset($row['password_hash']);
        return $row;
    }

    /**
     * Validation rules for create / edit (role list limited to what the actor may assign).
     *
     * @param array<string, mixed> $actor
     * @return array<string, string|list<string>>
     */
    public function rules(array $actor, ?int $id = null): array
    {
        $roles = array_map(static fn (StaffRole $r) => $r->value, self::manageableRoles(StaffRole::from((string) $actor['role'])));
        return [
            'name' => 'required|string|min:2|max:150',
            'email' => 'required|email|max:190|unique:staff_users,email' . ($id !== null ? ',' . $id : ''),
            'mobile' => 'nullable|phone_in',
            'role' => ['required', 'in:' . implode(',', $roles)],
        ];
    }

    /**
     * Create a staff user and email the invite. Returns the new id.
     *
     * @param array{name: string, email: string, mobile?: string|null, role: string} $data
     * @param array<string, mixed> $actor
     */
    public function create(array $data, array $actor): int
    {
        $role = StaffRole::from($data['role']);
        if (!self::canManage($actor, $role)) {
            throw new StaffRuleException('You cannot create a ' . $role->label() . '.');
        }
        $email = mb_strtolower(trim($data['email']));
        if (StaffUser::findByEmail($email) !== null) {
            throw new StaffRuleException('A staff account with this email already exists.');
        }
        $id = StaffUser::create([
            'centre_id' => $role === StaffRole::StateAdmin ? null : ($actor['centre_id'] ?? $this->defaultCentre()),
            'name' => trim($data['name']),
            'email' => $email,
            'mobile' => ($data['mobile'] ?? '') !== '' ? $data['mobile'] : null,
            // unusable until the invite link is used
            'password_hash' => $this->hasher->hash(bin2hex(random_bytes(32))),
            'role' => $role->value,
            'is_active' => 1,
            'created_by' => (int) $actor['id'],
        ]);
        $this->audit->record('staff.create', 'staff_user', $id, null, ['name' => $data['name'], 'email' => $email, 'role' => $role->value], null, 'staff', (int) $actor['id']);
        $this->sendLink((array) StaffUser::find($id), TokenPurpose::Invite, $actor);
        return $id;
    }

    /**
     * @param array{name: string, email: string, mobile?: string|null, role: string} $data
     * @param array<string, mixed> $actor
     */
    public function update(int $id, array $data, array $actor): void
    {
        $user = StaffUser::find($id) ?? throw new StaffRuleException('Staff user not found.');
        $old = StaffRole::from((string) $user['role']);
        $new = StaffRole::from($data['role']);
        if (!self::canManage($actor, $old) || !self::canManage($actor, $new)) {
            throw new StaffRuleException('You cannot manage ' . $old->label() . ' accounts.');
        }
        if ($new !== $old) {
            if ($id === (int) $actor['id']) {
                throw new StaffRuleException('You cannot change your own role — ask another manager.');
            }
            $this->guardLastOfRole($user, 'move to another role');
        }
        $email = mb_strtolower(trim($data['email']));
        $other = StaffUser::findByEmail($email);
        if ($other !== null && (int) $other['id'] !== $id) {
            throw new StaffRuleException('A staff account with this email already exists.');
        }
        $cols = [
            'name' => trim($data['name']),
            'email' => $email,
            'mobile' => ($data['mobile'] ?? '') !== '' ? $data['mobile'] : null,
            'role' => $new->value,
            'centre_id' => $new === StaffRole::StateAdmin ? null : ($user['centre_id'] ?? $actor['centre_id'] ?? $this->defaultCentre()),
        ];
        $before = array_intersect_key($user, $cols);
        StaffUser::update($id, $cols);
        if ($email !== $user['email']) {
            // a changed address must not keep working links sent to the old one
            $this->db->execute('UPDATE password_tokens SET used_at = NOW() WHERE subject_type = ? AND subject_id = ? AND used_at IS NULL', [HolderType::Staff->value, $id]);
        }
        $this->audit->record('staff.update', 'staff_user', $id, $before, $cols, null, 'staff', (int) $actor['id']);
    }

    /** @param array<string, mixed> $actor */
    public function setActive(int $id, bool $active, array $actor, ?string $reason = null): void
    {
        $user = StaffUser::find($id) ?? throw new StaffRuleException('Staff user not found.');
        if (!self::canManage($actor, StaffRole::from((string) $user['role']))) {
            throw new StaffRuleException('You cannot manage ' . StaffRole::from((string) $user['role'])->label() . ' accounts.');
        }
        if ((bool) $user['is_active'] === $active) {
            return;
        }
        if (!$active) {
            if ($id === (int) $actor['id']) {
                throw new StaffRuleException('You cannot deactivate your own account.');
            }
            $this->guardLastOfRole($user, 'deactivate');
        }
        StaffUser::update($id, ['is_active' => $active ? 1 : 0, 'deactivated_at' => $active ? null : date('Y-m-d H:i:s')]);
        if (!$active) {
            $this->db->execute('UPDATE password_tokens SET used_at = NOW() WHERE subject_type = ? AND subject_id = ? AND used_at IS NULL', [HolderType::Staff->value, $id]);
        }
        $this->audit->record($active ? 'staff.reactivate' : 'staff.deactivate', 'staff_user', $id, ['is_active' => (int) $user['is_active']], ['is_active' => $active ? 1 : 0], $reason, 'staff', (int) $actor['id']);
    }

    /**
     * Email a password link (invite for accounts that never signed in, else reset).
     *
     * @param array<string, mixed> $user staff row
     * @param array<string, mixed>|null $actor manager who asked (null = the user via "Forgot password")
     */
    public function sendLink(array $user, ?TokenPurpose $purpose = null, ?array $actor = null): bool
    {
        if ((int) ($user['is_active'] ?? 0) !== 1) {
            return false;
        }
        $purpose ??= $user['last_login_at'] === null && $user['password_changed_at'] === null ? TokenPurpose::Invite : TokenPurpose::Reset;
        $minutes = $purpose === TokenPurpose::Invite
            ? max(1, (int) setting('staff_invite_hours', 72)) * 60
            : $this->tokens->lifetimeMinutes();
        $token = $this->tokens->issue(HolderType::Staff, (int) $user['id'], $purpose, $minutes);
        $role = StaffRole::from((string) $user['role']);
        $sent = $this->mailer->send([(string) $user['email'], (string) $user['name']], $purpose === TokenPurpose::Invite
            ? 'You have been added to the Commune staff console'
            : 'Reset your Commune staff password', 'staff-password', [
            'name' => (string) $user['name'],
            'role' => $role->label(),
            'invite' => $purpose === TokenPurpose::Invite,
            'url' => absolute_url('staff.password.reset', ['token' => $token]),
            'minutes' => $minutes,
            'by' => $actor !== null ? (string) $actor['name'] : null,
        ]);
        $this->audit->record('staff.password_link', 'staff_user', (int) $user['id'], null, ['purpose' => $purpose->value, 'emailed' => $sent], null, $actor !== null ? 'staff' : 'system', $actor !== null ? (int) $actor['id'] : null);
        return $sent;
    }

    /** "Forgot password" on /staff/login: never reveals whether the email exists. */
    public function forgot(string $email): void
    {
        $user = StaffUser::findByEmail($email);
        if ($user !== null && (int) $user['is_active'] === 1) {
            $this->sendLink($user, TokenPurpose::Reset);
        }
    }

    /**
     * Apply a consumed staff token.
     *
     * @param array<string, mixed> $tokenRow
     * @return array<string, mixed> the staff row
     */
    public function setPassword(array $tokenRow, string $password): array
    {
        $id = (int) $tokenRow['subject_id'];
        $user = StaffUser::find($id) ?? throw new StaffRuleException('Staff user not found.');
        if ((int) $user['is_active'] !== 1) {
            throw new StaffRuleException('This staff account is deactivated. Please contact your Centre Manager.');
        }
        StaffUser::update($id, ['password_hash' => $this->hasher->hash($password), 'password_changed_at' => date('Y-m-d H:i:s')]);
        $this->audit->record('staff.password_set', 'staff_user', $id, null, ['purpose' => (string) $tokenRow['purpose']], null, 'staff', $id);
        return (array) StaffUser::find($id);
    }

    /**
     * Create a staff user from the console (first Centre Manager on a new server) with a password.
     *
     * @return int new id
     */
    public function createWithPassword(string $name, string $email, StaffRole $role, string $password): int
    {
        $email = mb_strtolower(trim($email));
        if (StaffUser::findByEmail($email) !== null) {
            throw new StaffRuleException("A staff account for {$email} already exists.");
        }
        $id = StaffUser::create([
            'centre_id' => $role === StaffRole::StateAdmin ? null : $this->defaultCentre(),
            'name' => trim($name),
            'email' => $email,
            'password_hash' => $this->hasher->hash($password),
            'password_changed_at' => date('Y-m-d H:i:s'),
            'role' => $role->value,
            'is_active' => 1,
        ]);
        $this->audit->record('staff.create', 'staff_user', $id, null, ['name' => $name, 'email' => $email, 'role' => $role->value, 'via' => 'console'], null, 'system', null);
        return $id;
    }

    /**
     * Active staff counts per role (for the list header).
     *
     * @return array<string, int>
     */
    public function activeCounts(): array
    {
        $out = [];
        foreach ($this->db->select('SELECT role, COUNT(*) AS n FROM staff_users WHERE is_active = 1 GROUP BY role') as $r) {
            $out[(string) $r['role']] = (int) $r['n'];
        }
        return $out;
    }

    /** @param array<string, mixed> $user */
    private function guardLastOfRole(array $user, string $what): void
    {
        $role = StaffRole::from((string) $user['role']);
        if (!in_array($role, [StaffRole::CentreManager, StaffRole::StateAdmin], true) || (int) $user['is_active'] !== 1) {
            return;
        }
        $others = (int) $this->db->scalar('SELECT COUNT(*) FROM staff_users WHERE role = ? AND is_active = 1 AND id <> ?', [$role->value, (int) $user['id']]);
        if ($others === 0) {
            throw new StaffRuleException(sprintf('You cannot %s the last active %s — add another one first.', $what, $role->label()));
        }
    }

    private function defaultCentre(): ?int
    {
        $id = $this->db->scalar('SELECT id FROM centres ORDER BY id LIMIT 1');
        return $id !== null ? (int) $id : null;
    }
}
