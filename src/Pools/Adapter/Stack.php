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
     * Pop an item from the stack.
     *
     * Note: The stack adapter does not support blocking operations.
     * The `$timeout` parameter is ignored.
     *
     * @param int $timeout Ignored by the stack adapter.
     * @return mixed|null Returns the popped item, or null if the stack is empty.
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
     * Executes the callback without acquiring a lock.
     *
     * This implementation does not provide mutual exclusion.
     * The `$timeout` parameter is ignored.
     *
     * @param callable $callback Callback to execute.
     * @param int $timeout Ignored.
     * @return mixed The value returned by the callback.
     */
    public function withLock(callable $callback, int $timeout): mixed
    {
        return $callback();
    }
}
