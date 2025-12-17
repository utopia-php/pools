<?php

namespace Utopia\Pools;

use Exception;
use Utopia\Pools\Adapter as PoolAdapter;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;

/**
 * @template TResource
 */
class Pool
{
    public const POP_TIMEOUT_IN_SECONDS = 3;
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
     * @var int
     */
    protected int $retryAttempts = 3;

    /**
     * @var int
     */
    protected int $retrySleep = 1; // seconds

    protected PoolAdapter $pool;

    /**
     * @var array<string, Connection<TResource>>
     */
    protected array $active = [];

    /**
     * Total number of connections created
     */
    protected int $connectionsCreated = 0;

    private Gauge $telemetryOpenConnections;
    private Gauge $telemetryActiveConnections;
    private Gauge $telemetryIdleConnections;
    private Gauge $telemetryPoolCapacity;
    private Histogram $telemetryWaitDuration;
    private Histogram $telemetryUseDuration;
    /** @var array<non-empty-string, int|string> */
    private array $telemetryAttributes;

    /**
     * @param PoolAdapter $adapter
     * @param string $name
     * @param int $size
     * @param callable(): TResource $init
     */
    public function __construct(PoolAdapter $adapter, protected string $name, protected int $size, callable $init)
    {
        $this->init = $init;
        $this->pool = $adapter;
        // Initialize empty channel (no pre-filling for lazy initialization)
        $this->pool->fill($this->size, null);
        $this->setTelemetry(new NoTelemetry());
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
     * @return $this<TResource>
     */
    public function setReconnectAttempts(int $reconnectAttempts): static
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
     * @return $this<TResource>
     */
    public function setReconnectSleep(int $reconnectSleep): static
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
     * @return $this<TResource>
     */
    public function setRetryAttempts(int $retryAttempts): static
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
     * @return $this<TResource>
     */
    public function setRetrySleep(int $retrySleep): static
    {
        $this->retrySleep = $retrySleep;
        return $this;
    }

    /**
     * @param Telemetry $telemetry
     * @return $this<TResource>
     */
    public function setTelemetry(Telemetry $telemetry): static
    {
        $this->telemetryOpenConnections = $telemetry->createGauge('pool.connection.open.count');
        $this->telemetryActiveConnections = $telemetry->createGauge('pool.connection.active.count');
        $this->telemetryIdleConnections = $telemetry->createGauge('pool.connection.idle.count');
        $this->telemetryPoolCapacity = $telemetry->createGauge('pool.connection.capacity.count');
        $this->telemetryWaitDuration = $telemetry->createHistogram(
            name: 'pool.connection.wait_time',
            unit: 's',
            advisory: ['ExplicitBucketBoundaries' =>  [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]],
        );
        $this->telemetryUseDuration = $telemetry->createHistogram(
            name: 'pool.connection.use_time',
            unit: 's',
            advisory: ['ExplicitBucketBoundaries' =>  [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]],
        );
        $this->telemetryAttributes = ['pool' => $this->name, 'size' => $this->size];

        return $this;
    }

    /**
     * Execute a callback with a managed connection
     *
     * @template T
     * @param callable(TResource): T $callback Function that receives the connection resource
     * @return T Return value from the callback
     */
    public function use(callable $callback): mixed
    {
        $start = microtime(true);
        $connection = null;
        try {
            $connection = $this->pop();
            return $callback($connection->getResource());
        } finally {
            if ($connection !== null) {
                $this->telemetryUseDuration->record(microtime(true) - $start, $this->telemetryAttributes);
                $this->reclaim($connection);
            }
        }
    }

    /**
     * Summary:
     *  1. Try to get a connection from the pool
     *  2. If no connection is available, wait for one to be released
     *  3. If still no connection is available, throw an exception
     *  4. If a connection is available, return it
     *
     * @return Connection<TResource>
     * @throws Exception
     * @internal Please migrate to `use`.
     */
    public function pop(): Connection
    {
        $attempts = 0;
        $totalSleepTime = 0;

        try {
            do {
                $attempts++;
                // If pool is empty and size limit not reached, create new connection
                // Use lock to prevent race condition where multiple coroutines create connections simultaneously
                $shouldCreate = $this->pool->withLock(function () {
                    return $this->pool->count() === 0 && $this->connectionsCreated < $this->size;
                }, timeout: self::POP_TIMEOUT_IN_SECONDS);

                if ($shouldCreate) {
                    $connection = $this->createConnection();
                    $this->active[$connection->getID()] = $connection;
                    return $connection;
                }

                $connection = $this->pool->pop(self::POP_TIMEOUT_IN_SECONDS);

                if ($connection === false || $connection === null) {
                    if ($attempts >= $this->getRetryAttempts()) {
                        throw new Exception("Pool '{$this->name}' is empty (size {$this->size})");
                    }

                    $totalSleepTime += $this->getRetrySleep();
                    sleep($this->getRetrySleep());
                } else {
                    if ($connection instanceof Connection) {
                        $this->active[$connection->getID()] = $connection;
                        return $connection;
                    }
                }
            } while ($attempts < $this->getRetryAttempts());

            throw new Exception('Failed to get a connection from the pool');
        } finally {
            $this->recordPoolTelemetry();
            $this->telemetryWaitDuration->record($totalSleepTime, $this->telemetryAttributes);
        }
    }

    /**
     * Create a new connection
     *
     * @return Connection<TResource>
     * @throws \Exception
     */
    protected function createConnection(): Connection
    {
        $this->connectionsCreated++;

        $connection = null;
        $attempts = 0;
        do {
            try {
                $attempts++;
                $connection = new Connection(($this->init)());
                break;
            } catch (\Exception $e) {
                if ($attempts >= $this->getReconnectAttempts()) {
                    $this->connectionsCreated--;
                    throw new \Exception('Failed to create connection: ' . $e->getMessage());
                }
                sleep($this->getReconnectSleep());
            }
        } while ($attempts < $this->getReconnectAttempts());

        if ($connection === null) {
            $this->connectionsCreated--;
            throw new \Exception('Failed to create connection');
        }

        if (empty($connection->getID())) {
            $connection->setID($this->getName() . '-' . uniqid());
        }

        $connection->setPool($this);

        return $connection;
    }

    /**
     * @param Connection<TResource> $connection
     * @return $this<TResource>
     */
    public function push(Connection $connection): static
    {
        try {
            // Push the actual connection back to the pool
            $this->pool->push($connection);
            unset($this->active[$connection->getID()]);

            return $this;
        } finally {
            $this->recordPoolTelemetry();
        }
    }

    /**
     * Returns the number of available connections (idle + not yet created)
     *
     * @return int
     */
    public function count(): int
    {
        // Available = idle connections in pool + connections not yet created
        return $this->pool->count() + ($this->size - $this->connectionsCreated);
    }

    /**
     * @param Connection<TResource>|null $connection
     * @return $this<TResource>
     */
    public function reclaim(?Connection $connection = null): static
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
     * @param Connection<TResource>|null $connection
     * @return $this<TResource>
     */
    public function destroy(?Connection $connection = null): static
    {
        try {
            if ($connection !== null) {
                // Synchronize access to shared state and replacement connection creation
                $newConnection = $this->pool->withLock(function () use ($connection) {
                    $this->connectionsCreated--;
                    unset($this->active[$connection->getID()]);

                    // Create a new connection to maintain pool size while holding the lock
                    if ($this->connectionsCreated < $this->size) {
                        return $this->createConnection();
                    }
                    return null;
                }, timeout: self::POP_TIMEOUT_IN_SECONDS);

                // Push the new connection to the pool if one was created
                if ($newConnection !== null) {
                    $this->pool->push($newConnection);
                }

                return $this;
            }

            // Get a stable copy of active connections to avoid modifying array during iteration
            $activeConnections = array_values($this->active);

            foreach ($activeConnections as $conn) {
                // Synchronize access to shared state and replacement connection creation
                $newConnection = $this->pool->withLock(function () use ($conn) {
                    $this->connectionsCreated--;
                    unset($this->active[$conn->getID()]);

                    // Create a new connection to maintain pool size while holding the lock
                    if ($this->connectionsCreated < $this->size) {
                        return $this->createConnection();
                    }
                    return null;
                }, timeout: self::POP_TIMEOUT_IN_SECONDS);

                // Push the new connection to the pool if one was created
                if ($newConnection !== null) {
                    $this->pool->push($newConnection);
                }
            }

            return $this;
        } finally {
            $this->recordPoolTelemetry();
        }
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->pool->count() === 0;
    }

    /**
     * @return bool
     */
    public function isFull(): bool
    {
        // Pool is full when all possible connections are available (idle or not created yet)
        return count($this->active) === 0;
    }

    private function recordPoolTelemetry(): void
    {
        $activeConnections = count($this->active);
        $idleConnections = $this->pool->count(); // Connections in the pool (idle)
        $openConnections = $activeConnections + $idleConnections; // Total connections in use or available

        $this->telemetryActiveConnections->record($activeConnections, $this->telemetryAttributes);
        $this->telemetryIdleConnections->record($idleConnections, $this->telemetryAttributes);
        $this->telemetryOpenConnections->record($openConnections, $this->telemetryAttributes);
        $this->telemetryPoolCapacity->record($activeConnections + $this->pool->count(), $this->telemetryAttributes);
    }
}
