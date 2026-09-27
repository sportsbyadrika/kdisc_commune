<?php

declare(strict_types=1);

namespace App\Services\Layout;

/**
 * A Designer rule violation. status 422 = invalid input, 409 = conflict (draft changed elsewhere / not a draft).
 * The JSON API returns {message, ...payload} with the status.
 */
final class LayoutException extends \RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(string $message, public readonly int $status = 422, public readonly array $payload = [])
    {
        parent::__construct($message);
    }
}
