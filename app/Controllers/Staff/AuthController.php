<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Enums\HolderType;
use App\Enums\TokenPurpose;
use App\Models\StaffUser;
use App\Services\AuditLog;
use App\Services\Auth\LoginCaptcha;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\PasswordHasher;
use App\Services\Auth\PasswordTokenService;
use App\Services\Staff\StaffRuleException;
use App\Services\Staff\StaffUserService;
use App\Support\SafeRedirect;

/**
 * Staff sign-in / sign-out (Argon2id, throttled, captcha after repeated failures, session id regenerated on
 * login) and the staff password links: "Forgot password" and the invite / reset link (/staff/password/reset/{token}).
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly LoginCaptcha $captcha,
        private readonly AuditLog $audit,
    ) {
    }

    public function showLogin(): Response
    {
        return $this->view('staff/auth/login', ['title' => 'Staff sign in', 'captchaQuestion' => $this->captcha->question('staff')]);
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
            return redirect(url('staff.login'))->withErrors(['email' => sprintf('Too many sign-in attempts. Please try again in %d minute(s).', (int) ceil($wait / 60))])->withInput();
        }
        if ($this->captcha->required('staff', $email, $ip) && !$this->captcha->passes('staff', $request->input('captcha'))) {
            $this->throttle->hit('staff', $email, $ip);
            return redirect(url('staff.login'))->withErrors(['captcha' => 'Please answer the question to continue.'])->withInput();
        }

        $user = StaffUser::findByEmail($email);
        $password = (string) $request->input('password');
        if ($user === null) {
            $this->hasher->dummyVerify($password);
        }
        if ($user === null || !$this->hasher->verify($password, (string) $user['password_hash']) || (int) $user['is_active'] !== 1) {
            $this->throttle->hit('staff', $email, $ip);
            $this->captcha->failed('staff', $email, $ip);
            return redirect(url('staff.login'))->withErrors(['email' => 'These credentials do not match an active staff account.'])->withInput();
        }

        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            StaffUser::update((int) $user['id'], ['password_hash' => $this->hasher->hash($password)]);
        }
        $this->throttle->clear('staff', $email, $ip);
        $this->captcha->clear('staff');
        $intended = $session->pull('_intended');
        App::guard('staff')->login($user);   // regenerates the session id
        StaffUser::recordLogin((int) $user['id'], $ip);
        $this->audit->record('staff.login', 'staff_user', (int) $user['id'], actorType: 'staff', actorId: (int) $user['id']);

        $target = SafeRedirect::path($intended, ['/staff/'], $request->basePath()) ?? url('staff.dashboard');
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

    // ---------------------------------------------------------------- forgot / reset

    public function showForgot(): Response
    {
        return $this->view('staff/auth/forgot', ['title' => 'Forgot password']);
    }

    public function sendReset(Request $request, StaffUserService $staff, PasswordTokenService $tokens): Response
    {
        $data = $this->validate($request, ['email' => 'required|email|max:190']);
        $email = mb_strtolower((string) $data['email']);
        if (!$this->throttle->tooManyAttempts('staffpw', $email, $request->ip())) {
            $this->throttle->hit('staffpw', $email, $request->ip());
            $staff->forgot($email);
        }
        return redirect(url('staff.login'))->with('success', 'If that email belongs to an active staff account, a reset link is on its way. It works once and expires in ' . $tokens->lifetimeMinutes() . ' minutes.');
    }

    public function showReset(string $token, PasswordTokenService $tokens): Response
    {
        $row = $tokens->find($token);
        if ($row === null || $row['subject_type'] !== HolderType::Staff->value) {
            return $this->invalid($tokens->failureReason($token));
        }
        $user = (array) StaffUser::find((int) $row['subject_id']);
        $purpose = TokenPurpose::from((string) $row['purpose']);
        return $this->noReferrer($this->view('staff/auth/reset', [
            'title' => $purpose === TokenPurpose::Invite ? 'Set your password' : 'Reset password',
            'invite' => $purpose === TokenPurpose::Invite,
            'email' => (string) ($user['email'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'action' => url('staff.password.update', ['token' => $token]),
        ]));
    }

    public function reset(Request $request, string $token, PasswordTokenService $tokens, StaffUserService $staff): Response
    {
        $this->validate($request, ['password' => 'required|password|max:200|confirmed'], [], ['password' => 'password']);
        $row = $tokens->consume($token);
        if ($row === null || $row['subject_type'] !== HolderType::Staff->value) {
            return $this->invalid($tokens->failureReason($token));
        }
        try {
            $staff->setPassword($row, (string) $request->input('password'));
        } catch (StaffRuleException $e) {
            return redirect(url('staff.login'))->with('error', $e->getMessage());
        }
        // Any existing staff session in this browser belongs to someone else or is now stale.
        App::guard('staff')->logout();
        return redirect(url('staff.login'))->with('success', 'Your password is saved. Sign in with your new password.');
    }

    private function invalid(string $reason): Response
    {
        return $this->noReferrer($this->view('staff/auth/link-invalid', ['title' => 'Link expired', 'reason' => $reason], 410));
    }

    /** Token pages never leak the token through the Referer header. */
    private function noReferrer(Response $response): Response
    {
        return $response->header('Referrer-Policy', 'no-referrer');
    }
}
