<?php

namespace Utopia\Pools;

abstract class Adapter
{
    abstract public function initialize(int $size): static;

    abstract public function push(mixed $connection): static;

    /**
     * @param int $timeout
     * @return mixed
     */
    abstract public function pop(int $timeout): mixed;

    abstract public function count(): int;

    /**
     * Execute a callback with lock protection if the adapter supports it
     *
     * @param callable $callback
     * @return mixed
     */
    abstract public function synchronized(callable $callback): mixed;
}
