<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

class PoolTest extends TestCase
{
    protected Pool $object;

    public function setUp(): void
    {
        $this->object = new Pool('test', 5, function () {
            return 'x';
        });
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
}
