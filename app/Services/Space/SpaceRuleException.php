<?php

declare(strict_types=1);

namespace App\Services\Space;

/** A booking/selection rule was broken (wrong category mix, too many seats, bad slot …). Shown to the user as-is (HTTP 422). */
final class SpaceRuleException extends \RuntimeException
{
}
