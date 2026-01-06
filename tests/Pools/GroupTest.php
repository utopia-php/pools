<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Pools\Pool;
use Utopia\Pools\Group;

class GroupTest extends TestCase
{
    protected Group $object;

    #[\Override]
    public function setUp(): void
    {
        $this->object = new Group();
    }

    public function testAdd(): void
    {
        $this->object->add(new Pool('test', 1, fn () => 'x'));

        $this->assertInstanceOf(Pool::class, $this->object->get('test'));
    }

    public function testGet(): void
    {
        $this->object->add(new Pool('test', 1, fn () => 'x'));

        $this->assertInstanceOf(Pool::class, $this->object->get('test'));

        $this->expectException(Exception::class);

        $this->assertInstanceOf(Pool::class, $this->object->get('testx'));
    }

    public function testRemove(): void
    {
        $this->object->add(new Pool('test', 1, fn () => 'x'));

        $this->assertInstanceOf(Pool::class, $this->object->get('test'));

        $this->object->remove('test');

        $this->expectException(Exception::class);

        $this->assertInstanceOf(Pool::class, $this->object->get('test'));
    }

    public function testReset(): void
    {
        $this->object->add(new Pool('test', 5, fn () => 'x'));

        $this->assertSame(5, $this->object->get('test')->count());

        $this->object->get('test')->pop();
        $this->object->get('test')->pop();
        $this->object->get('test')->pop();

        $this->assertSame(2, $this->object->get('test')->count());

        $this->object->reclaim();

        $this->assertSame(5, $this->object->get('test')->count());
    }

    public function testReconnectAttempts(): void
    {
        $this->object->add(new Pool('test', 5, fn () => 'x'));

        $this->assertSame(3, $this->object->get('test')->getReconnectAttempts());

        $this->object->setReconnectAttempts(5);

        $this->assertSame(5, $this->object->get('test')->getReconnectAttempts());
    }

    public function testReconnectSleep(): void
    {
        $this->object->add(new Pool('test', 5, fn () => 'x'));

        $this->assertSame(1, $this->object->get('test')->getReconnectSleep());

        $this->object->setReconnectSleep(2);

        $this->assertSame(2, $this->object->get('test')->getReconnectSleep());
    }

    public function testUse(): void
    {
        $pool1 = new Pool('pool1', 1, fn () => '1');
        $pool2 = new Pool('pool2', 1, fn () => '2');
        $pool3 = new Pool('pool3', 1, fn () => '3');

        $this->object->add($pool1);
        $this->object->add($pool2);
        $this->object->add($pool3);

        $this->assertSame(1, $pool1->count());
        $this->assertSame(1, $pool2->count());
        $this->assertSame(1, $pool3->count());

        // @phpstan-ignore argument.type
        $this->object->use(['pool1', 'pool3'], function ($one, $three) use ($pool1, $pool2, $pool3): void {
            $this->assertSame('1', $one);
            $this->assertSame('3', $three);

            $this->assertSame(0, $pool1->count());
            $this->assertSame(1, $pool2->count());
            $this->assertSame(0, $pool3->count());
        });

        $this->assertSame(1, $pool1->count());
        $this->assertSame(1, $pool2->count());
        $this->assertSame(1, $pool3->count());
    }
}
