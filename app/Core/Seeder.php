<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base class for database/seeds/*Seeder.php (namespace Database\Seeds).
 * Seeders should be idempotent where practical (INSERT ... ON DUPLICATE KEY / existence checks)
 * and are run by `php bin/console db:seed [--class=Name]`.
 */
abstract class Seeder
{
    /** @var callable(string): void */
    protected $output;

    public function __construct(protected readonly Database $db, ?callable $output = null)
    {
        $this->output = $output ?? static function (string $line): void {
        };
    }

    abstract public function run(): void;

    /** @param class-string<Seeder> ...$seeders */
    protected function call(string ...$seeders): void
    {
        foreach ($seeders as $class) {
            $start = microtime(true);
            (new $class($this->db, $this->output))->run();
            ($this->output)(sprintf('Seeded    %s (%.0f ms)', (new \ReflectionClass($class))->getShortName(), (microtime(true) - $start) * 1000));
        }
    }

    protected function info(string $line): void
    {
        ($this->output)($line);
    }
}
