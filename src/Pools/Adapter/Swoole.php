<?php

namespace Utopia\Pools\Adapter;

use Utopia\Pools\Adapter;
use Swoole\Coroutine\Channel;

class Swoole extends Adapter
{
    /** @var Channel<mixed> $pool */
    protected Channel $pool;
    public function fill(int $size, mixed $value): static
    {
        $this->pool = new Channel($size);
        for ($i = 0; $i < $size; $i++) {
            $this->pool->push($value);
        }
        return $this;
    }

    public function push(mixed $connection): static
    {
        $this->pool->push($connection);
        return $this;
    }

    public function pop(int $timeout): mixed
    {
        // Swoole Channel doesn't support -1 timeout properly in all contexts
        // Use a very small timeout to check if channel is empty, then return null
        if ($timeout === -1) {
            $timeout = 0.001; // 1ms timeout
        }

        $result = $this->pool->pop($timeout);

        // If pop returns false, it means timeout occurred (channel was empty)
        return $result === false ? null : $result;
    }


    public function count(): int
    {
        return $this->pool->length();
    }

    public function run(callable $callback): mixed
    {
        return $callback($this->pool);
    }
}
