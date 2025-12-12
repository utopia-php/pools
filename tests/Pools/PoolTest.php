<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

class PoolTest extends TestCase
{
    /**
     * @var Pool<string>
     */
    protected Pool $object;

    #[\Override]
    public function setUp(): void
    {
        $this->object = new Pool('test', 5, fn () => 'x');
    }

    public function testGetName(): void
    {
        $this->assertEquals('test', $this->object->getName());
    }

    public function testGetSize(): void
    {
        $this->assertEquals(5, $this->object->getSize());
    }

    public function testGetReconnectAttempts(): void
    {
        $this->assertEquals(3, $this->object->getReconnectAttempts());
    }

    public function testSetReconnectAttempts(): void
    {
        $this->assertEquals(3, $this->object->getReconnectAttempts());

        $this->object->setReconnectAttempts(20);

        $this->assertEquals(20, $this->object->getReconnectAttempts());
    }

    public function testGetReconnectSleep(): void
    {
        $this->assertEquals(1, $this->object->getReconnectSleep());
    }

    public function testSetReconnectSleep(): void
    {
        $this->assertEquals(1, $this->object->getReconnectSleep());

        $this->object->setReconnectSleep(20);

        $this->assertEquals(20, $this->object->getReconnectSleep());
    }

    public function testGetRetryAttempts(): void
    {
        $this->assertEquals(3, $this->object->getRetryAttempts());
    }

    public function testSetRetryAttempts(): void
    {
        $this->assertEquals(3, $this->object->getRetryAttempts());

        $this->object->setRetryAttempts(20);

        $this->assertEquals(20, $this->object->getRetryAttempts());
    }

    public function testGetRetrySleep(): void
    {
        $this->assertEquals(1, $this->object->getRetrySleep());
    }

    public function testSetRetrySleep(): void
    {
        $this->assertEquals(1, $this->object->getRetrySleep());

        $this->object->setRetrySleep(20);

        $this->assertEquals(20, $this->object->getRetrySleep());
    }

    public function testPop(): void
    {
        $this->assertEquals(5, $this->object->count());

        $connection = $this->object->pop();

        $this->assertEquals(4, $this->object->count());

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertEquals('x', $connection->getResource());

        // Pool should be empty
        $this->expectException(Exception::class);

        $this->assertInstanceOf(Connection::class, $this->object->pop());
        $this->assertInstanceOf(Connection::class, $this->object->pop());
        $this->assertInstanceOf(Connection::class, $this->object->pop());
        $this->assertInstanceOf(Connection::class, $this->object->pop());
        $this->assertInstanceOf(Connection::class, $this->object->pop());
    }

    public function testUse(): void
    {
        $this->assertEquals(5, $this->object->count());
        $this->object->use(function ($resource): void {
            $this->assertEquals(4, $this->object->count());
            $this->assertEquals('x', $resource);
        });

        $this->assertEquals(5, $this->object->count());
    }

    public function testPush(): void
    {
        $this->assertEquals(5, $this->object->count());

        $connection = $this->object->pop();

        $this->assertEquals(4, $this->object->count());

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertEquals('x', $connection->getResource());

        $this->assertInstanceOf(Pool::class, $this->object->push($connection));

        $this->assertEquals(5, $this->object->count());
    }

    public function testCount(): void
    {
        $this->assertEquals(5, $this->object->count());

        $connection = $this->object->pop();

        $this->assertEquals(4, $this->object->count());

        $this->object->push($connection);

        $this->assertEquals(5, $this->object->count());
    }

    public function testReclaim(): void
    {
        $this->assertEquals(5, $this->object->count());

        $this->object->pop();
        $this->object->pop();
        $this->object->pop();

        $this->assertEquals(2, $this->object->count());

        $this->object->reclaim();

        $this->assertEquals(5, $this->object->count());
    }

    public function testIsEmpty(): void
    {
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();

        $this->assertEquals(true, $this->object->isEmpty());
    }

    public function testIsFull(): void
    {
        $this->assertEquals(true, $this->object->isFull());

        $connection = $this->object->pop();

        $this->assertEquals(false, $this->object->isFull());

        $this->object->push($connection);

        $this->assertEquals(true, $this->object->isFull());

        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();

        $this->assertEquals(false, $this->object->isFull());

        $this->object->reclaim();

        $this->assertEquals(true, $this->object->isFull());

        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();

        $this->assertEquals(false, $this->object->isFull());
    }

