<?php

declare(strict_types=1);

namespace App\Services\Imports;

use RuntimeException;

/** A whole upload / batch is refused (wrong file, missing headers, expired, already imported…). */
final class ImportException extends RuntimeException
{
}
