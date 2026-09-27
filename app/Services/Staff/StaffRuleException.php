<?php

declare(strict_types=1);

namespace App\Services\Staff;

/** A staff-management rule was broken (shown to the manager as a flash message). */
final class StaffRuleException extends \RuntimeException
{
}
