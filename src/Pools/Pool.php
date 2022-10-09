<?php

namespace Utopia\Pools;

use Exception;

class Pool
{
    /**
     * @var string
     */
    protected string $name;
    
    /**
     * @var int
     */
    protected int $size = 0;

    /**
     * @var callable
     */
    protected $init = null;

    /**
     * @var int
     */
    protected int $reconnectAttempts = 3;

    /**
     * @var int
     */
    protected int $reconnectSleep = 1; // seconds

    /**
     * @var array
     */
    protected array $pool = [];

    /**
     * @var array
     */
    protected array $active = [];

    /**
     * @var string $name
     * @var callable $init
     */
    public function __construct(string $name, int $size, callable $init)
    {
        $this->name = $name;
        $this->size = $size;
        $this->init = $init;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return int
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * @return int
     */
    public function getReconnectAttempts(): int
    {
        return $this->reconnectAttempts;
    }

    /**
     * @return self
     */
    public function setReconnectAttempts(int $reconnectAttempts): self
    {
        $this->reconnectAttempts = $reconnectAttempts;
        return $this;
    }

    /**
     * @return int
     */
    public function getReconnectSleep(): int
    {
        return $this->reconnectSleep;
    }

    /**
     * @return self
     */
    public function setReconnectSleep(int $reconnectSleep): self
    {
        $this->reconnectSleep = $reconnectSleep;
        return $this;
    }

    /**
     * @return self
     */
    public function fill(): self
    {
        $this->pool = [];

        for ($i=0; $i < $this->size; $i++) { 
            $attempts = 0;

            do {
                try {
                    $attempts++;
                    $connection = new Connection(($this->init)());
                    break; // leave loop if successful
                } catch (\Exception $e) {
                    if ($attempts >= $this->getReconnectAttempts()) {
                        throw new \Exception('Failed to create connection: ' . $e->getMessage());
                    }
                    sleep($this->getReconnectSleep());
                }
            } while ($attempts < $this->getReconnectAttempts());

            $connection->setID($this->getName().'-'.$i);
            
            $this->pool[$i] = $connection;
        }

        return $this;
    }

    /**
     * @return Connection
     */
    public function pop(): Connection
    {
        if (empty($this->pool)) {
            throw new Exception('Pool is empty');
        }

        $connection = array_pop($this->pool);
        $this->active[$connection->getID()] = $connection;

        return $connection;
    }

    /**
     * @param Connection $connection
     * @return self
     */
    public function push(Connection $connection): self
    {
        array_push($this->pool, $connection);
        unset($this->active[$connection->getID()]);

        return $this;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->pool);
    }

    /**
     * @return self
     */
    public function reset(): self
    {
        foreach ($this->active as $connection) {
            $this->push($connection);
        }
        return $this;
    }
    
    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->pool);
    }

    /**
     * @return bool
     */
    public function isFull(): bool
    {
        return count($this->pool) === $this->size;
    }
}
