<?php

namespace Utopia\Pools;

class Connection {

    /**
     * @var string
     */
    protected string $id = '';

    /**
     * @var mixed $resource
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
}