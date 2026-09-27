<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Session;

/**
 * Lightweight bot protection for public forms (spec 12): a simple arithmetic question stored in the
 * session plus a honeypot field that humans never see. No third-party service, works without JS.
 *
 *   $question = $captcha->question('register');                 // "What is 4 + 7?"
 *   $captcha->passes('register', $request->input('captcha'))   // true once, then a new question is needed
 *   Captcha::HONEYPOT                                           // hidden field name; must stay empty
 */
final class Captcha
{
    public const HONEYPOT = 'website';

    public function __construct(private readonly Session $session)
    {
    }

    public function question(string $form): string
    {
        $a = random_int(2, 9);
        $b = random_int(1, 9);
        $plus = random_int(0, 1) === 1 || $a <= $b;
        $this->session->put('_captcha_' . $form, $plus ? $a + $b : $a - $b);
        return sprintf('What is %d %s %d?', $a, $plus ? '+' : '−', $b);
    }

    public function passes(string $form, mixed $answer): bool
    {
        $expected = $this->session->pull('_captcha_' . $form);
        return is_int($expected) && is_scalar($answer) && trim((string) $answer) !== '' && (int) trim((string) $answer) === $expected;
    }

    /** True when the hidden honeypot field was filled in (a bot). */
    public static function isBot(mixed $honeypot): bool
    {
        return is_string($honeypot) && trim($honeypot) !== '';
    }
}
