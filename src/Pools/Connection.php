<?php

namespace Utopia\Pools;

use Exception;

/**
 * @template TResource
 */
class Connection
{
    protected string $id = '';

    /**
     * @var Pool<TResource>|null
     */
    protected ?Pool $pool = null;

    /**
     * @param TResource $resource
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
     * @return $this<TResource>
     */
    public function setID(string $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return TResource
     */
    public function getResource(): mixed
    {
        return $this->resource;
    }

    /**
     * @param TResource $resource
     * @return $this<TResource>
     */
    public function setResource(mixed $resource): static
    {
        $this->resource = $resource;
        return $this;
    }

    /**
     * @return Pool<TResource>|null
     */
    public function getPool(): ?Pool
    {
        return $this->pool;
    }

    /**
     * @param Pool<TResource> $pool
     * @return $this<TResource>
     */
    public function setPool(Pool $pool): static
    {
        $this->pool = $pool;
        return $this;
    }

    /**
     * @return Pool<TResource>
     * @throws Exception
     */
    public function reclaim(): Pool
    {
        if ($this->pool === null) {
            throw new Exception('You cannot reclaim connection that does not have a pool.');
        }

        return $this->pool->reclaim($this);
    }

    /**
     * @return Pool<TResource>
     * @throws Exception
     */
    public function destroy(): Pool
    {
        if ($this->pool === null) {
            throw new Exception('You cannot destroy connection that does not have a pool.');
        }

        return $this->pool->destroy($this);
    }
}
