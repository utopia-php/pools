<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

class ConnectionTest extends TestCase
{
    /**
     * @var Connection<string>
     */
    protected Connection $object;

    #[\Override]
    public function setUp(): void
    {
        $this->object = new Connection('x');
    }

    public function testGetID(): void
    {
        $this->assertSame('', $this->object->getID());

        $this->object->setID('test');

        $this->assertSame('test', $this->object->getID());
    }

    public function testSetID(): void
    {
        $this->assertSame('', $this->object->getID());

        $this->assertInstanceOf(Connection::class, $this->object->setID('test'));

        $this->assertSame('test', $this->object->getID());
    }

    public function testGetResource(): void
    {
        $this->assertSame('x', $this->object->getResource());
    }

    public function testSetResource(): void
    {
        $this->assertSame('x', $this->object->getResource());

        $this->assertInstanceOf(Connection::class, $this->object->setResource('y'));

        $this->assertSame('y', $this->object->getResource());
    }

    public function testSetPool(): void
    {
        $pool = new Pool('test', 1, fn () => 'x');

        $this->assertNull($this->object->getPool());
        $this->assertInstanceOf(Connection::class, $this->object->setPool($pool));
    }

    public function testGetPool(): void
    {
        $pool = new Pool('test', 1, fn () => 'x');

        $this->assertNull($this->object->getPool());
        $this->assertInstanceOf(Connection::class, $this->object->setPool($pool));

        $pool = $this->object->getPool();

        if ($pool === null) {
            throw new Exception("Pool should never be null here.");
        }

        $this->assertInstanceOf(Pool::class, $pool);
        $this->assertSame('test', $pool->getName());
    }

    public function testReclaim(): void
    {
        $pool = new Pool('test', 2, fn () => 'x');

        $this->assertSame(2, $pool->count());

        $connection1 = $pool->pop();

        $this->assertSame(1, $pool->count());

        $connection2 = $pool->pop();

        $this->assertSame(0, $pool->count());

        $this->assertInstanceOf(Pool::class, $connection1->reclaim());

        $this->assertSame(1, $pool->count());

        $this->assertInstanceOf(Pool::class, $connection2->reclaim());

        $this->assertSame(2, $pool->count());
    }

    public function testReclaimException(): void
    {
        $this->expectException(Exception::class);
        $this->object->reclaim();
    }

    public function testDestroy(): void
    {
        $i = 0;
        $object = new Pool('testDestroy', 2, function () use (&$i) {
            $i++;
            return $i <= 2 ? 'x' : 'y';
        });

        $this->assertSame(2, $object->count());

        $connection1 = $object->pop();
        $connection2 = $object->pop();

        $this->assertSame(0, $object->count());

        $this->assertSame('x', $connection1->getResource());
        $this->assertSame('x', $connection2->getResource());

        $connection1->destroy();
        $connection2->destroy();

        $this->assertSame(2, $object->count());

        $connection1 = $object->pop();
        $connection2 = $object->pop();

        $this->assertSame(0, $object->count());

        $this->assertSame('y', $connection1->getResource());
        $this->assertSame('y', $connection2->getResource());
    }
}