    public function testRetry(): void
    {
        $this->object->setReconnectAttempts(2);
        $this->object->setReconnectSleep(2);

        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();

        // Pool should be empty
        $this->expectException(Exception::class);

        $timeStart = \time();
        $this->object->pop();
        $timeEnd = \time();

        $timeDiff = $timeEnd - $timeStart;

        $this->assertGreaterThanOrEqual(4, $timeDiff);
    }

    public function testDestroy(): void
    {
        $i = 0;
        $object = new Pool('testDestroy', 2, function () use (&$i) {
            $i++;
            return $i <= 2 ? 'x' : 'y';
        });

        $this->assertEquals(2, $object->count());

        $connection1 = $object->pop();
        $connection2 = $object->pop();

        $this->assertEquals(0, $object->count());

        $this->assertEquals('x', $connection1->getResource());
        $this->assertEquals('x', $connection2->getResource());

        $object->destroy();

        $this->assertEquals(2, $object->count());

        $connection1 = $object->pop();
        $connection2 = $object->pop();

        $this->assertEquals(0, $object->count());

        $this->assertEquals('y', $connection1->getResource());
        $this->assertEquals('y', $connection2->getResource());
    }

    public function testTelemetry(): void
    {
        $telemetry = new TestTelemetry();
        $this->object->setTelemetry($telemetry);

        $allocate = function (int $amount, callable $assertion): void {
            $connections = [];
            for ($i = 0; $i < $amount; $i++) {
                $connections[] = $this->object->pop();
            }

            $assertion();

            foreach ($connections as $connection) {
                $this->object->reclaim($connection);
            }
        };

        $this->assertEquals(5, $this->object->count());

        $allocate(3, function () use ($telemetry): void {
            $this->assertEquals([1, 2, 3], $telemetry->gauges['pool.connection.open.count']->values);
            $this->assertEquals([1, 2, 3], $telemetry->gauges['pool.connection.active.count']->values);
            $this->assertEquals([0, 0, 0], $telemetry->gauges['pool.connection.idle.count']->values);
        });

        $this->assertEquals(5, $this->object->count());

        $allocate(1, function () use ($telemetry): void {
            $this->assertEquals([1, 2, 3, 3, 3, 3, 3], $telemetry->gauges['pool.connection.open.count']->values);
            $this->assertEquals([1, 2, 3, 2, 1, 0, 1], $telemetry->gauges['pool.connection.active.count']->values);
            $this->assertEquals([0, 0, 0, 1, 2, 3, 2], $telemetry->gauges['pool.connection.idle.count']->values);
        });
    }

    public function testSwooleCoroutineRaceCondition(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded');
        }

        // Create a pool with 5 connections
        $connectionCounter = 0;
        $pool = new Pool('swoole-test', 5, function () use (&$connectionCounter) {
            $connectionCounter++;
            return "connection-{$connectionCounter}";
        });

        // Set retry attempts to 1 to fail fast if there's a race condition
        $pool->setRetryAttempts(1);
        $pool->setRetrySleep(0);

        $errors = [];
        $successCount = 0;

        \Swoole\Coroutine\run(function () use ($pool, &$errors, &$successCount) {
            // Spawn 10 coroutines trying to get connections from a pool of 5
            // First 5 should get connections immediately
            // Next 5 should wait and reuse connections after they're returned
            $channels = [];
            for ($i = 0; $i < 10; $i++) {
                $channels[$i] = new \Swoole\Coroutine\Channel(1);
            }

            for ($i = 0; $i < 10; $i++) {
                \Swoole\Coroutine::create(function () use ($pool, $i, &$errors, &$successCount, $channels) {
                    try {
                        // Each coroutine tries to get a connection
                        $connection = $pool->pop();
                        
                        // Verify we got a valid connection
                        if (!$connection instanceof Connection) {
                            $errors[] = "Coroutine {$i}: Did not receive a valid Connection object";
                            $channels[$i]->push(false);
                            return;
                        }
                        
                        if (empty($connection->getID())) {
                            $errors[] = "Coroutine {$i}: Connection has no ID";
                            $channels[$i]->push(false);
                            return;
                        }

                        // Simulate some work
                        \Swoole\Coroutine::sleep(0.01);

                        // Return connection to pool
                        $pool->reclaim($connection);
                        
                        $successCount++;
                        $channels[$i]->push(true);
                    } catch (\Exception $e) {
                        $errors[] = "Coroutine {$i}: " . $e->getMessage();
                        $channels[$i]->push(false);
                    }
                });
            }

            // Wait for all coroutines to complete
            foreach ($channels as $channel) {
                $channel->pop();
            }
        });

