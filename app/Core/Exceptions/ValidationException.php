<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * Thrown by Validator::validate(). ErrorHandler turns it into a redirect back
 * (errors + old input flashed) or a 422 JSON response for XHR/JSON requests.
 */
final class ValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private readonly array $errors, private readonly ?string $redirectTo = null)
    {
        parent::__construct('The given data was invalid.');
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function redirectTo(): ?string
    {
        return $this->redirectTo;
    }
}
