<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Page not found')
    {
        parent::__construct(404, $message);
    }
}
