<?php

namespace Utopia\Tests\Scopes;

use Exception;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

trait PoolTestScope
{
    abstract protected function getAdapter(): \Utopia\Pools\Adapter;
    abstract protected function execute(callable $callback): mixed;

    /**
     * @var Pool<string>
     */
    protected Pool $poolObject;

    protected function setUpPool(): void
    {
        $this->poolObject = new Pool($this->getAdapter(), 'test', 5, fn() => 'x');
    }

    public function testPoolGetName(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame('test', $this->poolObject->getName());
        });
    }

    public function testPoolGetSize(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->getSize());
        });
    }

    public function testPoolGetReconnectAttempts(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(3, $this->poolObject->getReconnectAttempts());
        });
    }

    public function testPoolSetReconnectAttempts(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(3, $this->poolObject->getReconnectAttempts());

            $this->poolObject->setReconnectAttempts(20);

            $this->assertSame(20, $this->poolObject->getReconnectAttempts());
        });
    }

    public function testPoolGetReconnectSleep(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(1, $this->poolObject->getReconnectSleep());
        });
    }

    public function testPoolSetReconnectSleep(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(1, $this->poolObject->getReconnectSleep());

            $this->poolObject->setReconnectSleep(20);

            $this->assertSame(20, $this->poolObject->getReconnectSleep());
        });
    }

    public function testPoolGetRetryAttempts(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(3, $this->poolObject->getRetryAttempts());
        });
    }

    public function testPoolSetRetryAttempts(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(3, $this->poolObject->getRetryAttempts());

            $this->poolObject->setRetryAttempts(20);

            $this->assertSame(20, $this->poolObject->getRetryAttempts());
        });
    }

    public function testPoolGetRetrySleep(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(1, $this->poolObject->getRetrySleep());
        });
    }

    public function testPoolSetRetrySleep(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(1, $this->poolObject->getRetrySleep());

            $this->poolObject->setRetrySleep(20);

            $this->assertSame(20, $this->poolObject->getRetrySleep());
        });
    }

    public function testPoolPop(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->count());

            $connection = $this->poolObject->pop();

            $this->assertSame(4, $this->poolObject->count());

            $this->assertInstanceOf(Connection::class, $connection);
            $this->assertSame('x', $connection->getResource());

            // Pop remaining 4 connections
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            // Pool should be empty, next pop should throw
            $this->expectException(Exception::class);
            $this->poolObject->pop();
        });
    }

    public function testPoolUse(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->count());
            $this->poolObject->use(function ($resource): void {
                $this->assertSame(4, $this->poolObject->count());
                $this->assertSame('x', $resource);
            });

            $this->assertSame(5, $this->poolObject->count());
        });
    }

    public function testPoolPush(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->count());

            $connection = $this->poolObject->pop();

            $this->assertSame(4, $this->poolObject->count());

            $this->assertInstanceOf(Connection::class, $connection);
            $this->assertSame('x', $connection->getResource());

            $this->assertInstanceOf(Pool::class, $this->poolObject->push($connection));

            $this->assertSame(5, $this->poolObject->count());
        });
    }

    public function testPoolCount(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->count());

            $connection = $this->poolObject->pop();

            $this->assertSame(4, $this->poolObject->count());

            $this->poolObject->push($connection);

            $this->assertSame(5, $this->poolObject->count());
        });
    }

    public function testPoolReclaim(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(5, $this->poolObject->count());

            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            $this->assertSame(2, $this->poolObject->count());

            $this->poolObject->reclaim();

            $this->assertSame(5, $this->poolObject->count());
        });
    }

    public function testPoolIsEmpty(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            $this->assertSame(true, $this->poolObject->isEmpty());
        });
    }

    public function testPoolIsFull(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertSame(true, $this->poolObject->isFull());

            $connection = $this->poolObject->pop();

            $this->assertSame(false, $this->poolObject->isFull());

            $this->poolObject->push($connection);

            $this->assertSame(true, $this->poolObject->isFull());

            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            $this->assertSame(false, $this->poolObject->isFull());

            $this->poolObject->reclaim();

            $this->assertSame(true, $this->poolObject->isFull());

            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            $this->assertSame(false, $this->poolObject->isFull());
        });
    }

    public function testPoolRetry(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->poolObject->setReconnectAttempts(2);
            $this->poolObject->setReconnectSleep(2);

            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            // Pool should be empty
            $this->expectException(Exception::class);

            $timeStart = time();
            $this->poolObject->pop();
            $timeEnd = time();

            $timeDiff = $timeEnd - $timeStart;

            $this->assertGreaterThanOrEqual(4, $timeDiff);
        });
    }

    public function testPoolDestroy(): void
    {
        $this->execute(function (): void {
            $i = 0;
            $object = new Pool($this->getAdapter(), 'testDestroy', 2, function () use (&$i) {
                $i++;
                return $i <= 2 ? 'x' : 'y';
            });

            $this->assertSame(2, $object->count());

            $connection1 = $object->pop();
            $connection2 = $object->pop();

            $this->assertSame(0, $object->count());

            $this->assertSame('x', $connection1->getResource());
            $this->assertSame('x', $connection2->getResource());

            $object->destroy();

            $this->assertSame(2, $object->count());

            $connection1 = $object->pop();
            $connection2 = $object->pop();

            $this->assertSame(0, $object->count());

            $this->assertSame('y', $connection1->getResource());
            $this->assertSame('y', $connection2->getResource());
        });
    }

    public function testPopRetriesAfterConnectionCreationFailure(): void
    {
        $this->execute(function (): void {
            $callCount = 0;
            $pool = new Pool($this->getAdapter(), 'test-retry', 1, function () use (&$callCount) {
                $callCount++;
                if ($callCount <= 1) {
                    throw new \Exception('Connection failed');
                }
                return 'x';
            });
            $pool->setReconnectAttempts(1);
            $pool->setReconnectSleep(0);
            $pool->setRetrySleep(0);

            // With the fix, pop() should retry after creation failure
            // First attempt: createConnection fails (callCount=1), falls through
            // Second attempt: createConnection succeeds (callCount=2)
            $connection = $pool->pop();
            $this->assertSame('x', $connection->getResource());
        });
    }

    public function testPoolEmptyErrorIncludesActiveCount(): void
    {
        $this->execute(function (): void {
            $this->setUpPool(); // size 5
            $this->poolObject->setRetryAttempts(1);
            $this->poolObject->setRetrySleep(0);

            // Pop all 5
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            try {
                $this->poolObject->pop();
                $this->fail('Should have thrown');
            } catch (Exception $e) {
                $this->assertStringContainsString('active 5', $e->getMessage());
            }
        });
    }

    public function testUseDestroysConnectionWhenRecoveryFails(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-destroy-on-error', 2, function () use (&$created) {
                $created++;
                return new readonly class ('resource-' . $created, $created === 1) implements \Stringable {
                    public function __construct(private string $name, private bool $failRecovery) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }

                    public function reconnect(): void
                    {
                        if ($this->failRecovery) {
                            throw new \RuntimeException('Recovery failed');
                        }
                    }
                };
            });
            $pool->setReconnectAttempts(1);
            $pool->setReconnectSleep(0);

            try {
                $pool->use(function (\Stringable $resource): void {
                    $this->assertSame('resource-1', (string) $resource);
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException) {
                // expected
            }

            $this->assertSame(2, $pool->count());

            $pool->use(function (\Stringable $resource): void {
                $this->assertSame('resource-2', (string) $resource);
            });
        });
    }

    public function testUseDestroysConnectionWhenRecoveryReturnsFalse(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-destroy-on-false-recovery', 2, function () use (&$created) {
                $created++;
                return new readonly class ('resource-' . $created) implements \Stringable {
                    public function __construct(private string $name) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }

                    public function reconnect(): bool
                    {
                        return false;
                    }
                };
            });
            $pool->setReconnectAttempts(1);
            $pool->setReconnectSleep(0);

            try {
                $pool->use(function (\Stringable $resource): void {
                    $this->assertSame('resource-1', (string) $resource);
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException) {
                // expected
            }

            $this->assertSame(2, $pool->count());

            $pool->use(function (\Stringable $resource): void {
                $this->assertSame('resource-2', (string) $resource);
            });
        });
    }

    public function testUseRecoversAndReusesConnectionWhenRecoverySucceeds(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-recover-and-reuse', 2, function () use (&$created) {
                $created++;
                return new readonly class ('resource-' . $created) implements \Stringable {
                    public function __construct(private string $name) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }

                    public function reconnect(): bool
                    {
                        return true;
                    }
                };
            });
            $pool->setReconnectAttempts(1);
            $pool->setReconnectSleep(0);

            try {
                $pool->use(function (\Stringable $resource): void {
                    $this->assertSame('resource-1', (string) $resource);
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException) {
                // expected
            }

            $pool->use(function (\Stringable $resource) use (&$created): void {
                $this->assertSame('resource-1', (string) $resource);
                $this->assertSame(1, $created);
            });
        });
    }

    public function testUseDestroysObjectConnectionWithoutRecoveryHooks(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-destroy-without-recovery', 2, function () use (&$created) {
                $created++;
                return new readonly class ('resource-' . $created) implements \Stringable {
                    public function __construct(private string $name) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }
                };
            });
            $pool->setReconnectAttempts(1);
            $pool->setReconnectSleep(0);

            try {
                $pool->use(function (\Stringable $resource): void {
                    $this->assertSame('resource-1', (string) $resource);
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException) {
                // expected
            }

            $this->assertSame(2, $pool->count());

            $pool->use(function (\Stringable $resource): void {
                $this->assertSame('resource-2', (string) $resource);
            });
        });
    }

    public function testUseDestroysNativeResourceConnectionAfterCallbackFailure(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-destroy-native-resource', 2, function () use (&$created) {
                $created++;
                $resource = fopen('php://temp', 'r+');
                if ($resource === false) {
                    throw new \RuntimeException('Failed to open stream');
                }

                fwrite($resource, 'resource-' . $created);
                rewind($resource);

                return $resource;
            });
            $pool->setReconnectAttempts(1);
            $pool->setReconnectSleep(0);

            try {
                $pool->use(function ($resource): void {
                    $this->assertSame('resource-1', stream_get_contents($resource));
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException) {
                // expected
            }

            $this->assertSame(2, $pool->count());

            $pool->use(function ($resource): void {
                $this->assertSame('resource-2', stream_get_contents($resource));
            });
        });
    }

    public function testUseForgetsConnectionWhenDestroyCleanupFails(): void
    {
        $this->execute(function (): void {
            $adapter = new class extends \Utopia\Pools\Adapter\Stack {
                public bool $failSynchronized = false;

                public function synchronized(callable $callback): mixed
                {
                    if ($this->failSynchronized) {
                        $this->failSynchronized = false;
                        throw new \RuntimeException('Lock failed');
                    }

                    return parent::synchronized($callback);
                }
            };

            $created = 0;
            $pool = new Pool($adapter, 'test-forget-on-destroy-failure', 1, function () use (&$created) {
                $created++;
                return new readonly class ('resource-' . $created) implements \Stringable {
                    public function __construct(private string $name) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }
                };
            });
            $pool->setReconnectAttempts(1);
            $pool->setReconnectSleep(0);

            try {
                $pool->use(function (\Stringable $resource) use ($adapter): void {
                    $this->assertSame('resource-1', (string) $resource);
                    $adapter->failSynchronized = true;
                    throw new \RuntimeException('Callback failed');
                });
            } catch (\RuntimeException $exception) {
                $this->assertSame('Callback failed', $exception->getMessage());
            }

            $pool->use(function (\Stringable $resource) use (&$created): void {
                $this->assertSame('resource-2', (string) $resource);
                $this->assertSame(2, $created);
            });
        });
    }

    public function testUsePreservesCallbackExceptionWhenReplacementFails(): void
    {
        $this->execute(function (): void {
            $created = 0;
            $pool = new Pool($this->getAdapter(), 'test-preserve-callback-error', 1, function () use (&$created) {
                $created++;
                if ($created > 1) {
                    throw new \TypeError('Replacement failed');
                }

                return new readonly class ('resource-' . $created) implements \Stringable {
                    public function __construct(private string $name) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }

                    public function reconnect(): never
                    {
                        throw new \RuntimeException('Recovery failed');
                    }
                };
            });
            $pool->setReconnectAttempts(1);
            $pool->setReconnectSleep(0);

            $error = null;
            try {
                $pool->use(function (\Stringable $resource): void {
                    $this->assertSame('resource-1', (string) $resource);
                    throw new \LogicException('Callback failed');
                });
            } catch (\LogicException $error) {
            }

            $this->assertInstanceOf(\LogicException::class, $error);
            $this->assertSame('Callback failed', $error->getMessage());
            $this->assertSame(1, $pool->count());
        });
    }

    public function testPoolTelemetry(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $telemetry = new TestTelemetry();
            $this->poolObject->setTelemetry($telemetry);

            $this->assertArrayHasKey('pool.connection.open.count', $telemetry->observableGauges);
            $this->assertArrayHasKey('pool.connection.active.count', $telemetry->observableGauges);
            $this->assertArrayHasKey('pool.connection.idle.count', $telemetry->observableGauges);
            $this->assertArrayHasKey('pool.connection.capacity.count', $telemetry->observableGauges);
            $this->assertArrayNotHasKey('pool.connection.wait_time', $telemetry->histograms);
            $this->assertArrayNotHasKey('pool.connection.use_time', $telemetry->histograms);

            // Observable gauges report their value at collection time, so read them on demand.
            $read = function (string $name) use ($telemetry): float|int {
                /** @var object{callbacks: array<int, \Closure>} $gauge */
                $gauge = $telemetry->observableGauges[$name];
                $value = 0;
                foreach ($gauge->callbacks as $callback) {
                    $callback(function (float|int $observed) use (&$value): void {
                        $value = $observed;
                    });
                }
                return $value;
            };

            $this->assertSame(5, $this->poolObject->count());

            $connections = [];
            for ($i = 0; $i < 3; $i++) {
                $connections[] = $this->poolObject->pop();
            }

            $this->assertSame(3, $read('pool.connection.open.count'));
            $this->assertSame(3, $read('pool.connection.active.count'));
            $this->assertSame(0, $read('pool.connection.idle.count'));
            $this->assertSame(3, $read('pool.connection.capacity.count'));

            /** @var object{values: array<int, float|int>} $waitHistogram */
            $waitHistogram = $telemetry->histograms['pool.connection.wait_time'];
            $this->assertCount(3, $waitHistogram->values);
            $this->assertArrayNotHasKey('pool.connection.use_time', $telemetry->histograms);

            // Reclaim one connection: it returns to the pool as idle.
            $this->poolObject->reclaim(array_pop($connections));

            $this->assertSame(3, $read('pool.connection.open.count'));
            $this->assertSame(2, $read('pool.connection.active.count'));
            $this->assertSame(1, $read('pool.connection.idle.count'));
            $this->assertSame(3, $read('pool.connection.capacity.count'));

            // Reclaim the rest.
            foreach ($connections as $connection) {
                $this->poolObject->reclaim($connection);
            }

            $this->assertSame(3, $read('pool.connection.open.count'));
            $this->assertSame(0, $read('pool.connection.active.count'));
            $this->assertSame(3, $read('pool.connection.idle.count'));
            $this->assertSame(5, $this->poolObject->count());
        });
    }

    public function testMultiplePoolsShareGaugesButEmitDistinctSeries(): void
    {
        $this->execute(function (): void {
            // Adapters cache observable gauges by name, so every pool that registers
            // 'pool.connection.*.count' shares one instrument. Each pool must still emit its own
            // series; a single-callback gauge would drop all but the last pool to bind.
            $telemetry = new TestTelemetry();

            $alpha = new Pool($this->getAdapter(), 'alpha', 5, fn() => 'x');
            $beta = new Pool($this->getAdapter(), 'beta', 5, fn() => 'x');
            $alpha->setTelemetry($telemetry);
            $beta->setTelemetry($telemetry);

            $alpha->pop();
            $beta->pop();
            $beta->pop();

            /** @var object{callbacks: array<int, \Closure>} $gauge */
            $gauge = $telemetry->observableGauges['pool.connection.active.count'];

            $series = [];
            foreach ($gauge->callbacks as $callback) {
                $callback(function (float|int $value, iterable $attributes = []) use (&$series): void {
                    $attrs = [];
                    foreach ($attributes as $key => $attr) {
                        $attrs[$key] = $attr;
                    }
                    $series[$attrs['pool']] = $value;
                });
            }

            $this->assertSame(['alpha' => 1, 'beta' => 2], $series);
        });
    }

    public function testPoolUseDurationTelemetryIsCreatedOnFirstUse(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $telemetry = new TestTelemetry();
            $this->poolObject->setTelemetry($telemetry);

            $this->assertArrayNotHasKey('pool.connection.use_time', $telemetry->histograms);

            $this->poolObject->use(function ($resource): void {
                $this->assertSame('x', $resource);
            });

            $this->assertArrayHasKey('pool.connection.use_time', $telemetry->histograms);
            /** @var object{values: array<int, float|int>} $useHistogram */
            $useHistogram = $telemetry->histograms['pool.connection.use_time'];
            $this->assertCount(1, $useHistogram->values);
        });
    }
}
