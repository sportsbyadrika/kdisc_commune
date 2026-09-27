<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Minimal service container with constructor autowiring.
 *
 *   $c->singleton(Database::class, fn (Container $c) => new Database(...));
 *   $c->get(SomeService::class);            // autowired if not bound
 *   $c->call([$controller, 'show'], ['id' => '5']);
 */
final class Container
{
    private static ?Container $instance = null;

    /** @var array<string, array{factory: Closure, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(?self $container): void
    {
        self::$instance = $container;
    }

    public function bind(string $id, Closure $factory): void
    {
        $this->bindings[$id] = ['factory' => $factory, 'shared' => false];
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->bindings[$id] = ['factory' => $factory, 'shared' => true];
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    /** Drop a resolved instance (e.g. per-request state such as auth guards). */
    public function forget(string $id): void
    {
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->bindings[$id])) {
            $object = ($this->bindings[$id]['factory'])($this);
            if ($this->bindings[$id]['shared']) {
                $this->instances[$id] = $object;
            }
            return $object;
        }
        if (class_exists($id)) {
            return $this->build($id);
        }
        throw new RuntimeException("Container cannot resolve [{$id}].");
    }

    /**
     * Instantiate a class, resolving typed constructor parameters from the container.
     *
     * @param array<string, mixed> $params named overrides
     */
    public function build(string $class, array $params = []): object
    {
        $ref = new ReflectionClass($class);
        if (!$ref->isInstantiable()) {
            throw new RuntimeException("Class [{$class}] is not instantiable.");
        }
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return new $class();
        }
        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $args[] = $this->resolveParameter($p, $params, $class);
        }
        return $ref->newInstanceArgs($args);
    }

    /**
     * Call a callable, injecting typed dependencies and named parameters.
     *
     * @param callable|array{0: object|string, 1: string} $callable
     * @param array<string, mixed> $params
     */
    public function call(callable|array $callable, array $params = []): mixed
    {
        if (is_array($callable)) {
            [$target, $method] = $callable;
            $object = is_string($target) ? $this->get($target) : $target;
            $ref = new \ReflectionMethod($object, $method);
            $args = [];
            foreach ($ref->getParameters() as $p) {
                $args[] = $this->resolveParameter($p, $params, $ref->class . '::' . $method);
            }
            return $ref->invokeArgs($object, $args);
        }
        $ref = new \ReflectionFunction(Closure::fromCallable($callable));
        $args = [];
        foreach ($ref->getParameters() as $p) {
            $args[] = $this->resolveParameter($p, $params, 'closure');
        }
        return $ref->invokeArgs($args);
    }

    /** @param array<string, mixed> $params */
    private function resolveParameter(\ReflectionParameter $p, array $params, string $context): mixed
    {
        $name = $p->getName();
        $type = $p->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            foreach ($params as $value) {
                if (is_object($value) && is_a($value, $type->getName())) {
                    return $value;
                }
            }
            if ($this->has($type->getName()) || class_exists($type->getName())) {
                return $this->get($type->getName());
            }
        }

        if (array_key_exists($name, $params)) {
            $value = $params[$name];
            if ($type instanceof ReflectionNamedType && $type->isBuiltin() && is_string($value)) {
                return match ($type->getName()) {
                    'int' => (int) $value,
                    'float' => (float) $value,
                    'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                    default => $value,
                };
            }
            return $value;
        }
        if ($p->isDefaultValueAvailable()) {
            return $p->getDefaultValue();
        }
        if ($p->allowsNull()) {
            return null;
        }
        throw new RuntimeException("Unresolvable parameter \${$name} in {$context}.");
    }
}
