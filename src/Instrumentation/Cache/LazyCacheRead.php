<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Cache;

use Nmspaced\TelemetryWeaver\Internal\Operation\ScopedOperation;
use Symfony\Component\Cache\CacheItem;

/**
 * A lazy `getItems()` result whose operation ends with the iteration: success when
 * exhausted, the error when the backend throws, abandoned (no duration) when destroyed
 * early. The operation is active only while the backend produces an item. There is no
 * destructor; a worker reset abandons unread results through `PendingOperations`.
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
            $this->operation->abandon();
        }
    }
}
