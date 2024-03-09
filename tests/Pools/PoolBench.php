<?php

namespace Utopia\Http;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use Utopia\Pools\Pool;

final class PoolBench
{
    public function provideRoutesToMatch(): iterable
    {
        foreach ([
            'xs' => [ 'name' => 'xs', 'size' => 10, 'concurrency' => 10, 'actions' => 100000 ],
            'sm' => [ 'name' => 'sm', 'size' => 100, 'concurrency' => 100, 'actions' => 100000 ],
            'md' => [ 'name' => 'md', 'size' => 1000, 'concurrency' => 1000, 'actions' => 100000 ],
            'lg' => [ 'name' => 'lg', 'size' => 10000, 'concurrency' => 10000, 'actions' => 100000 ],
            'xl' => [ 'name' => 'xl', 'size' => 100000, 'concurrency' => 100000, 'actions' => 100000 ],
            'xxl' => [ 'name' => 'xxl', 'size' => 1000000, 'concurrency' => 1000000, 'actions' => 100000 ],
        ] as $name => $data) {
            yield $name => $data;
        }
    }

    #[Iterations(50)]
    #[ParamProviders('provideRoutesToMatch')]
    public function benchRouter(array $data): void
    {
        $pool = new Pool('test', $data['size'], function () {
            return 'x';
        });

        for($i = 0; $i < $data['actions']; $i++) {
            $connection = $pool->pop();

            if($i % $data['concurrency'] === 0) {
                $pool->reclaim();
            }
        }
    }
}
