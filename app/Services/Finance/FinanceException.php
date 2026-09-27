<?php

declare(strict_types=1);

namespace App\Services\Finance;

use RuntimeException;

/** A finance rule refused the action (already invoiced, over-credit, not verified …). Controllers flash it. */
final class FinanceException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'state')
    {
        parent::__construct($message);
    }
}
