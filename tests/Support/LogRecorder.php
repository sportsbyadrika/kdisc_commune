<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\LogRecord;

/** Collects App\Core\Logger records in memory: $logger->listen($recorder). */
final class LogRecorder
{
    /** @var list<LogRecord> */
    private array $records = [];

    public function __invoke(LogRecord $record): void
    {
        $this->records[] = $record;
    }

    /** @return list<LogRecord> */
    public function getRecords(): array
    {
        return $this->records;
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
