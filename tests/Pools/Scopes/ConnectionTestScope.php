<?php

declare(strict_types=1);

namespace Utopia\Tests\Scopes;

use Utopia\Pools\Adapter;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

trait ConnectionTestScope
{
    abstract protected function getAdapter(): Adapter;

    abstract protected function execute(callable $callback): mixed;

    /**
     * A connection only exists as something checked out of a pool, so there is
     * no standalone construction to test.
     *
     * @return Connection<string>
     */
    private function checkedOutConnection(string $name = 'test'): Connection
    {
        return new Pool($this->getAdapter(), $name, 2, fn(): string => 'x', timeout: 0.0)->pop();
    }

    public function testConnectionIdIsNamespacedByPool(): void
    {
        $this->execute(function (): void {
            $connection = $this->checkedOutConnection('alpha');

            $this->assertStringStartsWith('alpha-', $connection->id);
        });
    }

    public function testConnectionExposesItsResource(): void
    {
        $this->execute(function (): void {
            $this->assertSame('x', $this->checkedOutConnection()->resource);
        });
    }

    public function testConnectionReclaim(): void
    {
        $this->execute(function (): void {
            $pool = new Pool($this->getAdapter(), 'test', 2, fn(): string => 'x', timeout: 0.0);

            $this->assertSame(2, $pool->count());

            $connection1 = $pool->pop();

            $this->assertSame(1, $pool->count());

            $connection2 = $pool->pop();

            $this->assertSame(0, $pool->count());

            $connection1->reclaim();

            $this->assertSame(1, $pool->count());

            $connection2->reclaim();

            $this->assertSame(2, $pool->count());
        });
    }

    public function testConnectionDestroy(): void
    {
        $this->execute(function (): void {
            $i = 0;
            $object = new Pool($this->getAdapter(), 'testDestroy', 2, function () use (&$i): string {
                ++$i;

                return $i <= 2 ? 'x' : 'y';
            }, timeout: 0.0);

            $this->assertSame(2, $object->count());

            $connection1 = $object->pop();
            $connection2 = $object->pop();

            $this->assertSame(0, $object->count());

            $this->assertSame('x', $connection1->resource);
            $this->assertSame('x', $connection2->resource);

            $connection1->destroy();
            $connection2->destroy();

            // Capacity is freed immediately; replacements are created lazily by the
            // next pop() rather than inside destroy().
            $this->assertSame(2, $object->count());

            $connection1 = $object->pop();
            $connection2 = $object->pop();

            $this->assertSame(0, $object->count());

            $this->assertSame('y', $connection1->resource);
            $this->assertSame('y', $connection2->resource);
        });
    }
}
