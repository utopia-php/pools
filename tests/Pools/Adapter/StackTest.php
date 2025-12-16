<?php

namespace Utopia\Tests\Adapter;

use Utopia\Pools\Adapter\Stack;
use Utopia\Tests\Base;

class StackTest extends Base
{
    protected function getAdapter(): Stack
    {
        return new Stack();
    }

    protected function execute(callable $callback): mixed
    {
        return $callback();
    }
}
