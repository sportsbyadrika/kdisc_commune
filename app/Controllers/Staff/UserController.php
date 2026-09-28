<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Enums\StaffRole;
use App\Models\StaffUser;
use App\Services\Staff\StaffRuleException;
use App\Services\Staff\StaffUserService;

/**
 * Staff user management (/staff/users, ability staff.manage — Centre Manager, State Admin). Rules live in
 * StaffUserService: who may manage which role, self / last-manager protection, invite + reset links by email only.
 */
final class UserController extends StaffController
{
    public function __construct(private readonly StaffUserService $staff)
    {
    }

    public function index(Request $request): Response
    {
        $filters = ['q' => mb_substr($request->string('q'), 0, 100), 'role' => $request->string('role'), 'status' => $request->string('status')];
        $actorRole = StaffRole::from((string) $this->user()['role']);
        return $this->view('staff/users/index', [
            'title' => 'Staff users',
            'subtitle' => 'Invite colleagues, change roles, deactivate leavers and send password reset links.',
            'users' => $this->staff->list($filters),
            'filters' => $filters,
            'counts' => $this->staff->activeCounts(),
            'manageable' => array_map(static fn (StaffRole $r) => $r->value, StaffUserService::manageableRoles($actorRole)),
            'me' => $this->staffId(),
        ]);
    }

    public function create(): Response
    {
        return $this->form(['name' => '', 'email' => '', 'mobile' => '', 'role' => StaffRole::Receptionist->value]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, $this->staff->rules($this->user()), [], ['mobile' => 'mobile number']);
        try {
            $id = $this->staff->create($data, $this->user());
        } catch (StaffRuleException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
        return redirect(url('staff.users.index'))->with('success', sprintf('%s added. An invite to set a password was emailed to %s.', $data['name'], mb_strtolower((string) $data['email'])))->with('highlight', $id);
    }

    public function edit(int $id): Response
    {
        return $this->form($this->find($id));
    }

    public function update(Request $request, int $id): Response
    {
        $this->find($id);
        $data = $this->validate($request, $this->staff->rules($this->user(), $id), [], ['mobile' => 'mobile number']);
        try {
            $this->staff->update($id, $data, $this->user());
        } catch (StaffRuleException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
        return redirect(url('staff.users.index'))->with('success', sprintf('%s updated.', $data['name']));
    }

    public function deactivate(Request $request, int $id): Response
    {
        $user = $this->find($id);
        try {
            $this->staff->setActive($id, false, $this->user(), mb_substr($request->string('reason'), 0, 500) ?: null);
        } catch (StaffRuleException $e) {
            return redirect(url('staff.users.index'))->with('error', $e->getMessage());
        }
        return redirect(url('staff.users.index'))->with('success', sprintf('%s is deactivated and signed out everywhere. History stays.', $user['name']));
    }

    public function reactivate(int $id): Response
    {
        $user = $this->find($id);
        try {
            $this->staff->setActive($id, true, $this->user());
        } catch (StaffRuleException $e) {
            return redirect(url('staff.users.index'))->with('error', $e->getMessage());
        }
        return redirect(url('staff.users.index'))->with('success', sprintf('%s can sign in again.', $user['name']));
    }

    public function sendLink(int $id): Response
    {
        $user = $this->find($id);
        if (!StaffUserService::canManage($this->user(), StaffRole::from((string) $user['role']))) {
            return redirect(url('staff.users.index'))->with('error', 'You cannot manage this account.');
        }
        if ((int) $user['is_active'] !== 1) {
            return redirect(url('staff.users.index'))->with('warning', 'Reactivate the account first.');
        }
        $sent = $this->staff->sendLink((array) StaffUser::find($id), null, $this->user());
        return redirect(url('staff.users.index'))->with($sent ? 'success' : 'warning', $sent
            ? sprintf('A single-use password link was emailed to %s.', $user['email'])
            : 'The email could not be sent — check the mail settings (MAIL_DSN) and try again.');
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        return $this->staff->find($id) ?? throw new NotFoundException('Staff user not found.');
    }

    /** @param array<string, mixed> $user */
    private function form(array $user): Response
    {
        $actorRole = StaffRole::from((string) $this->user()['role']);
        $roles = [];
        foreach (StaffUserService::manageableRoles($actorRole) as $role) {
            $roles[$role->value] = $role->label() . ' — ' . $role->description();
        }
        $editing = isset($user['id']);
        if ($editing && !isset($roles[(string) $user['role']])) {
            return redirect(url('staff.users.index'))->with('error', 'You cannot manage ' . StaffRole::from((string) $user['role'])->label() . ' accounts.');
        }
        return $this->view('staff/users/form', [
            'title' => $editing ? 'Edit ' . $user['name'] : 'Add staff user',
            'user' => $user,
            'roles' => $roles,
            'self' => $editing && (int) $user['id'] === $this->staffId(),
        ]);
    }
}
