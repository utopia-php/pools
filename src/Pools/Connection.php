<?php

declare(strict_types=1);

namespace Utopia\Pools;

/**
 * A resource checked out of a pool.
 *
 * Immutable: identity, resource and owning pool are fixed at construction. A
 * connection held by one coroutine cannot be repointed at another pool or have
 * its resource swapped underneath it, and the pool is never null, so returning
 * a connection can no longer fail for want of an owner.
 *
 * @template TResource
 */
final readonly class Connection
{
    /**
     * @param  TResource  $resource
     * @param  Pool<TResource>  $pool
     */
    public function __construct(
        public string $id,
        public mixed $resource,
        private Pool $pool,
    ) {}

    /**
     * Return this connection to its pool for reuse.
     */
    public function reclaim(): void
    {
        $this->pool->reclaim($this);
    }

    /**
     * Discard this connection and free its capacity for a replacement.
     */
    public function destroy(): void
    {
        $this->pool->destroy($this);
    }
}
