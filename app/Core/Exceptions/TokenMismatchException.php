<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class TokenMismatchException extends HttpException
{
    public function __construct()
    {
        parent::__construct(419, 'Your session has expired. Please refresh the page and try again.');
    }
}
