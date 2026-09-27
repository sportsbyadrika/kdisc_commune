<?php

declare(strict_types=1);

namespace App\Controllers\Api;

/** Internal signal: the action needs a signed-in visitor/staff user (rendered as 401 JSON with sign-in links). */
final class GuestException extends \RuntimeException
{
}
