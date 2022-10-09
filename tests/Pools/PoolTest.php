<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

class PoolTest extends TestCase
{
    protected ?Pool $object;

    public function setUp(): void
    {
        $this->object = new Pool('test', 5, function() {
            return 'x';
        });
    }

    public function tearDown(): void
    {
        $this->object = null;
    }

    public function testGetName()
    {
        $this->assertEquals('test', $this->object->getName());
    }

    public function testGetSize()
    {
        $this->assertEquals(5, $this->object->getSize());
    }

    public function testGetRecconectAttempts()
    {
        $this->assertEquals(3, $this->object->getRecconectAttempts());
    }

    public function testSetRecconectAttempts()
    {
        $this->assertEquals(3, $this->object->getRecconectAttempts());
        
        $this->object->setReconnectAttempts(20);

        $this->assertEquals(20, $this->object->getRecconectAttempts());
    }

    public function testGetRecconectSleep()
    {
        $this->assertEquals(1, $this->object->getRecconectSleep());
    }

    public function testSetRecconectSleep()
    {
        $this->assertEquals(1, $this->object->getRecconectSleep());
        
        $this->object->setRecconectSleep(20);

        $this->assertEquals(20, $this->object->getRecconectSleep());
    }

    public function testFill()
    {
        $this->assertEquals(0, $this->object->count());
        
        $this->object->fill();

        $this->assertEquals(5, $this->object->count());
    }

    public function testFillFailure()
    {
        $this->object = new Pool('test', 5, function() {
            throw new Exception();
        });

        $start = microtime(true);

        $this->assertEquals(0, $this->object->count());
        
        try {
            $this->object->fill();
            $this->fail('Exception not thrown');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }
    
        $time = microtime(true) - $start;

        $this->assertGreaterThan(2, $time);
    }

    public function testPop()
    {
        // Pool should be empty
        try {
            $this->object->pop();
            $this->fail('Exception not thrown');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }

        $this->object->fill();

        $this->assertEquals(5, $this->object->count());

        $connection = $this->object->pop();

        $this->assertEquals(4, $this->object->count());

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertEquals('x', $connection->getResource());
    }

    public function testPush()
    {
        // Pool should be empty
        try {
            $this->object->pop();
            $this->fail('Exception not thrown');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }

        $this->object->fill();

        $this->assertEquals(5, $this->object->count());

        $connection = $this->object->pop();

        $this->assertEquals(4, $this->object->count());

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertEquals('x', $connection->getResource());

        $this->assertInstanceOf(Pool::class, $this->object->push($connection));

        $this->assertEquals(5, $this->object->count());
    }

    public function testCount()
    {
        $this->assertEquals(0, $this->object->count());
     
        $this->object->fill();

        $this->assertEquals(5, $this->object->count());

        $connection = $this->object->pop();

        $this->assertEquals(4, $this->object->count());

        $this->object->push($connection);

        $this->assertEquals(5, $this->object->count());
    }

    public function testReset()
    {
        $this->assertEquals(0, $this->object->count());

        $this->object->fill();

        $this->assertEquals(5, $this->object->count());

        $this->object->pop();
        $this->object->pop();
        $this->object->pop();

        $this->assertEquals(2, $this->object->count());

        $this->object->reset();

        $this->assertEquals(5, $this->object->count());
    }

    public function testIsEmpty()
    {
        $this->assertEquals(true, $this->object->isEmpty());

        $this->object->fill();

        $this->assertEquals(false, $this->object->isEmpty());

        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();

        $this->assertEquals(true, $this->object->isEmpty());
    }

    public function testIsFull()
    {
        $this->assertEquals(false, $this->object->isFull());

        $this->object->fill();

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

        $this->object->reset();

        $this->assertEquals(true, $this->object->isFull());

        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();
        $this->object->pop();

        $this->assertEquals(false, $this->object->isFull());

        $this->object->fill();

        $this->assertEquals(true, $this->object->isFull());
    }
}
