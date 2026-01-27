<?php

namespace Utopia\Pools\Adapter;

use Utopia\Pools\Adapter;
use Swoole\Coroutine\Channel;

class Swoole extends Adapter
{
    protected Channel $pool;

    protected Channel $lock;
    public function initialize(int $size): static
    {
        $this->pool = new Channel($size);

        // using a coroutine mutex instead of lock as lock is process based and acquires os level lock
        // result will be all the coroutines will be blocked(http, realtime running on the same event loop)s
        // coroutine is coroutine safe
        $this->lock = new Channel(1);
        $this->lock->push(true);

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
    public function synchronized(callable $callback, int $timeout): mixed
    {
        $acquired = $this->lock->pop($timeout);

        if (!$acquired) {
            throw new \RuntimeException("Failed to acquire lock within {$timeout} seconds");
        }

        try {
            return $callback();
        } finally {
            $this->lock->push(true, $timeout);
        }
    }
}
