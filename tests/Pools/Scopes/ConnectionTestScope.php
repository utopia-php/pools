<?php

namespace Utopia\Tests\Scopes;

use Exception;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

trait ConnectionTestScope
{
    abstract protected function getAdapter(): \Utopia\Pools\Adapter;
    abstract protected function execute(callable $callback): mixed;

    /**
     * @var Connection<string>
     */
    protected Connection $connectionObject;

    protected function setUpConnection(): void
    {
        $this->connectionObject = new Connection('x');
    }

    public function testConnectionGetID(): void
    {
        $this->execute(function (): void {
            $this->setUpConnection();
            $this->assertSame(null, $this->connectionObject->getID());

            $this->connectionObject->setID('test');

            $this->assertSame('test', $this->connectionObject->getID());
        });
    }

    public function testConnectionSetID(): void
    {
        $this->execute(function (): void {
            $this->setUpConnection();
            $this->assertSame(null, $this->connectionObject->getID());

            $this->assertInstanceOf(Connection::class, $this->connectionObject->setID('test'));

            $this->assertSame('test', $this->connectionObject->getID());
        });
    }

    public function testConnectionGetResource(): void
    {
        $this->execute(function (): void {
            $this->setUpConnection();
            $this->assertSame('x', $this->connectionObject->getResource());
        });
    }

    public function testConnectionSetResource(): void
    {
        $this->execute(function (): void {
            $this->setUpConnection();
            $this->assertSame('x', $this->connectionObject->getResource());

            $this->assertInstanceOf(Connection::class, $this->connectionObject->setResource('y'));

            $this->assertSame('y', $this->connectionObject->getResource());
        });
    }

    public function testConnectionSetPool(): void
    {
        $this->execute(function (): void {
            $this->setUpConnection();
            $pool = new Pool($this->getAdapter(), 'test', 1, fn () => 'x');

            $this->assertNull($this->connectionObject->getPool());
            $this->assertInstanceOf(Connection::class, $this->connectionObject->setPool($pool));
        });
    }

    public function testConnectionGetPool(): void
    {
        $this->execute(function (): void {
            $this->setUpConnection();
            $pool = new Pool($this->getAdapter(), 'test', 1, fn () => 'x');

            $this->assertNull($this->connectionObject->getPool());
            $this->assertInstanceOf(Connection::class, $this->connectionObject->setPool($pool));

            $pool = $this->connectionObject->getPool();

            if ($pool === null) {
                throw new Exception("Pool should never be null here.");
            }

            $this->assertInstanceOf(Pool::class, $pool);
            $this->assertSame('test', $pool->getName());
        });
    }

    public function testConnectionReclaim(): void
    {
        $this->execute(function (): void {
            $pool = new Pool($this->getAdapter(), 'test', 2, fn () => 'x');

            $this->assertEquals(2, $pool->count());

            $connection1 = $pool->pop();

            $this->assertEquals(1, $pool->count());

            $connection2 = $pool->pop();

            $this->assertEquals(0, $pool->count());

            $this->assertInstanceOf(Pool::class, $connection1->reclaim());

            $this->assertEquals(1, $pool->count());

            $this->assertInstanceOf(Pool::class, $connection2->reclaim());

            $this->assertEquals(2, $pool->count());
        });
    }

    public function testConnectionReclaimException(): void
    {
        $this->execute(function (): void {
            $this->setUpConnection();
            $this->expectException(Exception::class);
            $this->connectionObject->reclaim();
        });
    }

    public function testConnectionDestroy(): void
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

            $connection1->destroy();
            $connection2->destroy();

            $this->assertEquals(2, $object->count());

            $connection1 = $object->pop();
            $connection2 = $object->pop();

            $this->assertEquals(0, $object->count());

            $this->assertEquals('y', $connection1->getResource());
            $this->assertEquals('y', $connection2->getResource());
        });
    }
}
