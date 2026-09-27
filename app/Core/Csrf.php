<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchronizer-token CSRF protection. Every non-GET request must carry the
 * token in the `_token` field or the `X-CSRF-TOKEN` header.
 *
 *   <form method="post"> <?= csrf_field() ?> ... </form>
 *   fetch(url, { headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } })
 */
final class Csrf
{
    public const FIELD = '_token';
    private const KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->put(self::KEY, $token);
        }
        return $token;
    }

    public function validate(?string $token): bool
    {
        $expected = $this->session->get(self::KEY);
        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    public function regenerate(): string
    {
        $this->session->forget(self::KEY);
        return $this->token();
    }

    public function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . $this->token() . '">';
    }
}
