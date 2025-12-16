<?php

namespace Utopia\Pools\Adapter;

use Utopia\Pools\Adapter;

class Stack extends Adapter
{
    /** @var array<mixed> $pool */
    protected array $pool = [];

    public function fill(int $size, mixed $value): static
    {
        $this->pool = array_fill(0, $size, $value);
        return $this;
    }

    public function push(mixed $connection): static
    {
        $this->pool[] = $connection;
        return $this;
    }

    public function pop(int $timeout): mixed
    {
        return array_pop($this->pool);
    }

    public function count(): int
    {
        return count($this->pool);
    }

    public function run(callable $callback): mixed
    {
        return $callback($this->pool);
    }
}
