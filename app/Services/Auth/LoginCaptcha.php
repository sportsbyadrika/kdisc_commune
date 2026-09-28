<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Session;

/**
 * Sign-in captcha (spec 12): after setting('login_captcha_after', 3) failed attempts for the same email from the
 * same IP — or 3× that many from one IP across emails (credential stuffing) — the sign-in form also asks the math
 * question from registration (Captcha). The requirement is remembered in the session and re-derived from
 * login_attempts on every POST, so dropping the cookie does not skip it. LoginThrottle still locks the account
 * + IP out after 5 failures; the captcha slows scripted guessing before that and across many emails.
 *
 *   if ($captcha->required('staff', $email, $ip) && !$captcha->passes('staff', $answer)) { … }
 *   $question = $captcha->question('staff');   // null when not needed
 */
final class LoginCaptcha
{
    public function __construct(
        private readonly Captcha $captcha,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
    ) {
    }

    public static function threshold(): int
    {
        try {
            return max(1, (int) setting('login_captcha_after', 3));
        } catch (\Throwable) {
            return 3;
        }
    }

    /** Does this sign-in attempt need the captcha? */
    public function required(string $guard, string $email, string $ip): bool
    {
        if ($this->session->get('_login_captcha_' . $guard) === true) {
            return true;
        }
        $n = $this->throttle->failures($guard, $email, $ip);
        $need = $n['email'] >= self::threshold() || $n['ip'] >= self::threshold() * 3;
        if ($need) {
            $this->session->put('_login_captcha_' . $guard, true);
        }
        return $need;
    }

    /** Called after a failed attempt: switch the captcha on once the threshold is reached. */
    public function failed(string $guard, string $email, string $ip): void
    {
        $this->required($guard, $email, $ip);
    }

    /** A fresh question for the form, or null when the form needs no captcha. */
    public function question(string $guard): ?string
    {
        return $this->session->get('_login_captcha_' . $guard) === true ? $this->captcha->question('login_' . $guard) : null;
    }

    public function passes(string $guard, mixed $answer): bool
    {
        return $this->captcha->passes('login_' . $guard, $answer);
    }

    public function clear(string $guard): void
    {
        $this->session->forget('_login_captcha_' . $guard);
    }
}
