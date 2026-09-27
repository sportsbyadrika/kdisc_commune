<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class MethodNotAllowedException extends HttpException
{
    /** @param list<string> $allowed */
    public function __construct(array $allowed)
    {
        parent::__construct(405, 'Method not allowed', ['Allow' => implode(', ', $allowed)]);
    }
}
