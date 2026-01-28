<?php

namespace Utopia\Pools\Adapter;

use Utopia\Pools\Adapter;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Lock;

class Swoole extends Adapter
{
    protected Channel $pool;

    protected Lock $lock;
    public function initialize(int $size): static
    {

        $this->pool = new Channel($size);
        $this->lock = new Lock();

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
     * @return mixed The value returned by the callback.
     *
     * @throws \RuntimeException If the lock cannot be acquired within the timeout.
    */
    public function synchronized(callable $callback): mixed
    {
        $acquired = $this->lock->lock();

        if (!$acquired) {
            throw new \RuntimeException("Failed to acquire lock");
        }

        try {
            return $callback();
        } finally {
            $this->lock->unlock();
        }
    }
}
