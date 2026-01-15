<?php

namespace Utopia\Tests\Scopes;

use Exception;
use Utopia\Pools\Pool;
use Utopia\Pools\Group;

trait GroupTestScope
{
    abstract protected function getAdapter(): \Utopia\Pools\Adapter;
    abstract protected function execute(callable $callback): mixed;

    protected Group $groupObject;

    protected function setUpGroup(): void
    {
        $this->groupObject = new Group();
    }

    public function testGroupAdd(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 1, fn () => 'x'));

            $this->assertInstanceOf(Pool::class, $this->groupObject->get('test'));
        });
    }

    public function testGroupGet(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 1, fn () => 'x'));

            $this->assertInstanceOf(Pool::class, $this->groupObject->get('test'));

            $this->expectException(Exception::class);

            $this->assertInstanceOf(Pool::class, $this->groupObject->get('testx'));
        });
    }

    public function testGroupRemove(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 1, fn () => 'x'));

            $this->assertInstanceOf(Pool::class, $this->groupObject->get('test'));

            $this->groupObject->remove('test');

            $this->expectException(Exception::class);

            $this->assertInstanceOf(Pool::class, $this->groupObject->get('test'));
        });
    }

    public function testGroupReset(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 5, fn () => 'x'));

            $this->assertSame(5, $this->groupObject->get('test')->count());

            $this->groupObject->get('test')->pop();
            $this->groupObject->get('test')->pop();
            $this->groupObject->get('test')->pop();

            $this->assertSame(2, $this->groupObject->get('test')->count());

            $this->groupObject->reclaim();

            $this->assertSame(5, $this->groupObject->get('test')->count());
        });
    }

    public function testGroupReconnectAttempts(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 5, fn () => 'x'));

            $this->assertSame(3, $this->groupObject->get('test')->getReconnectAttempts());

            $this->groupObject->setReconnectAttempts(5);

            $this->assertSame(5, $this->groupObject->get('test')->getReconnectAttempts());
        });
    }

    public function testGroupReconnectSleep(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 5, fn () => 'x'));

            $this->assertSame(1, $this->groupObject->get('test')->getReconnectSleep());

            $this->groupObject->setReconnectSleep(2);

            $this->assertSame(2, $this->groupObject->get('test')->getReconnectSleep());
        });
    }

    public function testGroupUse(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $pool1 = new Pool($this->getAdapter(), 'pool1', 1, fn () => '1');
            $pool2 = new Pool($this->getAdapter(), 'pool2', 1, fn () => '2');
            $pool3 = new Pool($this->getAdapter(), 'pool3', 1, fn () => '3');

            $this->groupObject->add($pool1);
            $this->groupObject->add($pool2);
            $this->groupObject->add($pool3);

            $this->assertSame(1, $pool1->count());
            $this->assertSame(1, $pool2->count());
            $this->assertSame(1, $pool3->count());

            // @phpstan-ignore argument.type
            $this->groupObject->use(['pool1', 'pool3'], function ($one, $three) use ($pool1, $pool2, $pool3): void {
                $this->assertSame('1', $one);
                $this->assertSame('3', $three);

                $this->assertSame(0, $pool1->count());
                $this->assertSame(1, $pool2->count());
                $this->assertSame(0, $pool3->count());
            });

            $this->assertSame(1, $pool1->count());
            $this->assertSame(1, $pool2->count());
            $this->assertSame(1, $pool3->count());
        });
    }
}