        // Assertions
        $this->assertEmpty($errors, 'Errors occurred: ' . implode(', ', $errors));
        $this->assertEquals(10, $successCount, 'All 10 coroutines should successfully complete');

        // Pool should be full again after all connections are reclaimed
        $this->assertEquals(5, $pool->count(), 'Pool should have all 5 connections back');
    }

    public function testSwooleCoroutineHighConcurrency(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded');
        }

        // Create a pool with 3 connections
        $connectionCounter = 0;
        $pool = new Pool('swoole-concurrent', 3, function () use (&$connectionCounter) {
            $connectionCounter++;
            return "connection-{$connectionCounter}";
        });

        $pool->setRetryAttempts(5);
        $pool->setRetrySleep(0);

        $totalRequests = 20;
        $successCount = 0;
        $errorCount = 0;
        $activeConnectionsSnapshot = [];

        \Swoole\Coroutine\run(function () use ($pool, $totalRequests, &$successCount, &$errorCount, &$activeConnectionsSnapshot) {
            $channels = [];
            for ($i = 0; $i < $totalRequests; $i++) {
                $channels[$i] = new \Swoole\Coroutine\Channel(1);
            }

            for ($i = 0; $i < $totalRequests; $i++) {
                \Swoole\Coroutine::create(function () use ($pool, $i, &$successCount, &$errorCount, &$activeConnectionsSnapshot, $channels) {
                    try {
                        $pool->use(function ($resource) use ($i, &$activeConnectionsSnapshot) {
                            // Simulate work
                            \Swoole\Coroutine::sleep(0.01);
                            return "processed-{$i}";
                        });
                        $successCount++;
                        $channels[$i]->push(true);
                    } catch (\Exception $e) {
                        $errorCount++;
                        $channels[$i]->push(false);
                    }
                });
            }

            // Wait for all coroutines to complete
            foreach ($channels as $channel) {
                $channel->pop();
            }
        });

        // All requests should succeed with proper retry logic
        $this->assertEquals($totalRequests, $successCount, "All {$totalRequests} requests should succeed");
        $this->assertEquals(0, $errorCount, 'No errors should occur with proper concurrency handling');

        // Pool should be full again
        $this->assertEquals(3, $pool->count(), 'Pool should have all 3 connections back');
    }

    public function testSwooleCoroutineConnectionUniqueness(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded');
        }

        // Create a pool with 5 connections
        $connectionCounter = 0;
        $pool = new Pool('swoole-uniqueness', 5, function () use (&$connectionCounter) {
            $connectionCounter++;
            return "connection-{$connectionCounter}";
        });

        $pool->setRetryAttempts(1);
        $pool->setRetrySleep(0);

        $seenResources = [];
        $duplicateResources = [];

        \Swoole\Coroutine\run(function () use ($pool, &$seenResources, &$duplicateResources) {
            $channels = [];
            for ($i = 0; $i < 5; $i++) {
                $channels[$i] = new \Swoole\Coroutine\Channel(1);
            }

            // Get all 5 connections simultaneously
            for ($i = 0; $i < 5; $i++) {
                \Swoole\Coroutine::create(function () use ($pool, $i, &$seenResources, &$duplicateResources, $channels) {
                    try {
                        $connection = $pool->pop();
                        $resource = $connection->getResource();

                        // Check if we've seen this resource before (indicates race condition)
                        if (isset($seenResources[$resource])) {
                            $duplicateResources[] = $resource;
                        } else {
                            $seenResources[$resource] = $connection;
                        }

                        // Hold the connection briefly
                        \Swoole\Coroutine::sleep(0.01);

                        $channels[$i]->push(true);
                    } catch (\Exception $e) {
                        $channels[$i]->push(false);
                    }
                });
            }

            // Wait for all coroutines to complete
            foreach ($channels as $channel) {
                $channel->pop();
            }
        });

        // Assertions
        $this->assertEmpty($duplicateResources, 'Duplicate resources detected: ' . implode(', ', $duplicateResources));
        $this->assertCount(5, $seenResources, 'Should have exactly 5 unique connections');

        // Verify each connection has a unique resource
        $resources = array_keys($seenResources);
        $this->assertCount(5, array_unique($resources), 'All connection resources should be unique');
    }
}
