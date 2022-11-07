<?php

namespace Utopia\Pools;

use Exception;

class Connection
{
    /**
     * @var string
     */
    protected string $id = '';

    /**
     * @var Pool|null
     */
    protected ?Pool $pool = null;

    /**
     * @param mixed $resource
     */
    public function __construct(protected mixed $resource)
    {
    }

    /**
     * @return string
     */
    public function getID(): string
    {
        return $this->id;
    }

    /**
     * @param string $id
     * @return self
     */
    public function setID(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getResource(): mixed
    {
        return $this->resource;
    }

    /**
     * @param mixed $resource
     * @return self
     */
    public function setResource(mixed $resource): self
    {
        $this->resource = $resource;
        return $this;
    }

    /**
     * @return Pool
     */
    public function getPool(): ?Pool
    {
        return $this->pool;
    }

    /**
     * @param Pool $pool
     * @return self
     */
    public function setPool(Pool &$pool): self
    {
        $this->pool = $pool;
        return $this;
    }

    /**
     * @return Pool
     */
    public function reclaim(): Pool
    {
        if ($this->pool === null) {
            throw new Exception('You cannot reclaim connection that does not have a pool.');
        }

        return $this->pool->reclaim($this);
    }
}
