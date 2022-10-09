<?php

namespace Utopia\Pools;

use Exception;

class Group
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
     * @return ?Pool
     */
    public function get(string $name): ?Pool
    {
        if(!isset($this->pools[$name])) {
            throw new Exception('Pool not found');
        }

        return $this->pools[$name];
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
        foreach ($this->pools as $pool) {
            $pool->fill();
        }
        
        return $this;
    }
    
    /**
     * @return self
     */
    public function reset(): self
    {
        foreach ($this->pools as $pool) {
            $pool->reset();
        }
        
        return $this;
    }
}