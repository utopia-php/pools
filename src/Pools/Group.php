<?php

namespace Utopia\Pools;

use Exception;
use Utopia\Telemetry\Adapter as Telemetry;

class Group
{
    /**
     * @var Pool[]
     */
    protected array $pools = [];

    /**
     * @param Pool $pool
     * @return self
     */
    public function add(Pool $pool): self
    {
        $this->pools[$pool->getName()] = $pool;
        return $this;
    }

    /**
     * @param string $name
     * @return Pool
     */
    public function get(string $name): Pool
    {
        return $this->pools[$name] ?? throw new Exception("Pool '{$name}' not found");
    }

    /**
     * @param string $name
     * @return self
     */
    public function remove(string $name): self
    {
        unset($this->pools[$name]);
        return $this;
    }

    /**
     * @return self
     */
    public function reclaim(): self
    {
        foreach ($this->pools as $pool) {
            $pool->reclaim();
        }

        return $this;
    }

    /**
     * Execute a callback with a managed connection
     *
     * @param string[] $names Name of resources
     * @param callable(mixed...): mixed $callback Function that receives the connection resources
     * @return mixed Return value from the callback
     */
    public function use(array $names, callable $callback): mixed
    {
        if (empty($names)) {
            throw new Exception("Cannot use with empty names");
        }
        return $this->useInternal($names, $callback);
    }

    /**
     * Internal recursive callback for `use`.
     *
     * @param string[] $names Name of resources
     * @param callable(mixed...): mixed $callback Function that receives the connection resources
     * @param mixed[] $resources
     * @return mixed
     * @throws Exception
     */
    private function useInternal(array $names, callable $callback, array $resources = []): mixed
    {
        if (empty($names)) {
            return $callback(...$resources);
        }

        return $this
            ->get(array_shift($names))
            ->use(fn ($resource) => $this->useInternal($names, $callback, array_merge($resources, [$resource])));
    }

    /**
     * @param int $reconnectAttempts
     * @return self
     */
    public function setReconnectAttempts(int $reconnectAttempts): self
    {
        foreach ($this->pools as $pool) {
            $pool->setReconnectAttempts($reconnectAttempts);
        }

        return $this;
    }

    /**
     * @param int $reconnectSleep
     * @return self
     */
    public function setReconnectSleep(int $reconnectSleep): self
    {
        foreach ($this->pools as $pool) {
            $pool->setReconnectSleep($reconnectSleep);
        }

        return $this;
    }

    public function setTelemetry(Telemetry $telemetry): self
    {
        foreach ($this->pools as $pool) {
            $pool->setTelemetry($telemetry);
        }

        return $this;
    }
}
