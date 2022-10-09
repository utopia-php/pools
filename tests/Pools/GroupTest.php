<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;
use Utopia\Pools\Group;

class GroupTest extends TestCase
{
    protected ?Group $object;

    public function setUp(): void
    {
        $this->object = new Group();
    }

    public function tearDown(): void
    {
        $this->object = null;
    }

    public function testAdd()
    {
        $this->object->add(new Pool('test', 1, function() {
            return 'x';
        }));

        $this->assertInstanceOf(Pool::class, $this->object->get('test'));
    }

    public function testGet()
    {
        $this->object->add(new Pool('test', 1, function() {
            return 'x';
        }));

        $this->assertInstanceOf(Pool::class, $this->object->get('test'));

        $this->expectException(Exception::class);
        
        $this->assertInstanceOf(Pool::class, $this->object->get('testx'));
    }

    public function testRemove()
    {
        $this->object->add(new Pool('test', 1, function() {
            return 'x';
        }));

        $this->assertInstanceOf(Pool::class, $this->object->get('test'));

        $this->object->remove('test');

        $this->expectException(Exception::class);
        
        $this->assertInstanceOf(Pool::class, $this->object->get('test'));
    }

    public function testFill()
    {
        $this->object->add(new Pool('test', 1, function() {
            return 'x';
        }));

        // Pool should be empty
        try {
            $this->object->get('test')->pop();
            $this->fail('Exception not thrown');
        } catch (Exception $e) {
            $this->assertInstanceOf(Exception::class, $e);
        }

        $this->object->fill();

        $this->assertInstanceOf(Connection::class, $this->object->get('test')->pop());
    }

    public function testReset()
    {
        $this->object->add(new Pool('test', 5, function() {
            return 'x';
        }));

        $this->assertEquals(0, $this->object->get('test')->count());

        $this->object->fill();

        $this->assertEquals(5, $this->object->get('test')->count());

        $this->object->get('test')->pop();
        $this->object->get('test')->pop();
        $this->object->get('test')->pop();

        $this->assertEquals(2, $this->object->get('test')->count());

        $this->object->reset();

        $this->assertEquals(5, $this->object->get('test')->count());
    }

    public function testReconnectAttempts()
    {
        $this->object->add(new Pool('test', 5, function() {
            return 'x';
        }));

        $this->assertEquals(3, $this->object->get('test')->getReconnectAttempts());
        
        $this->object->setReconnectAttempts(5);
        
        $this->assertEquals(5, $this->object->get('test')->getReconnectAttempts());
    }

    public function testReconnectSleep()
    {
        $this->object->add(new Pool('test', 5, function() {
            return 'x';
        }));

        $this->assertEquals(1, $this->object->get('test')->getReconnectSleep());
        
        $this->object->setReconnectSleep(2);
        
        $this->assertEquals(2, $this->object->get('test')->getReconnectSleep());
    }
}
