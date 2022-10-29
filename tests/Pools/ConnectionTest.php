<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

class ConnectionTest extends TestCase
{
    protected ?Connection $object;

    public function setUp(): void
    {
        $this->object = new Connection('x');
    }

    public function tearDown(): void
    {
        $this->object = null;
    }

    public function testGetID()
    {
        $this->assertEquals(null, $this->object->getID());
        
        $this->object->setID('test');
        
        $this->assertEquals('test', $this->object->getID());
    }

    public function testSetID()
    {
        $this->assertEquals(null, $this->object->getID());
        
        $this->assertInstanceOf(Connection::class, $this->object->setID('test'));
        
        $this->assertEquals('test', $this->object->getID());
    }

    public function testGetResource()
    {
        $this->assertEquals('x', $this->object->getResource());
    }

    public function testSetResource()
    {
        $this->assertEquals('x', $this->object->getResource());
        
        $this->assertInstanceOf(Connection::class, $this->object->setResource('y'));
        
        $this->assertEquals('y', $this->object->getResource());
    }

    public function testSetPool()
    {
        $pool = new Pool('test', 1, function () {
            return 'x';
        });

        $this->assertNull($this->object->getPool());
        $this->assertInstanceOf(Connection::class, $this->object->setPool($pool));
    }

    public function testGetPool()
    {
        $pool = new Pool('test', 1, function () {
            return 'x';
        });

        $this->assertNull($this->object->getPool());
        $this->assertInstanceOf(Connection::class, $this->object->setPool($pool));
        $this->assertInstanceOf(Pool::class, $this->object->getPool());
        $this->assertEquals('test', $this->object->getPool()->getName());
    }

    public function testReclame()
    {
        $pool = new Pool('test', 1, function () {
            return 'x';
        });

        $pool->fill();

        $this->assertEquals(1, $pool->count());

        $connection = $pool->pop();

        $this->assertEquals(0, $pool->count());
     
        $this->assertInstanceOf(Pool::class, $connection->reclaim());
     
        $this->assertEquals(1, $pool->count());
    }
}
