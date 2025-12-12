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

    /**
     * @var \Swoole\Coroutine\Channel|null
     */
    private ?\Swoole\Coroutine\Channel $channel = null;

    /**
     * @var bool
     */
    private bool $useChannel = false;

    /**
     * @var int
     */
    private int $connectionIdCounter = 0;

    /**
     * @var \Swoole\Atomic|null
     */
    private static ?\Swoole\Atomic $globalAtomicCounter = null;

    /**
     * @var \Swoole\Lock|null
     */
    private ?\Swoole\Lock $activeLock = null;

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
    public function __construct(protected string $name, protected int $size, callable $init)
    {
        $this->init = $init;
        $this->pool = array_fill(0, $this->size, true);
        $this->setTelemetry(new NoTelemetry());
        
        // Don't initialize channel here - it will be lazily initialized when needed
        // Channel can only be created inside a coroutine context
    }

    /**
     * Initialize channel for coroutine-safe operations
     * This must be called from within a coroutine context
     */
    private function ensureChannel(): void
    {
        // Only initialize if Swoole is loaded, we're in a coroutine, and channel isn't already created
        if (!$this->useChannel 
            && \extension_loaded('swoole') 
            && \class_exists('\Swoole\Coroutine\Channel')
            && \Swoole\Coroutine::getCid() > 0
        ) {
            $this->channel = new \Swoole\Coroutine\Channel($this->size);
            
            // Initialize lock for active connections array
            if (\class_exists('\Swoole\Lock')) {
                $this->activeLock = new \Swoole\Lock(SWOOLE_MUTEX);
            }
            
            // Migrate existing pool items to channel
            while (!empty($this->pool)) {
                $item = array_pop($this->pool);
                $this->channel->push($item);
            }
            
            $this->useChannel = true;
        }
    }

    /**
     * Generate a unique connection ID (coroutine-safe)
     */
    private function generateConnectionId(): string
    {
        // Use atomic increment for coroutine safety
        if (\extension_loaded('swoole') && \class_exists('\Swoole\Atomic')) {
            // Initialize global atomic counter if not already done
            if (self::$globalAtomicCounter === null) {
                self::$globalAtomicCounter = new \Swoole\Atomic(0);
            }
            $id = self::$globalAtomicCounter->add(1);
            $cid = \Swoole\Coroutine::getCid();
            return $this->getName() . '-' . $id . '-' . ($cid > 0 ? $cid : 'main') . '-' . hrtime(true);
        }
        
        // Fallback for non-coroutine context
        return $this->getName() . '-' . (++$this->connectionIdCounter) . '-' . uniqid();
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
        $startTime = microtime(true);

        try {
            // Ensure channel is initialized if we're in a coroutine context
            $this->ensureChannel();
            
            // Use channel-based approach for coroutine safety
            if ($this->useChannel && $this->channel !== null) {
                return $this->popWithChannel($attempts, $totalSleepTime);
            }

            // Fallback to array-based approach for non-coroutine environments
            do {
                $attempts++;
                $connection = array_pop($this->pool);

                if (is_null($connection)) {
                    if ($attempts >= $this->getRetryAttempts()) {
                        throw new Exception("Pool '{$this->name}' is empty (size {$this->size})");
                    }

                    $totalSleepTime += $this->getRetrySleep();
                    $this->sleep($this->getRetrySleep());
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
                        $this->sleep($this->getReconnectSleep());
                    }
                } while ($attempts < $this->getReconnectAttempts());
            }

            if ($connection instanceof Connection) { // connection is available, return it
                if (empty($connection->getID())) {
                    $connection->setID($this->generateConnectionId());
                }

                $connection->setPool($this);
                $this->active[$connection->getID()] = $connection;
                
                return $connection;
            }

            throw new Exception('Failed to get a connection from the pool');
        } finally {
            $this->recordPoolTelemetry();
            $totalSleepTime = microtime(true) - $startTime;
            $this->telemetryWaitDuration->record($totalSleepTime, $this->telemetryAttributes);
        }
    }

    /**
     * Pop a connection using Swoole Channel (coroutine-safe)
     *
     * @param int $attempts
     * @param int $totalSleepTime
     * @return Connection<TResource>
     * @throws Exception
     */
    private function popWithChannel(int &$attempts, int &$totalSleepTime): Connection
    {
        $connection = null;
        $slot = null;

        do {
            $attempts++;
            
            // Calculate timeout based on retry settings
            $timeout = $this->getRetrySleep();
            
            // Try to get a slot from the channel
            $slot = $this->channel->pop($timeout);

            if ($slot === false) {
                // Timeout occurred
                if ($attempts >= $this->getRetryAttempts()) {
                    throw new Exception("Pool '{$this->name}' is empty (size {$this->size})");
                }
                $totalSleepTime += $timeout;
            } else {
                // Got a slot, now check if it's a connection or needs to be created
                if ($slot instanceof Connection) {
                    $connection = $slot;
                    break;
                } elseif ($slot === true) {
                    // Need to create a new connection
                    $reconnectAttempts = 0;
                    do {
                        try {
                            $reconnectAttempts++;
                            $connection = new Connection(($this->init)());
                            break;
                        } catch (\Exception $e) {
                            if ($reconnectAttempts >= $this->getReconnectAttempts()) {
                                // Return the slot back to the channel before throwing
                                $this->channel->push(true);
                                throw new \Exception('Failed to create connection: ' . $e->getMessage());
                            }
                            $totalSleepTime += $this->getReconnectSleep();
                            $this->sleep($this->getReconnectSleep());
                        }
                    } while ($reconnectAttempts < $this->getReconnectAttempts());
                    break;
                }
            }
        } while ($attempts < $this->getRetryAttempts());

        if (!$connection instanceof Connection) {
            throw new Exception('Failed to get a connection from the pool');
        }

        // Set up the connection
        if (empty($connection->getID())) {
            $connection->setID($this->generateConnectionId());
        }

        $connection->setPool($this);
        
        // Protect active array access with lock
        $this->activeLock?->lock();
        try {
            $this->active[$connection->getID()] = $connection;
        } finally {
            $this->activeLock?->unlock();
        }

        return $connection;
    }

    /**
     * @param Connection<TResource> $connection
     * @return $this<TResource>
     */
    public function push(Connection $connection): static
    {
        try {
            // Ensure channel is initialized if we're in a coroutine context
            $this->ensureChannel();
            
            if ($this->useChannel && $this->channel !== null) {
                // Remove from active array first (with lock protection)
                $this->activeLock?->lock();
                try {
                    unset($this->active[$connection->getID()]);
                } finally {
                    $this->activeLock?->unlock();
                }
                
                // Push connection back to channel
                $this->channel->push($connection);
            } else {
                // Fallback to array-based approach
                $this->pool[] = $connection;
                unset($this->active[$connection->getID()]);
            }

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
        if ($this->useChannel && $this->channel !== null) {
            return $this->channel->length();
        }
        return count($this->pool);
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
            // Ensure channel is initialized if we're in a coroutine context
            $this->ensureChannel();
            
            if ($this->useChannel && $this->channel !== null) {
                if ($connection !== null) {
                    $this->activeLock?->lock();
                    try {
                        unset($this->active[$connection->getID()]);
                    } finally {
                        $this->activeLock?->unlock();
                    }
                    $this->channel->push(true);
                    return $this;
                }

                $this->activeLock?->lock();
                try {
                    foreach ($this->active as $connection) {
                        $this->channel->push(true);
                        unset($this->active[$connection->getID()]);
                    }
                } finally {
                    $this->activeLock?->unlock();
                }

                return $this;
            } else {
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
            }
        } finally {
            $this->recordPoolTelemetry();
        }
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        if ($this->useChannel && $this->channel !== null) {
            return $this->channel->isEmpty();
        }
        return empty($this->pool);
    }

    /**
     * @return bool
     */
    public function isFull(): bool
    {
        if ($this->useChannel && $this->channel !== null) {
            return $this->channel->isFull();
        }
        return count($this->pool) === $this->size;
    }

    /**
     * Coroutine-aware sleep function
     */
    private function sleep(int $seconds): void
    {
        if (\extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep($seconds);
        } else {
            sleep($seconds);
        }
    }

    private function recordPoolTelemetry(): void
    {
        $this->activeLock?->lock();
        try {
            $activeConnections = count($this->active);
        } finally {
            $this->activeLock?->unlock();
        }
        
        if ($this->useChannel && $this->channel !== null) {
            // For channel-based pools, we need to peek at the channel stats
            $availableSlots = $this->channel->length();
            $idleConnections = $this->channel->stats()['consumer_num'] ?? 0;
            
            $this->telemetryActiveConnections->record($activeConnections, $this->telemetryAttributes);
            $this->telemetryIdleConnections->record($availableSlots, $this->telemetryAttributes);
            $this->telemetryOpenConnections->record($activeConnections + $availableSlots, $this->telemetryAttributes);
            $this->telemetryPoolCapacity->record($this->size, $this->telemetryAttributes);
        } else {
            // Connections get removed from $this->pool when they are active
            $existingConnections = count($this->pool);
            $idleConnections = count(array_filter($this->pool, fn ($data) => $data instanceof Connection));
            
            $this->telemetryActiveConnections->record($activeConnections, $this->telemetryAttributes);
            $this->telemetryIdleConnections->record($idleConnections, $this->telemetryAttributes);
            $this->telemetryOpenConnections->record($activeConnections + $idleConnections, $this->telemetryAttributes);
            $this->telemetryPoolCapacity->record($activeConnections + $existingConnections, $this->telemetryAttributes);
        }
    }
}
