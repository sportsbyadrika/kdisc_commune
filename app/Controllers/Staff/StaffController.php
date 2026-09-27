<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFoundException;
use App\Enums\StaffRole;
use App\Models\Customer;

/** Base for authenticated staff controllers. */
abstract class StaffController extends Controller
{
    /** @return array<string, mixed> */
    protected function user(): array
    {
        return staff() ?? [];
    }

    protected function staffId(): int
    {
        return (int) ($this->user()['id'] ?? 0);
    }

    protected function can(string $ability): bool
    {
        return StaffRole::tryFrom((string) ($this->user()['role'] ?? ''))?->can($ability) ?? false;
    }

    /** @return array<string, mixed> customer by Unique Visitor ID or numeric id */
    protected function findCustomer(string $ref): array
    {
        return Customer::findByRef($ref) ?? throw new NotFoundException('Visitor not found.');
    }
}
