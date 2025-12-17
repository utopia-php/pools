<?php

namespace Utopia\Pools\Adapter;

use Utopia\Pools\Adapter;
use Swoole\Coroutine\Channel;
use Swoole\Lock;

class Swoole extends Adapter
{
    protected Channel $pool;

    /** @var Lock $lock */
    protected Lock $lock;
    public function fill(int $size, mixed $value): static
    {
        // Create empty channel with capacity (no pre-filling)
        $this->pool = new Channel($size);

        // Initialize lock for thread-safe operations
        $this->lock = new Lock(SWOOLE_MUTEX);

        return $this;
    }

    public function push(mixed $connection): static
    {
        // Push connection to channel
        $this->pool->push($connection);
        return $this;
    }

    /**
     * Pop an item from the pool.
     *
     * @param int $timeout Timeout in seconds. Use 0 for non-blocking pop.
     * @return mixed|false Returns the pooled value, or false if the pool is empty
     *                     or the timeout expires.
     */
    public function pop(int $timeout): mixed
    {
        return $this->pool->pop($timeout);
    }

    public function count(): int
    {
        $length = $this->pool->length();
        return is_int($length) ? $length : 0;
    }

    /**
     * Executes a callback while holding a lock.
     *
     * The lock is acquired before invoking the callback and is always released
     * afterward, even if the callback throws an exception.
     *
     * @param callable $callback Callback to execute within the critical section.
     * @param int $timeout Maximum time (in seconds) to wait for the lock.
     * @return mixed The value returned by the callback.
     *
     * @throws \RuntimeException If the lock cannot be acquired within the timeout.
    */
    public function withLock(callable $callback, int $timeout): mixed
    {
        $acquired = $this->lock->lockwait($timeout);

        if (!$acquired) {
            throw new \RuntimeException("Failed to acquire lock within {$timeout} seconds");
        }

        try {
            return $callback();
        } finally {
            $this->lock->unlock();
        }
    }
}
