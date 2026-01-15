<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Pools\Adapter;
use Utopia\Tests\Scopes\ConnectionTestScope;
use Utopia\Tests\Scopes\PoolTestScope;
use Utopia\Tests\Scopes\GroupTestScope;

abstract class Base extends TestCase
{
    use ConnectionTestScope;
    use PoolTestScope;
    use GroupTestScope;

    abstract protected function getAdapter(): Adapter;

    /**
     * Execute a callback in the appropriate context for the adapter
     * (e.g., in a coroutine for Swoole, directly for Stack)
     */
    abstract protected function execute(callable $callback): mixed;
}
