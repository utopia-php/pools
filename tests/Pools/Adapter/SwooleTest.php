<?php

namespace Utopia\Tests\Adapter;

use Utopia\Pools\Adapter\Swoole;
use Utopia\Tests\Base;
use Swoole\Coroutine;

class SwooleTest extends Base
{
    protected function getAdapter(): Swoole
    {
        return new Swoole();
    }

    protected function execute(callable $callback): mixed
    {
        $result = null;
        $exception = null;

        Coroutine\run(function () use ($callback, &$result, &$exception): void {
            try {
                $result = $callback();
            } catch (\Throwable $e) {
                $exception = $e;
            }
        });

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }
}
