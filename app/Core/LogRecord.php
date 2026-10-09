<?php

declare(strict_types=1);

namespace App\Core;

/** One log entry as passed to Logger::listen() listeners (already redacted and interpolated). */
final class LogRecord
{
    /** @param array<mixed> $context */
    public function __construct(
        public readonly int $level,
        public readonly string $levelName,
        public readonly string $message,
        public readonly array $context,
        public readonly string $channel,
        public readonly \DateTimeImmutable $datetime,
    ) {
    }
}
