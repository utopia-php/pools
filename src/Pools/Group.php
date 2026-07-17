<?php

namespace Utopia\Pools;

use Exception;
use Utopia\Telemetry\Adapter as Telemetry;

class Group
{
    /**
     * @var array<Pool<covariant mixed>>
     */
    protected array $pools = [];

    /**
     * @param Pool<covariant mixed> $pool
     * @return static
     */
    public function add(Pool $pool): static
    {
        $this->pools[$pool->getName()] = $pool;
        return $this;
    }

    /**
     * @param string $name
     * @return Pool<covariant mixed>
     * @throws Exception
     */
    public function get(string $name): Pool
    {
        return $this->pools[$name] ?? throw new Exception("Pool '$name' not found");
    }

    /**
     * @param string $name
     * @return static
     */
    public function remove(string $name): static
    {
        unset($this->pools[$name]);
        return $this;
    }

    /**
     * @return static
     */
    public function reclaim(): static
    {
        foreach ($this->pools as $pool) {
            $pool->reclaim();
        }

        return $this;
    }

    /**
     * Execute a callback with a managed connection
     *
     * @template TReturn
     * @param array<string> $names Name of resources
     * @param callable(mixed...): TReturn $callback Function that receives the connection resources
     * @return TReturn Return value from the callback
     * @throws Exception
     */
    public function use(array $names, callable $callback): mixed
    {
        if (empty($names)) {
            throw new Exception('Cannot use with empty names');
        }

        $connections = [];
        $pools = [];
        $starts = [];
        $started = false;
        $failed = false;
        $thrown = null;
        $result = null;

        try {
            foreach ($names as $name) {
                $pool = $this->get($name);
                $starts[] = microtime(true);
                $pools[] = $pool;
                $connections[] = $pool->pop();
            }

            $started = true;
            $result = $callback(...array_map(fn(Connection $connection) => $connection->getResource(), $connections));
        } catch (\Throwable $error) {
            $thrown = $error;
            $failed = $started;
        }

        $releaseError = null;

        for ($i = \count($connections) - 1; $i >= 0; $i--) {
            try {
                $pools[$i]->release($connections[$i], $failed, $starts[$i]);
            } catch (\Throwable $error) {
                $releaseError ??= $error;
            }
        }

        if ($thrown !== null) {
            throw $thrown;
        }

        if ($releaseError !== null) {
            throw $releaseError;
        }

        return $result;
    }

    /**
     * @param int $reconnectAttempts
     * @return static
     */
    public function setReconnectAttempts(int $reconnectAttempts): static
    {
        foreach ($this->pools as $pool) {
            $pool->setReconnectAttempts($reconnectAttempts);
        }

        return $this;
    }

    /**
     * @param int $reconnectSleep
     * @return static
     */
    public function setReconnectSleep(int $reconnectSleep): static
    {
        foreach ($this->pools as $pool) {
            $pool->setReconnectSleep($reconnectSleep);
        }

        return $this;
    }

    public function setTelemetry(Telemetry $telemetry): static
    {
        foreach ($this->pools as $pool) {
            $pool->setTelemetry($telemetry);
        }

        return $this;
    }
}
