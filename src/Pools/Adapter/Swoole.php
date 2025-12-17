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

    public function pop(int $timeout): mixed
    {
        $result = $this->pool->pop($timeout);

        // if pool is empty or timeout occured => result will be false
        return $result;
    }


    public function count(): int
    {
        return (int) $this->pool->length();
    }
}
