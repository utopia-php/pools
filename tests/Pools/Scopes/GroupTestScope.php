<?php

namespace Utopia\Tests\Scopes;

use Exception;
use Utopia\Pools\Connection;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

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
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 1, fn() => 'x'));

            $this->assertInstanceOf(Pool::class, $this->groupObject->get('test'));
        });
    }

    public function testGroupGet(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 1, fn() => 'x'));

            $this->assertInstanceOf(Pool::class, $this->groupObject->get('test'));

            $this->expectException(Exception::class);

            $this->assertInstanceOf(Pool::class, $this->groupObject->get('testx'));
        });
    }

    public function testGroupRemove(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 1, fn() => 'x'));

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
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 5, fn() => 'x'));

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
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 5, fn() => 'x'));

            $this->assertSame(3, $this->groupObject->get('test')->getReconnectAttempts());

            $this->groupObject->setReconnectAttempts(5);

            $this->assertSame(5, $this->groupObject->get('test')->getReconnectAttempts());
        });
    }

    public function testGroupReconnectSleep(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $this->groupObject->add(new Pool($this->getAdapter(), 'test', 5, fn() => 'x'));

            $this->assertSame(1, $this->groupObject->get('test')->getReconnectSleep());

            $this->groupObject->setReconnectSleep(2);

            $this->assertSame(2, $this->groupObject->get('test')->getReconnectSleep());
        });
    }

    public function testGroupUse(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $pool1 = new Pool($this->getAdapter(), 'pool1', 1, fn() => '1');
            $pool2 = new Pool($this->getAdapter(), 'pool2', 1, fn() => '2');
            $pool3 = new Pool($this->getAdapter(), 'pool3', 1, fn() => '3');

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

    public function testGroupUseReclaimsEarlierConnectionWhenLaterPoolIsMissing(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $created = 0;
            $resources = [];
            $pool = new Pool($this->getAdapter(), 'pool1', 1, function () use (&$created, &$resources) {
                $created++;
                $resources[] = new readonly class ('resource-' . $created) implements \Stringable {
                    public function __construct(private string $name) {}

                    public function __toString(): string
                    {
                        return $this->name;
                    }
                };
                return $resources[$created - 1];
            });

            $this->groupObject->add($pool);

            try {
                $this->groupObject->use(['pool1', 'missing'], function (): void {});
                $this->fail('Should have thrown');
            } catch (Exception) {
                // expected
            }

            $this->assertSame(1, $pool->count());

            $pool->use(function (\Stringable $resource) use (&$resources): void {
                $this->assertSame($resources[0], $resource);
                $this->assertSame('resource-1', (string) $resource);
            });
            $this->assertSame(1, $created);
        });
    }

    public function testGroupUseRecordsUseDurationTelemetry(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();
            $telemetry = new TestTelemetry();

            $this->groupObject
                ->add(new Pool($this->getAdapter(), 'pool1', 1, fn() => '1'))
                ->setTelemetry($telemetry);

            $this->assertArrayNotHasKey('pool.connection.use_time', $telemetry->histograms);

            $this->groupObject->use(['pool1'], function (...$resources): void {
                $this->assertSame(['1'], $resources);
            });

            $this->assertArrayHasKey('pool.connection.use_time', $telemetry->histograms);
            /** @var object{values: array<int, float|int>} $useHistogram */
            $useHistogram = $telemetry->histograms['pool.connection.use_time'];
            $this->assertCount(1, $useHistogram->values);
        });
    }

    public function testGroupUseReleasesEveryConnectionWhenCleanupThrows(): void
    {
        $this->execute(function (): void {
            $this->setUpGroup();

            $pool1 = new class ($this->getAdapter(), 'pool1', 1, fn() => '1') extends Pool {
                public bool $released = false;

                public function release(Connection $connection, bool $failed = false, ?float $start = null): static
                {
                    $this->released = true;
                    return parent::release($connection, $failed, $start);
                }
            };
            $pool2 = new class ($this->getAdapter(), 'pool2', 1, fn() => '2') extends Pool {
                public bool $released = false;

                public function release(Connection $connection, bool $failed = false, ?float $start = null): static
                {
                    $this->released = true;
                    throw new \RuntimeException('Release failed');
                }
            };

            $this->groupObject
                ->add($pool1)
                ->add($pool2);

            $error = null;
            try {
                $this->groupObject->use(['pool1', 'pool2'], function (...$resources): void {
                    $this->assertSame(['1', '2'], $resources);
                });
            } catch (\RuntimeException $error) {
            }

            $this->assertInstanceOf(\RuntimeException::class, $error);
            $this->assertSame('Release failed', $error->getMessage());
            $this->assertTrue($pool1->released);
            $this->assertTrue($pool2->released);
            $this->assertSame(1, $pool1->count());
        });
    }
}
