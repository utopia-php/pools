<?php

namespace Utopia\Pools;

class Connection {

    /**
     * @var string
     */
    protected string $id;

    /**
     * @var mixed $connection
     */
    public function __construct(protected mixed $connection)
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
    public function getConnection(): mixed
    {
        return $this->connection;
    }

    /**
     * @param mixed $connection
     * @return self
     */
    public function setConnection(mixed $connection): self
    {
        $this->connection = $connection;
        return $this;
    }
}