<?php

namespace Utopia\Pools;

use Exception;

class Group
{
    /**
     * @var array<Pool<mixed>>
     */
    protected array $pools = [];

    /**
     * @param Pool<mixed> $pool
     * @return self
     */
    public function add(Pool $pool): self
    {
        $this->pools[$pool->getName()] = $pool;
        return $this;
    }

    /**
     * @param string $name
     * @return Pool<mixed>
     * @throws Exception
     */
    public function get(string $name): Pool
    {
        return $this->pools[$name] ??  throw new Exception("Pool '{$name}'  not found");
    }

    /**
     * @param string $name
     * @return self
     */
    public function remove(string $name): self
    {
        unset($this->pools[$name]);
        return $this;
    }

    /**
     * @return self
     */
    public function reclaim(): self
    {
        foreach ($this->pools as $pool) {
            $pool->reclaim();
        }

        return $this;
    }

    /**
     * @param int $reconnectAttempts
     * @return self
     */
    public function setReconnectAttempts(int $reconnectAttempts): self
    {
        foreach ($this->pools as $pool) {
            $pool->setReconnectAttempts($reconnectAttempts);
        }

        return $this;
    }

    /**
     * @param int $reconnectSleep
     * @return self
     */
    public function setReconnectSleep(int $reconnectSleep): self
    {
        foreach ($this->pools as $pool) {
            $pool->setReconnectSleep($reconnectSleep);
        }

        return $this;
    }
}
