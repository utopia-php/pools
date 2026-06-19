<?php

namespace Utopia\Pools;

use Exception;
use Utopia\Pools\Adapter as PoolAdapter;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\ObservableGauge;

/**
 * @template TResource
 */
class Pool
{
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

    /**
     * @var int
     */
    protected int $synchronizedTimeout = 3;

    /**
     * @var array<string, Connection<TResource>>
     */
    protected array $active = [];

    /**
     * Total number of connections created
     */
    protected int $connectionsCreated = 0;

    private Histogram $telemetryWaitDuration;
    private Histogram $telemetryUseDuration;
    /** @var array<non-empty-string, int|string> */
    private array $telemetryAttributes;
    /** @var list<ObservableGauge> */
    private array $telemetryGauges = [];

    /**
     * @param PoolAdapter $pool
     * @param string $name
     * @param int $size
     * @param callable(): TResource $init
     */
    public function __construct(protected PoolAdapter $pool, protected string $name, protected int $size, callable $init)
    {
        $this->init = $init;
        // Initialize empty channel (no pre-filling for lazy initialization)
        $this->pool->initialize($this->size);
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
     * @return $this
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
     * @return $this
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
     * @return $this
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
     * @return $this
     */
    public function setRetrySleep(int $retrySleep): static
    {
        $this->retrySleep = $retrySleep;
        return $this;
    }

    /**
     * Set the lock timeout for adapters that support synchronized locking.
     *
     * Note:
     * - This setting is applied only if the underlying adapter supports lock timeouts.
     * - For adapters that do not support locking or lock timeouts, this method is a no-op.
     *
     * @param int $timeout Synchronized lock timeout in seconds.
     * @return $this
     */
    public function setSynchronizationTimeout(int $timeout): static
    {
        $this->synchronizedTimeout = $timeout;
        return $this;
    }

    public function getSynchronizationTimeout(): int
    {
        return $this->synchronizedTimeout;
    }

    /**
     * @param Telemetry $telemetry
     * @return $this
     */
    public function setTelemetry(Telemetry $telemetry): static
    {
        // Observable gauges are pull-based: the adapter samples them on its own
        // collection cycle. When swapping adapters, neutralize the ones bound to the
        // previous adapter so it stops sampling this pool and double-emitting metrics.
        foreach ($this->telemetryGauges as $gauge) {
            $gauge->observe(fn(callable $observe) => null);
        }

        $this->telemetryWaitDuration = Histogram::lazy(
            telemetry: $telemetry,
            name: 'pool.connection.wait_time',
            unit: 's',
            advisory: ['ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]],
        );
        $this->telemetryUseDuration = Histogram::lazy(
            telemetry: $telemetry,
            name: 'pool.connection.use_time',
            unit: 's',
            advisory: ['ExplicitBucketBoundaries' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]],
        );
        $this->telemetryAttributes = ['pool' => $this->name, 'size' => $this->size];

        // Connection counts are gauges: only their value at export time matters, so observe
        // them lazily at collection rather than recording on every pop/push/reclaim.
        $this->telemetryGauges = [
            $this->observeGauge($telemetry, 'pool.connection.active.count', fn() => \count($this->active)),
            $this->observeGauge($telemetry, 'pool.connection.idle.count', fn() => $this->pool->count()),
            $this->observeGauge($telemetry, 'pool.connection.open.count', fn() => \count($this->active) + $this->pool->count()),
            $this->observeGauge($telemetry, 'pool.connection.capacity.count', fn() => $this->connectionsCreated),
        ];

        return $this;
    }

    /**
     * @param callable(): (float|int) $sample
     */
    private function observeGauge(Telemetry $telemetry, string $name, callable $sample): ObservableGauge
    {
        $gauge = $telemetry->createObservableGauge($name);
        $gauge->observe(fn(callable $observe) => $observe($sample(), $this->telemetryAttributes));
        return $gauge;
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
            $this->telemetryUseDuration->record(microtime(true) - $start, $this->telemetryAttributes);
            if ($connection !== null) {
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
        $lastException = null;

        try {
            do {
                $attempts++;
                // the connection creation block outside the lock so that other coroutines not get blocked in case of retries of a coroutine
                // Lock: check + increment only
                // Unlock
                // Create connection (no lock)
                // On failure: lock + decrement
                $shouldCreateConnections = $this->pool->synchronized(function (): bool {
                    if ($this->pool->count() === 0 && $this->connectionsCreated < $this->size) {
                        $this->connectionsCreated++;
                        return true;
                    }
                    return false;
                });

                if ($shouldCreateConnections) {
                    try {
                        $connection = $this->createConnection();
                        $this->pool->synchronized(function () use ($connection): void {
                            $this->active[$connection->getID()] = $connection;
                        });
                        return $connection;
                    } catch (\Exception $e) {
                        $this->pool->synchronized(function (): void {
                            $this->connectionsCreated--;
                        });
                        // Don't throw immediately - fall through to try getting
                        // an existing connection from the pool
                        $lastException = $e;
                    }
                }

                $connection = $this->pool->pop($this->getSynchronizationTimeout());

                if ($connection === false || $connection === null) {
                    if ($attempts >= $this->getRetryAttempts()) {
                        $activeCount = \count($this->active);
                        $idleCount = $this->pool->count();
                        $message = "Pool '{$this->name}' is empty (size {$this->size}, active {$activeCount}, idle {$idleCount})";
                        throw new Exception($message, 0, $lastException);
                    }

                    $totalSleepTime += $this->getRetrySleep();
                    sleep($this->getRetrySleep());
                } else {
                    if ($connection instanceof Connection) {
                        $this->pool->synchronized(function () use ($connection): void {
                            $this->active[$connection->getID()] = $connection;
                        });
                        return $connection;
                    }
                }
            } while ($attempts < $this->getRetryAttempts());

            $activeCount = \count($this->active);
            $idleCount = $this->pool->count();
            throw new Exception("Pool '{$this->name}' failed to provide a connection (size {$this->size}, active {$activeCount}, idle {$idleCount})", 0, $lastException);
        } finally {
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
        $connection = null;
        $attempts = 0;
        do {
            try {
                $attempts++;
                $connection = new Connection(($this->init)());
                break;
            } catch (\Exception $e) {
                if ($attempts >= $this->getReconnectAttempts()) {
                    throw new \Exception('Failed to create connection: ' . $e->getMessage());
                }
                sleep($this->getReconnectSleep());
            }
        } while ($attempts < $this->getReconnectAttempts());

        if ($connection === null) {
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
     * @return $this
     */
    public function push(Connection $connection): static
    {
        // Push the actual connection back to the pool
        $this->pool->push($connection);
        unset($this->active[$connection->getID()]);

        return $this;
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
     * @return $this
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
     * @return $this
     */
    private function destroyConnection(?Connection $connection = null): static
    {
        if ($connection !== null) {
            $shouldCreate = $this->pool->synchronized(function () use ($connection) {
                $this->connectionsCreated--;
                unset($this->active[$connection->getID()]);
                if ($this->connectionsCreated < $this->size) {
                    $this->connectionsCreated++;
                    return true;
                }
                return false;
            });

            if ($shouldCreate) {
                try {
                    $this->pool->push($this->createConnection());
                } catch (Exception $e) {
                    $this->pool->synchronized(function (): void {
                        $this->connectionsCreated--;
                    });
                    throw $e;
                }
            }

            return $this;
        }
        $activeConnections = array_values($this->active);
        foreach ($activeConnections as $conn) {
            $this->destroyConnection($conn);
        }
        return $this;
    }

    /**
     * @param Connection<TResource>|null $connection
     * @return $this
     */
    public function destroy(?Connection $connection = null): static
    {
        return $this->destroyConnection($connection);
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * @return bool
     */
    public function isFull(): bool
    {
        // Pool is full when all possible connections are available (idle or not created yet)
        return \count($this->active) === 0;
    }
}
