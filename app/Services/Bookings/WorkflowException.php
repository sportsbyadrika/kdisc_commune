<?php

declare(strict_types=1);

namespace App\Services\Bookings;

use RuntimeException;

/**
 * A booking action that is not allowed right now (wrong state, missing ability, KYC not verified, payment
 * requirement not met…). Controllers show getMessage() and, when set, a link (e.g. "Verify KYC first").
 *
 * kind: state | permission | kyc | payment | seats | input
 */
final class WorkflowException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'state', public readonly ?string $link = null, public readonly ?string $linkLabel = null)
    {
        parent::__construct($message);
    }
}
