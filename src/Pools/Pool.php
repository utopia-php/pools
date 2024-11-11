<?php

namespace Utopia\Pools;

use Exception;

/**
 * @template T
 */
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
     * @var callable(): T
     */
    protected $init;

    /**
     * @var callable(T): void | null
     */
    protected $reset = null;

    /**
     * @var int
     */
    protected int $reconnectAttempts = 3;

    /**
     * @var int
     */
    protected int $reconnectSleep = 1; // seconds

    /**
     * @var int
     */
    protected int $retryAttempts = 3;

    /**
     * @var int
     */
    protected int $retrySleep = 1; // seconds

    /**
     * @var array<Connection<T>|true>
     */
    protected array $pool = [];

    /**
     * @var array<string, Connection<T>>
     */
    protected array $active = [];

    /**
     * @param string $name
     * @param int $size
     * @param callable(): T $init
     * @param callable(T): void | null $reset
     */
    public function __construct(
        string $name,
        int $size,
        callable $init,
        ?callable $reset = null
    ) {
        $this->name = $name;
        $this->size = $size;
        $this->init = $init;
        $this->reset = $reset;
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
     * @return self<T>
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
     * @return self<T>
     */
    public function setReconnectSleep(int $reconnectSleep): self
    {
        $this->reconnectSleep = $reconnectSleep;
        return $this;
    }

    /**
     * @return int
     */
    public function getRetryAttempts(): int
    {
        return $this->retryAttempts;
    }

    /**
     * @param int $retryAttempts
     * @return self<T>
     */
    public function setRetryAttempts(int $retryAttempts): self
    {
        $this->retryAttempts = $retryAttempts;
        return $this;
    }

    /**
     * @return int
     */
    public function getRetrySleep(): int
    {
        return $this->retrySleep;
    }

    /**
     * @param int $retrySleep
     * @return self<T>
     */
    public function setRetrySleep(int $retrySleep): self
    {
        $this->retrySleep = $retrySleep;
        return $this;
    }

    /**
     * Summary:
     *  1. Try to get a connection from the pool
     *  2. If no connection is available, wait for one to be released
     *  3. If still no connection is available, throw an exception
     *  4. If a connection is available, return it
     *
     * @return Connection<T>
     */
    public function pop(): Connection
    {
        $attempts = 0;

        do {
            $attempts++;
            $connection = array_pop($this->pool);

            if (is_null($connection)) {
                if ($attempts >= $this->getRetryAttempts()) {
                    throw new Exception("Pool '{$this->name}' is empty (size {$this->size})");
                }

                sleep($this->getRetrySleep());
            } else {
                break;
            }
        } while ($attempts < $this->getRetryAttempts());

        if ($connection === true) { // Pool has space, create connection
            $attempts = 0;

            do {
                try {
                    $attempts++;
                    $connection = new Connection(($this->init)(), $this->reset);
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
            $connection
                ->setID($this->getName().'-'.uniqid())
                ->setPool($this)
            ;

            $this->active[$connection->getID()] = $connection;

            $connection->reset();

            return $connection;
        }

        throw new Exception('Failed to get a connection from the pool');
    }

    /**
     * @param Connection<T> $connection
     * @return self<T>
     */
    public function push(Connection $connection): self
    {
        $this->pool[] = $connection;
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
     * @param ?Connection<T> $connection
     * @return self<T>
     */
    public function reclaim(Connection $connection = null): self
    {
        if ($connection !== null) {
            $this->push($connection);
            return $this;
        }

        foreach ($this->active as $connection) {
            $this->push($connection);
        }

        return $this;
    }


    /**
     * @param ?Connection<T> $connection
     * @return self<T>
     */
    public function destroy(Connection $connection = null): self
    {
        if ($connection !== null) {
            array_push($this->pool, true);
            unset($this->active[$connection->getID()]);
            return $this;
        }

        foreach ($this->active as $connection) {
            array_push($this->pool, true);
            unset($this->active[$connection->getID()]);
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
