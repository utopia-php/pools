<?php

namespace Utopia\Pools;

use Exception;

class Connection
{
    protected string $id = '';

    protected ?Pool $pool = null;

    protected bool $healthy = true;

    /**
     * @param mixed $resource
     */
    public function __construct(
        protected mixed $resource,
    ) {
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

    /**
     * @return Pool
     */
    public function destroy(): Pool
    {
        if ($this->pool === null) {
            throw new Exception('You cannot destroy connection that does not have a pool.');
        }

        return $this->pool->destroy($this);
    }

    /**
     * @param bool $healthy
     * @return self
     */
    public function setHealthy(bool $healthy): self
    {
        $this->healthy = $healthy;
        return $this;
    }

    /**
     * @return bool
     */
    public function isHealthy(): bool
    {
        return $this->healthy;
    }
}
