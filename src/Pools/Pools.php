<?php

namespace Utopia\Database;

class Pools
{
    /**
     * @var Pool[]
     */
    protected array $pools = [];
    
    /**
     * @var Pool $pool
     * @return self
     */
    public function add(Pool $pool): self
    {
        $this->pools[$pool->getName()] = $pool;
        return $this;
    }

    /**
     * @var string $name
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
    public function fill(): self
    {

        return $this;
    }
    /**
     * @return self
     */
    public function reset(): self
    {
        
        return $this;
    }
}
