<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Cache;

use Nmspaced\TelemetryWeaver\Internal\Operation\ScopedOperation;
use Symfony\Component\Cache\CacheItem;

/**
 * A batch read whose items a backend produces only as they are asked for.
 *
 * `getItems()` may return a generator, and many Symfony adapters do: the read happens while
 * the caller iterates, not when the method returns. So the operation stays open until the
 * iteration is over, and it ends with what the iteration did:
 *
 *  - exhausted: the read finished, with its span and its duration;
 *  - the backend threw: the read failed, with that error;
 *  - destroyed before either happened: the operation is abandoned. The span is ended and no
 *    duration is recorded. A generator is destroyed when the caller breaks out of the loop,
 *    but also at `exit` and when a cycle is collected, possibly in some later request. None
 *    of those observed the read finishing, so none of them may record one.
 *
 * A result that is never iterated has no generator to destroy, and there is deliberately
 * no destructor here. A destructor runs at an arbitrary moment in a worker, and recording
 * a read then would report work nobody saw finish. The pool's `PendingOperations` holds the
 * operation weakly instead. A worker reset abandons it, and dropping the result drops it
 * without a trace.
 *
 * The operation is resumed while the backend produces an item and suspended while the item
 * is with the caller. So backend work, such as the query a database-backed pool runs lazily,
 * is a child of the cache read. The caller's loop body is neither a child of it nor time it
 * took.
 *
 * Single pass, like the generator it wraps.
 *
 * @internal
 *
 * @implements \IteratorAggregate<string, CacheItem>
 */
final readonly class LazyCacheRead implements \IteratorAggregate
{
    /**
     * @param iterable<string, CacheItem> $items
     * @param \Closure(CacheItem): void $onItem counts one lookup
     */
    public function __construct(
        private iterable $items,
        private ScopedOperation $operation,
        private \Closure $onItem,
    ) {}

    /**
     * @return \Generator<string, CacheItem>
     *
     * @throws \Throwable whatever the backend throws, untouched
     */
    #[\Override]
    public function getIterator(): \Generator
    {
        $this->operation->resume();

        try {
            foreach ($this->items as $key => $item) {
                $this->operation->suspend();
                ($this->onItem)($item);

                yield $key => $item;

                $this->operation->resume();
            }

            $this->operation->finish();
        } catch (\Throwable $throwable) {
            $this->operation->finish($throwable);

            throw $throwable;
        } finally {
            // A no-op after either ending above; otherwise the generator was destroyed
            // while suspended.
            $this->operation->abandon();
        }
    }
}
