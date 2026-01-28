<?php

namespace Utopia\Tests\Adapter;

use Utopia\Pools\Adapter\Swoole;
use Utopia\Tests\Base;
use Swoole\Coroutine;
use Utopia\Pools\Pool;
use Utopia\Pools\Connection;

class SwooleTest extends Base
{
    protected function getAdapter(): Swoole
    {
        return new Swoole();
    }

    protected function execute(callable $callback): mixed
    {
        $result = null;
        $exception = null;

        Coroutine\run(function () use ($callback, &$result, &$exception): void {
            try {
                $result = $callback();
            } catch (\Throwable $e) {
                $exception = $e;
            }
        });

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }

    public function testSwooleCoroutineRaceCondition(): void
    {
        $errors = [];
        $successCount = 0;

        \Swoole\Coroutine\run(function () use (&$errors, &$successCount) {
            // Create a pool with 5 connections inside coroutine context
            $connectionCounter = 0;
            $pool = new Pool(new Swoole(), 'swoole-test', 5, function () use (&$connectionCounter) {
                $connectionCounter++;
                return "connection-{$connectionCounter}";
            });

            // Set retry attempts to allow waiting for connections to be released
            $pool->setRetryAttempts(3);
            $pool->setRetrySleep(0);

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

            // Assertions inside coroutine context
            $this->assertEmpty($errors, 'Errors occurred: ' . implode(', ', $errors));
            $this->assertSame(10, $successCount, 'All 10 coroutines should successfully complete');

            // Pool should be full again after all connections are reclaimed
            $this->assertSame(5, $pool->count(), 'Pool should have all 5 connections back');

            // Should only create exactly pool size connections (no race conditions with new implementation)
            $this->assertSame(5, $connectionCounter, 'Should create exactly 5 connections (pool size)');
        });
    }

    public function testSwooleCoroutineHighConcurrency(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded');
        }

        $totalRequests = 20;
        $successCount = 0;
        $errorCount = 0;

