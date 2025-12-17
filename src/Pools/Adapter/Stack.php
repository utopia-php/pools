<?php

namespace Utopia\Pools\Adapter;

use Utopia\Pools\Adapter;

class Stack extends Adapter
{
    /** @var array<mixed> $pool */
    protected array $pool = [];

    public function fill(int $size, mixed $value): static
    {
        // Initialize empty pool (no pre-filling)
        $this->pool = [];
        return $this;
    }

    public function push(mixed $connection): static
    {
        // Push connection to pool
        $this->pool[] = $connection;
        return $this;
    }

    /**
     * @param int $timeout Stack adapter will ignore timeout
     * @return mixed
     */
    public function pop(int $timeout): mixed
    {
        return array_pop($this->pool);
    }

    public function count(): int
    {
        return count($this->pool);
    }

    /**
     * No lock applied and just the callback is executed
     * @param callable $callback
     * @param int $timeout
     * @return mixed
     */
    public function withLock(callable $callback, int $timeout): mixed
    {
        return $callback();
    }
}
