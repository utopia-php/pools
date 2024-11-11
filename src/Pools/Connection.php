<?php

namespace Utopia\Pools;

use Exception;

/**
 * @template T
 */
class Connection
{
    /**
     * @var string
     */
    protected string $id = '';

    /**
     * @var Pool<T>|null
     */
    protected ?Pool $pool = null;

    /**
     * @var callable(T): void | null
     */
    protected $reset;

    /**
     * @param T $resource
     * @param callable(T): void | null $reset
     */
    public function __construct(
        protected mixed $resource,
        ?callable $reset = null
    ) {
        $this->reset = $reset;
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
     * @return self<T>
     */
    public function setID(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return T
     */
    public function getResource(): mixed
    {
        return $this->resource;
    }

    /**
     * @param T $resource
     * @return self<T>
     */
    public function setResource(mixed $resource): self
    {
        $this->resource = $resource;
        return $this;
    }

    /**
     * @return ?Pool<T>
     */
    public function getPool(): ?Pool
    {
        return $this->pool;
    }

    /**
     * @param Pool<T> $pool
     * @return self<T>
     */
    public function setPool(Pool $pool): self
    {
        $this->pool = $pool;
        return $this;
    }

    /**
     * @return Pool<T>
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
     * @return Pool<T>
     * @throws Exception
     */
    public function destroy(): Pool
    {
        if ($this->pool === null) {
            throw new Exception('You cannot destroy connection that does not have a pool.');
        }

        return $this->pool->destroy($this);
    }

    public function reset(): void
    {
        \var_dump('calling reset on connection: ' . $this->id);
        if ($this->reset === null) {
            return;
        }

        ($this->reset)($this->resource);
    }
}
