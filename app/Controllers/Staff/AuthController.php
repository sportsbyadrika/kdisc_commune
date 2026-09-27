<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\StaffUser;
use App\Services\AuditLog;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\PasswordHasher;

/** Staff sign-in / sign-out (Argon2id, throttled, session id regenerated on login). */
final class AuthController extends Controller
{
    public function __construct(
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly AuditLog $audit,
    ) {
    }

    public function showLogin(): Response
    {
        return $this->view('staff/auth/login', ['title' => 'Staff sign in']);
    }

    public function login(Request $request, Session $session): Response
    {
        $data = $this->validate($request, [
            'email' => 'required|email|max:190',
            'password' => 'required|string|max:200',
        ]);
        $email = mb_strtolower($data['email']);
        $ip = $request->ip();

        $wait = $this->throttle->availableIn('staff', $email, $ip);
        if ($wait > 0) {
            return back()->withErrors(['email' => sprintf('Too many sign-in attempts. Please try again in %d minute(s).', (int) ceil($wait / 60))])->withInput();
        }

        $user = StaffUser::findByEmail($email);
        $password = (string) $request->input('password');
        if ($user === null) {
            $this->hasher->dummyVerify($password);
        }
        if ($user === null || !$this->hasher->verify($password, (string) $user['password_hash']) || (int) $user['is_active'] !== 1) {
            $this->throttle->hit('staff', $email, $ip);
            return back()->withErrors(['email' => 'These credentials do not match an active staff account.'])->withInput();
        }

        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            StaffUser::update((int) $user['id'], ['password_hash' => $this->hasher->hash($password)]);
        }
        $this->throttle->clear('staff', $email, $ip);
        App::guard('staff')->login($user);   // regenerates the session id
        StaffUser::recordLogin((int) $user['id'], $ip);
        $this->audit->record('staff.login', 'staff_user', (int) $user['id'], actorType: 'staff', actorId: (int) $user['id']);

        $intended = $session->pull('_intended');
        $target = is_string($intended) && str_starts_with($intended, $request->basePath() . '/staff') ? $intended : url('staff.dashboard');
        return redirect($target)->with('success', 'Welcome back, ' . explode(' ', (string) $user['name'])[0] . '!');
    }

    public function logout(): Response
    {
        $guard = App::guard('staff');
        if ($guard->check()) {
            $this->audit->record('staff.logout', 'staff_user', $guard->id());
        }
        $guard->logout();
        return redirect(url('staff.login'))->with('success', 'You have been signed out.');
    }
}
