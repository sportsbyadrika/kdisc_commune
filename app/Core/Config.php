<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Loads every PHP file in config/ into a dot-notation repository.
 *   config('app.name'), config('database.connections.mysql.host')
 */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = [])
    {
    }

    public static function fromDirectory(string $dir): self
    {
        $items = [];
        foreach (glob(rtrim($dir, '/') . '/*.php') ?: [] as $file) {
            $items[basename($file, '.php')] = require $file;
        }
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $ref = &$this->items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
