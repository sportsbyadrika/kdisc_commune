<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Controllers\Controller;
use App\Core\App;
use App\Core\Exceptions\NotFoundException;
use App\Models\Customer;

/** Base for /my/* pages: every query is scoped to the signed-in visitor's own customer row. */
abstract class PortalController extends Controller
{
    protected function accountId(): int
    {
        return (int) App::guard('visitor')->id();
    }

    /** @return array<string, mixed> the signed-in visitor's customer row (with secrets) */
    protected function customer(): array
    {
        return Customer::findByAccount($this->accountId()) ?? throw new NotFoundException('No visitor profile is linked to this account.');
    }

    /** @return array<string, mixed> */
    protected function account(): array
    {
        return App::guard('visitor')->user() ?? [];
    }
}
