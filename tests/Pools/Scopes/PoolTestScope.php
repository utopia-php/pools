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
        $this->poolObject = new Pool($this->getAdapter(), 'test', 5, fn () => 'x');
    }

    public function testPoolGetName(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals('test', $this->poolObject->getName());
        });
    }

    public function testPoolGetSize(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(5, $this->poolObject->getSize());
        });
    }

    public function testPoolGetReconnectAttempts(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(3, $this->poolObject->getReconnectAttempts());
        });
    }

    public function testPoolSetReconnectAttempts(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(3, $this->poolObject->getReconnectAttempts());

            $this->poolObject->setReconnectAttempts(20);

            $this->assertEquals(20, $this->poolObject->getReconnectAttempts());
        });
    }

    public function testPoolGetReconnectSleep(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(1, $this->poolObject->getReconnectSleep());
        });
    }

    public function testPoolSetReconnectSleep(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(1, $this->poolObject->getReconnectSleep());

            $this->poolObject->setReconnectSleep(20);

            $this->assertEquals(20, $this->poolObject->getReconnectSleep());
        });
    }

    public function testPoolGetRetryAttempts(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(3, $this->poolObject->getRetryAttempts());
        });
    }

    public function testPoolSetRetryAttempts(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(3, $this->poolObject->getRetryAttempts());

            $this->poolObject->setRetryAttempts(20);

            $this->assertEquals(20, $this->poolObject->getRetryAttempts());
        });
    }

    public function testPoolGetRetrySleep(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(1, $this->poolObject->getRetrySleep());
        });
    }

    public function testPoolSetRetrySleep(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(1, $this->poolObject->getRetrySleep());

            $this->poolObject->setRetrySleep(20);

            $this->assertEquals(20, $this->poolObject->getRetrySleep());
        });
    }

    public function testPoolPop(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(5, $this->poolObject->count());

            $connection = $this->poolObject->pop();

            $this->assertEquals(4, $this->poolObject->count());

            $this->assertInstanceOf(Connection::class, $connection);
            $this->assertEquals('x', $connection->getResource());

            // Pool should be empty
            $this->expectException(Exception::class);

            $this->assertInstanceOf(Connection::class, $this->poolObject->pop());
            $this->assertInstanceOf(Connection::class, $this->poolObject->pop());
            $this->assertInstanceOf(Connection::class, $this->poolObject->pop());
            $this->assertInstanceOf(Connection::class, $this->poolObject->pop());
            $this->assertInstanceOf(Connection::class, $this->poolObject->pop());
        });
    }

    public function testPoolUse(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(5, $this->poolObject->count());
            $this->poolObject->use(function ($resource): void {
                $this->assertEquals(4, $this->poolObject->count());
                $this->assertEquals('x', $resource);
            });

            $this->assertEquals(5, $this->poolObject->count());
        });
    }

    public function testPoolPush(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(5, $this->poolObject->count());

            $connection = $this->poolObject->pop();

            $this->assertEquals(4, $this->poolObject->count());

            $this->assertInstanceOf(Connection::class, $connection);
            $this->assertEquals('x', $connection->getResource());

            $this->assertInstanceOf(Pool::class, $this->poolObject->push($connection));

            $this->assertEquals(5, $this->poolObject->count());
        });
    }

    public function testPoolCount(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(5, $this->poolObject->count());

            $connection = $this->poolObject->pop();

            $this->assertEquals(4, $this->poolObject->count());

            $this->poolObject->push($connection);

            $this->assertEquals(5, $this->poolObject->count());
        });
    }

    public function testPoolReclaim(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(5, $this->poolObject->count());

            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            $this->assertEquals(2, $this->poolObject->count());

            $this->poolObject->reclaim();

            $this->assertEquals(5, $this->poolObject->count());
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

            $this->assertEquals(true, $this->poolObject->isEmpty());
        });
    }

    public function testPoolIsFull(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $this->assertEquals(true, $this->poolObject->isFull());

            $connection = $this->poolObject->pop();

            $this->assertEquals(false, $this->poolObject->isFull());

            $this->poolObject->push($connection);

            $this->assertEquals(true, $this->poolObject->isFull());

            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            $this->assertEquals(false, $this->poolObject->isFull());

            $this->poolObject->reclaim();

            $this->assertEquals(true, $this->poolObject->isFull());

            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();
            $this->poolObject->pop();

            $this->assertEquals(false, $this->poolObject->isFull());
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

            $timeStart = \time();
            $this->poolObject->pop();
            $timeEnd = \time();

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
        });
    }

    public function testPoolTelemetry(): void
    {
        $this->execute(function (): void {
            $this->setUpPool();
            $telemetry = new TestTelemetry();
            $this->poolObject->setTelemetry($telemetry);

            $allocate = function (int $amount, callable $assertion): void {
                $connections = [];
                for ($i = 0; $i < $amount; $i++) {
                    $connections[] = $this->poolObject->pop();
                }

                $assertion();

                foreach ($connections as $connection) {
                    $this->poolObject->reclaim($connection);
                }
            };

            $this->assertEquals(5, $this->poolObject->count());

            $allocate(3, function () use ($telemetry): void {
                /** @var object{values: array<int, float|int>} $openGauge */
                $openGauge = $telemetry->gauges['pool.connection.open.count'];
                /** @var object{values: array<int, float|int>} $activeGauge */
                $activeGauge = $telemetry->gauges['pool.connection.active.count'];
                /** @var object{values: array<int, float|int>} $idleGauge */
                $idleGauge = $telemetry->gauges['pool.connection.idle.count'];
                $this->assertEquals([1, 2, 3], $openGauge->values);
                $this->assertEquals([1, 2, 3], $activeGauge->values);
                $this->assertEquals([0, 0, 0], $idleGauge->values);
            });

            $this->assertEquals(5, $this->poolObject->count());

            $allocate(1, function () use ($telemetry): void {
                /** @var object{values: array<int, float|int>} $openGauge */
                $openGauge = $telemetry->gauges['pool.connection.open.count'];
                /** @var object{values: array<int, float|int>} $activeGauge */
                $activeGauge = $telemetry->gauges['pool.connection.active.count'];
                /** @var object{values: array<int, float|int>} $idleGauge */
                $idleGauge = $telemetry->gauges['pool.connection.idle.count'];
                $this->assertEquals([1, 2, 3, 3, 3, 3, 3], $openGauge->values);
                $this->assertEquals([1, 2, 3, 2, 1, 0, 1], $activeGauge->values);
                $this->assertEquals([0, 0, 0, 1, 2, 3, 2], $idleGauge->values);
            });
        });
    }
}
