<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Pools\Connection;

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
}
