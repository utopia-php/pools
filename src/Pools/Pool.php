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
     * @var array<int, true>|array<Connection>
     */
    protected array $pool = [];

    /**
     * @var array<Connection>
     */
    protected array $active = [];

    /**
     * @param string $name
     * @param int $size
     * @param callable $init
     */
    public function __construct(string $name, int $size, callable $init)
    {
        $this->name = $name;
        $this->size = $size;
        $this->init = $init;
        $this->pool = array_fill(0, $size, true);
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
     * @param int $reconnectAttempts
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
     * @param int $reconnectSleep
     * @return self
     */
    public function setReconnectSleep(int $reconnectSleep): self
    {
        $this->reconnectSleep = $reconnectSleep;
        return $this;
    }

    /**
     * Summary:
     *  1. Try to get a connection from the pool
     *  2. If no connection is available, wait for one to be released
     *  3. If still no connection is available, throw an exception
     *  4. If a connection is available, return it
     *
     * @return Connection
     */
    public function pop(): Connection
    {
        $connection = array_pop($this->pool);

        if (is_null($connection)) { // pool is empty, wait an if still empty throw exception
            usleep(50000); // 50ms TODO: make this configurable

            $connection = array_pop($this->pool);

            if (is_null($connection)) {
                throw new Exception('Pool is empty');
            }
        }

        if ($connection === true) { // Pool has space, create connection
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
        }

        if ($connection instanceof Connection) { // connection is available, return it
            $this->active[$connection->getID()] = $connection;

            $connection
                ->setID($this->getName().'-'.uniqid())
                ->setPool($this)
            ;

            return $connection;
        }

        throw new Exception('Failed to get a connection from the pool');
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
     * @param Connection|null $connection
     * @return self
     */
    public function reclaim(Connection $connection = null): self
    {
        foreach ($this->active as $activeConnection) {
            if($connection === null || $connection->getID() === $activeConnection->getID()) {
                $this->push($activeConnection);
            }
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
