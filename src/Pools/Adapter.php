<?php

namespace Utopia\Pools;

abstract class Adapter
{
    abstract public function fill(int $size, mixed $value): static;

    abstract public function push(mixed $connection): static;

    /**
     * @param int $timeout
     * @return mixed
     */
    abstract public function pop(int $timeout): mixed;

    abstract public function count(): int;

    /**
     * @param callable(mixed): mixed $callback
     * @return mixed
     */
    abstract public function run(callable $callback): mixed;
}