        \Swoole\Coroutine\run(function () use ($totalRequests, &$successCount, &$errorCount) {
            // Create a pool with 3 connections inside coroutine context
            $connectionCounter = 0;
            $pool = new Pool(new Swoole(), 'swoole-concurrent', 3, function () use (&$connectionCounter) {
                $connectionCounter++;
                return "connection-{$connectionCounter}";
            });

            $pool->setRetryAttempts(3);
            $pool->setRetrySleep(0);

            $channels = [];
            for ($i = 0; $i < $totalRequests; $i++) {
                $channels[$i] = new \Swoole\Coroutine\Channel(1);
            }

            for ($i = 0; $i < $totalRequests; $i++) {
                \Swoole\Coroutine::create(function () use ($pool, $i, &$successCount, &$errorCount, $channels) {
                    try {
                        $pool->use(function ($resource) use ($i) {
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

            // All requests should succeed with proper retry logic
            $this->assertSame($totalRequests, $successCount, "All {$totalRequests} requests should succeed");
            $this->assertSame(0, $errorCount, 'No errors should occur with proper concurrency handling');

            // Pool should be full again
            $this->assertSame(3, $pool->count(), 'Pool should have all 3 connections back');

            // Should only create 3 connections (pool size)
            $this->assertSame(3, $connectionCounter, 'Should only create 3 connections (pool size)');
        });
    }

    public function testSwooleCoroutineConnectionUniqueness(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded');
        }

        $seenResources = [];
        $duplicateResources = [];

        \Swoole\Coroutine\run(function () use (&$seenResources, &$duplicateResources) {
            // Create a pool with 5 connections inside coroutine context
            $connectionCounter = 0;
            $pool = new Pool(new Swoole(), 'swoole-uniqueness', 5, function () use (&$connectionCounter) {
                $connectionCounter++;
                return "connection-{$connectionCounter}";
            });

            $pool->setRetryAttempts(1);
            $pool->setRetrySleep(0);

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

            // Assertions inside coroutine context
            $this->assertEmpty($duplicateResources, 'Duplicate resources detected: ' . implode(', ', $duplicateResources));
            $this->assertCount(5, $seenResources, 'Should have exactly 5 unique connections');

            // Verify each connection has a unique resource
            $resources = array_keys($seenResources);
            $this->assertCount(5, array_unique($resources), 'All connection resources should be unique');
        });
    }

    public function testSwooleCoroutineIdleConnectionReuse(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded');
        }

        $connectionIds = [];
        $connectionCounter = 0;

        \Swoole\Coroutine\run(function () use (&$connectionIds, &$connectionCounter) {
            // Create a pool with 3 connections inside coroutine context
            $pool = new Pool(new Swoole(), 'swoole-reuse', 3, function () use (&$connectionCounter) {
                $connectionCounter++;
                return "connection-{$connectionCounter}";
            });

            $pool->setRetryAttempts(1);
            $pool->setRetrySleep(0);

            // First wave: Create 3 connections
            $firstWave = [];
            for ($i = 0; $i < 3; $i++) {
                $conn = $pool->pop();
                $firstWave[] = $conn;
                $connectionIds['first'][] = $conn->getID();
            }

            // Return all connections
            foreach ($firstWave as $conn) {
                $pool->reclaim($conn);
            }

            // Second wave: Should reuse the same 3 connections
            $secondWave = [];
            for ($i = 0; $i < 3; $i++) {
                $conn = $pool->pop();
                $secondWave[] = $conn;
                $connectionIds['second'][] = $conn->getID();
            }

            // Return all connections
            foreach ($secondWave as $conn) {
                $pool->reclaim($conn);
            }

            // Assertions inside coroutine context
            $this->assertSame(3, $connectionCounter, 'Should only create 3 connections total');
            $this->assertCount(3, $connectionIds['first'], 'First wave should have 3 connections');
            $this->assertCount(3, $connectionIds['second'], 'Second wave should have 3 connections');

            // Second wave should reuse connections from first wave
            sort($connectionIds['first']);
            sort($connectionIds['second']);
            $this->assertSame($connectionIds['first'], $connectionIds['second'], 'Second wave should reuse same connection IDs');
        });
    }

    public function testSwooleCoroutineStressTest(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is not loaded');
        }

        $totalRequests = 100;
        $successCount = 0;
        $errorCount = 0;
        $connectionCounter = 0;

        \Swoole\Coroutine\run(function () use ($totalRequests, &$successCount, &$errorCount, &$connectionCounter) {
            // Create a pool with 10 connections inside coroutine context
            $pool = new Pool(new Swoole(), 'swoole-stress', 10, function () use (&$connectionCounter) {
                $connectionCounter++;
                return "connection-{$connectionCounter}";
            });

            $pool->setRetryAttempts(10);
            $pool->setRetrySleep(0);

            $channels = [];
            for ($i = 0; $i < $totalRequests; $i++) {
                $channels[$i] = new \Swoole\Coroutine\Channel(1);
            }

            for ($i = 0; $i < $totalRequests; $i++) {
                \Swoole\Coroutine::create(function () use ($pool, $i, &$successCount, &$errorCount, $channels) {
                    try {
                        $pool->use(function ($resource) {
                            // Simulate variable work duration
                            \Swoole\Coroutine::sleep(0.001 * rand(1, 5));
                            return $resource;
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

            // Assertions inside coroutine context
            $this->assertSame($totalRequests, $successCount, "All {$totalRequests} requests should succeed");
            $this->assertSame(0, $errorCount, 'No errors should occur');
            $this->assertSame(10, $connectionCounter, 'Should create exactly 10 connections (pool size)');
            $this->assertSame(10, $pool->count(), 'Pool should have all connections back');
        });
    }
    public function testInitOutsideCoroutineNotThrowAnyError(): void
    {
        $pool = new Pool(new Swoole(), 'test', 1, fn () => 'x');
        $this->assertInstanceOf(Pool::class, $pool);
    }
}
