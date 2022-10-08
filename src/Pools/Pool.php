<?php

namespace Utopia\Database;

class Pool
{

    public function __construct(protected $name)
    {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
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
