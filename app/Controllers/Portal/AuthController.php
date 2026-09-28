<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Controllers\Controller;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Enums\AccountStatus;
use App\Enums\CustomerType;
use App\Enums\TokenPurpose;
use App\Models\Account;
use App\Models\Customer;
use App\Services\AuditLog;
use App\Services\Auth\Captcha;
use App\Services\Auth\LoginCaptcha;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\PasswordHasher;
use App\Services\Auth\PasswordTokenService;
use App\Services\Visitors\ProfileService;
use App\Services\Visitors\RegistrationService;

/**
 * Visitor accounts (spec 4.1 / 12): register → "set your password" email → set password (verifies the
 * email, logs in) → profile wizard. Login is throttled; forgot/resend never reveal whether an email exists.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly PasswordTokenService $tokens,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly Captcha $captcha,
        private readonly LoginCaptcha $loginCaptcha,
        private readonly AuditLog $audit,
    ) {
    }

    // ---------------------------------------------------------------- register

    public function showRegister(Request $request): Response
    {
        $type = CustomerType::tryFrom((string) $request->query('type', '')) ?? CustomerType::Individual;
        return $this->view('portal/auth/register', [
            'title' => 'Create your account',
            'captchaQuestion' => $this->captcha->question('register'),
            'defaultType' => $type,
        ]);
    }

    public function register(Request $request): Response
    {
        if (Captcha::isBot($request->input(Captcha::HONEYPOT))) {
            return redirect(url('portal.register.sent'));
        }
        $data = $this->validate($request, [
            'type' => ['required', CustomerType::rule()],
            'name' => 'required|string|min:2|max:150',
            'email' => 'required|email|max:190',
            'mobile' => 'required|mobile_in',
            'consent' => 'accepted',
            'captcha' => ['required', fn ($v) => $this->captcha->passes('register', $v) ?: 'That answer is not right — please try the new question.'],
        ], ['consent.accepted' => 'Please accept the terms and privacy policy to continue.'], ['mobile' => 'mobile number', 'captcha' => 'answer']);

        $email = mb_strtolower((string) $data['email']);
        $ip = $request->ip();
        if ($this->throttle->tooManyAttempts('register', $email, $ip)) {
            return back()->withErrors(['email' => 'Too many registration attempts from this network. Please try again in 15 minutes.'])->withInput();
        }
        $this->throttle->hit('register', $email, $ip);

        $this->registration->register([
            'name' => (string) $data['name'], 'email' => $email, 'mobile' => (string) $data['mobile'], 'type' => (string) $data['type'],
        ]);
        session()->flash('registered_email', $email);
        return redirect(url('portal.register.sent'));
    }

    public function registered(Session $session): Response
    {
        return $this->view('portal/auth/check-email', [
            'title' => 'Check your email',
            'email' => (string) $session->getFlash('registered_email', ''),
            'heading' => 'Check your inbox',
            'lead' => 'If this email can be used for a new account, we have sent a link to set your password. It works once and expires in ' . $this->tokens->lifetimeMinutes() . ' minutes.',
        ]);
    }

    // ---------------------------------------------------------------- set / reset password

    public function showSetPassword(string $token): Response
    {
        return $this->passwordForm($token, 'set');
    }

    public function showResetPassword(string $token): Response
    {
        return $this->passwordForm($token, 'reset');
    }

    public function setPassword(Request $request, string $token): Response
    {
        return $this->applyPassword($request, $token);
    }

    public function resetPassword(Request $request, string $token): Response
    {
        return $this->applyPassword($request, $token);
    }

    private function passwordForm(string $token, string $mode): Response
    {
        $row = $this->tokens->find($token);
        if ($row === null || $row['subject_type'] !== 'account') {
            return $this->view('portal/auth/link-invalid', [
                'title' => 'Link expired',
                'reason' => $this->tokens->failureReason($token),
            ], 410);
        }
        $account = (array) Account::find((int) $row['subject_id']);
        $customer = Customer::findByAccount((int) $account['id']);
        $purpose = TokenPurpose::from((string) $row['purpose']);
        return $this->view('portal/auth/set-password', [
            'title' => $purpose === TokenPurpose::Reset ? 'Reset password' : 'Set your password',
            'purpose' => $purpose,
            'email' => (string) $account['email'],
            'name' => (string) ($customer['name'] ?? ''),
            'action' => url($mode === 'reset' ? 'portal.password.reset.store' : 'portal.password.set.store', ['token' => $token]),
        ]);
    }

    private function applyPassword(Request $request, string $token): Response
    {
        $this->validate($request, ['password' => 'required|password|max:200|confirmed'], [], ['password' => 'password']);
        $row = $this->tokens->consume($token);
        if ($row === null || $row['subject_type'] !== 'account') {
            return $this->view('portal/auth/link-invalid', ['title' => 'Link expired', 'reason' => $this->tokens->failureReason($token)], 410);
        }
        $account = $this->registration->setPassword($row, (string) $request->input('password'));
        if ($account['status'] !== AccountStatus::Active->value) {
            return redirect(url('portal.login'))->with('error', 'Your account is not active. Please contact the front desk.');
        }
        App::guard('visitor')->login($account);
        Account::recordLogin((int) $account['id'], $request->ip());

        $customer = Customer::findByAccount((int) $account['id']);
        $firstTime = $row['purpose'] !== TokenPurpose::Reset->value;
        if ($customer !== null && (int) $customer['profile_step'] < 4) {
            return redirect(url('portal.wizard', ['step' => ProfileService::nextStep($customer)]))
                ->with('success', $firstTime ? 'Your email is verified and your password is set. Let’s complete your profile.' : 'Your password has been changed.');
        }
        return redirect(url('portal.dashboard'))->with('success', $firstTime ? 'Your password is set — welcome to Commune!' : 'Your password has been changed.');
    }

    // ---------------------------------------------------------------- forgot / resend

    public function showForgot(): Response
    {
        return $this->view('portal/auth/forgot', ['title' => 'Forgot password']);
    }

    public function sendReset(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|email|max:190']);
        $email = mb_strtolower((string) $data['email']);
        if (!$this->throttle->tooManyAttempts('pwlink', $email, $request->ip())) {
            $this->throttle->hit('pwlink', $email, $request->ip());
            $this->registration->forgot($email);
        }
        session()->flash('registered_email', $email);
        return redirect(url('portal.password.sent'));
    }

    public function resend(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|email|max:190']);
        $email = mb_strtolower((string) $data['email']);
        if (!$this->throttle->tooManyAttempts('pwlink', $email, $request->ip())) {
            $this->throttle->hit('pwlink', $email, $request->ip());
            $this->registration->resendSetLink($email);
        }
        session()->flash('registered_email', $email);
        return redirect(url('portal.password.sent'));
    }

    public function linkSent(Session $session): Response
    {
        return $this->view('portal/auth/check-email', [
            'title' => 'Check your email',
            'email' => (string) $session->getFlash('registered_email', ''),
            'heading' => 'Check your inbox',
            'lead' => 'If an account exists for this email, we have sent a link to choose a new password. It works once and expires in ' . $this->tokens->lifetimeMinutes() . ' minutes.',
        ]);
    }

    // ---------------------------------------------------------------- login / logout

    public function showLogin(Request $request, Session $session): Response
    {
        // ?next=/spaces/explore/… (Space Explorer "sign in to pick seats") — same-site paths only.
        $next = $request->string('next');
        if ($next !== '' && self::safeNext($next, $request->basePath())) {
            $session->put('_intended', $next);
        }
        return $this->view('portal/auth/login', ['title' => 'Sign in', 'captchaQuestion' => $this->loginCaptcha->question('visitor')]);
    }

    /** Post-login redirect targets: the portal and the Space Explorer only (no open redirects). */
    private static function safeNext(string $path, string $base): bool
    {
        return preg_match('#^/[A-Za-z0-9/_\-?=&.%]*$#', $path) === 1
            && \App\Support\SafeRedirect::path($path, ['/my', '/spaces'], $base) !== null;
    }

    public function login(Request $request, Session $session): Response
    {
        $data = $this->validate($request, ['email' => 'required|email|max:190', 'password' => 'required|string|max:200']);
        $email = mb_strtolower((string) $data['email']);
        $ip = $request->ip();

        $wait = $this->throttle->availableIn('visitor', $email, $ip);
        if ($wait > 0) {
            return back()->withErrors(['email' => sprintf('Too many sign-in attempts. Please try again in %d minute(s).', (int) ceil($wait / 60))])->withInput();
        }
        if ($this->loginCaptcha->required('visitor', $email, $ip) && !$this->loginCaptcha->passes('visitor', $request->input('captcha'))) {
            $this->throttle->hit('visitor', $email, $ip);
            return back()->withErrors(['captcha' => 'Please answer the question to continue.'])->withInput();
        }
        $account = Account::findByEmail($email);
        $password = (string) $request->input('password');
        if ($account === null || $account['password_hash'] === null) {
            $this->hasher->dummyVerify($password);
        }
        if ($account === null || !$this->hasher->verify($password, $account['password_hash'] !== null ? (string) $account['password_hash'] : null)) {
            $this->throttle->hit('visitor', $email, $ip);
            $this->loginCaptcha->failed('visitor', $email, $ip);
            return back()->withErrors(['email' => 'These credentials do not match our records. New here, or never set a password? Use “Forgot password”.'])->withInput();
        }
        if ($account['status'] !== AccountStatus::Active->value) {
            return back()->withErrors(['email' => 'This account is suspended. Please contact the front desk.'])->withInput();
        }
        if ($this->hasher->needsRehash((string) $account['password_hash'])) {
            Account::update((int) $account['id'], ['password_hash' => $this->hasher->hash($password)]);
        }
        $this->throttle->clear('visitor', $email, $ip);
        $this->loginCaptcha->clear('visitor');
        App::guard('visitor')->login($account);
        Account::recordLogin((int) $account['id'], $ip);
        $this->audit->record('account.login', 'account', (int) $account['id'], actorType: 'account', actorId: (int) $account['id']);

        $intended = $session->pull('_intended');
        if (is_string($intended) && self::safeNext($intended, $request->basePath())) {
            return redirect($intended);
        }
        $customer = Customer::findByAccount((int) $account['id']);
        if ($customer !== null && (int) $customer['profile_step'] < 4) {
            return redirect(url('portal.wizard', ['step' => ProfileService::nextStep($customer)]))->with('info', 'Welcome back! Pick up where you left off.');
        }
        return redirect(url('portal.dashboard'));
    }

    public function logout(): Response
    {
        $guard = App::guard('visitor');
        if ($guard->check()) {
            $this->audit->record('account.logout', 'account', $guard->id(), actorType: 'account', actorId: $guard->id());
        }
        $guard->logout();
        return redirect(url('home'))->with('success', 'You have been signed out.');
    }
}
