<?php

namespace Utopia\Pools;

use Exception;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Gauge;
use Utopia\Telemetry\Histogram;

/**
 * @template TResource
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
     * @var array<Connection<TResource>|true>
     */
    protected array $pool = [];

    /**
     * @var array<string, Connection<TResource>>
     */
    protected array $active = [];

    private Gauge $telemetryOpenConnections;
    private Gauge $telemetryActiveConnections;
    private Gauge $telemetryIdleConnections;
    private Gauge $telemetryPoolCapacity;
    private Histogram $telemetryWaitDuration;
    private Histogram $telemetryUseDuration;
    /** @var array<string, int|string> */
    private array $telemetryAttributes;

    /**
     * @param string $name
     * @param int $size
     * @param callable(): TResource $init
     */
    public function __construct(string $name, int $size, callable $init)
    {
        $this->name = $name;
        $this->size = $size;
        $this->init = $init;
        $this->pool = array_fill(0, $size, true);
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
                $connection = array_pop($this->pool);

                if (is_null($connection)) {
                    if ($attempts >= $this->getRetryAttempts()) {
                        throw new Exception("Pool '{$this->name}' is empty (size {$this->size})");
                    }

                    $totalSleepTime += $this->getRetrySleep();
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
                        $connection = new Connection(($this->init)());
                        break; // leave loop if successful
                    } catch (\Exception $e) {
                        if ($attempts >= $this->getReconnectAttempts()) {
                            throw new \Exception('Failed to create connection: ' . $e->getMessage());
                        }
                        $totalSleepTime += $this->getReconnectSleep();
                        sleep($this->getReconnectSleep());
                    }
                } while ($attempts < $this->getReconnectAttempts());
            }

            if ($connection instanceof Connection) { // connection is available, return it
                if (empty($connection->getID())) {
                    $connection->setID($this->getName() . '-' . uniqid());
                }

                $connection->setPool($this);

                $this->active[$connection->getID()] = $connection;
                return $connection;
            }

            throw new Exception('Failed to get a connection from the pool');
        } finally {
            $this->recordPoolTelemetry();
            $this->telemetryWaitDuration->record($totalSleepTime, $this->telemetryAttributes);
        }
    }

    /**
     * @param Connection<TResource> $connection
     * @return $this<TResource>
     */
    public function push(Connection $connection): static
    {
        try {
            $this->pool[] = $connection;
            unset($this->active[$connection->getID()]);

            return $this;
        } finally {
            $this->recordPoolTelemetry();
        }
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->pool);
    }

    /**
     * @param Connection<TResource>|null $connection
     * @return $this<TResource>
     */
    public function reclaim(Connection $connection = null): static
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
    public function destroy(Connection $connection = null): static
    {
        try {
            if ($connection !== null) {
                $this->pool[] = true;
                unset($this->active[$connection->getID()]);
                return $this;
            }

            foreach ($this->active as $connection) {
                $this->pool[] = true;
                unset($this->active[$connection->getID()]);
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
        return empty($this->pool);
    }

    /**
     * @return bool
     */
    public function isFull(): bool
    {
        return count($this->pool) === $this->size;
    }

    private function recordPoolTelemetry(): void
    {
        // Connections get removed from $this->pool when they are active
        $activeConnections = count($this->active);
        $existingConnections = count($this->pool);
        $idleConnections = count(array_filter($this->pool, fn ($data) => $data instanceof Connection));
        $this->telemetryActiveConnections->record($activeConnections, $this->telemetryAttributes);
        $this->telemetryIdleConnections->record($idleConnections, $this->telemetryAttributes);
        $this->telemetryOpenConnections->record($activeConnections + $idleConnections, $this->telemetryAttributes);
        $this->telemetryPoolCapacity->record($activeConnections + $existingConnections, $this->telemetryAttributes);
    }
}
