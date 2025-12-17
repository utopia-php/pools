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
     * Execute a callback with lock protection
     *
     * @param callable $callback
     * @param float $timeout Timeout in seconds
     * @return mixed
     */
    abstract public function withLock(callable $callback, int $timeout): mixed;
}
