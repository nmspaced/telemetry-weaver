<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Cache;

use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Internal\Operation\PendingOperations;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Exception\BadMethodCallException;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\CallbackInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

// @mago-expect lint:too-many-methods — the decorator must implement the complete cache interfaces
readonly class TraceableCachePool implements AdapterInterface, CacheInterface, PruneableInterface, ResettableInterface
{
    /**
     * @param PendingOperations $pending batch reads not yet iterated to the end. A pool
     *                                   derived through `withSubNamespace()` shares its
     *                                   parent's, because only the parent is reset
     */
    public function __construct(
        protected AdapterInterface $delegate,
        protected CacheTelemetry $cacheTelemetry,
        protected string $poolName = 'cache.app',
        protected PendingOperations $pending = new PendingOperations(),
    ) {}

    /**
     * @param mixed $key
     *
     * @throws \Throwable
     */
    #[\Override]
    public function getItem(mixed $key): CacheItem
    {
        $operation = __FUNCTION__;

        return $this->run(
            $operation,
            \is_string($key) ? ['cache.key' => $key] : [],
            /** @throws InvalidArgumentException */
            function (Span $span) use ($operation, $key): CacheItem {
                // @phpstan-ignore argument.type (Symfony accepts mixed keys and delegates validation to the adapter; PSR PHPDoc narrows it to string.)
                $item = $this->delegate->getItem($key);
                $this->lookup($operation, $item->isHit(), $span);

                return $item;
            },
        );
    }

    /**
     * Stays as lazy as the pool it decorates. The operation ends when the caller has read
     * the items, not when this method returns, because that is when a lazy backend reads.
     * Hits are counted as items are produced, and time spent in the caller's loop is
     * measured by neither the span's children nor the duration.
     *
     * @param array<array-key, string> $keys
     *
     * @return iterable<string, CacheItem>
     * @throws \Throwable
     */
    #[\Override]
    public function getItems(array $keys = []): iterable
    {
        return $this->cacheTelemetry->read(
            $this->pending,
            $this->poolName,
            __FUNCTION__,
            ['cache.keys' => \array_values($keys)],
            /**
             * @return iterable<string, CacheItem>
             * @throws InvalidArgumentException
             */
            fn(): iterable => $this->delegate->getItems($keys),
        );
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function clear(string $prefix = ''): bool
    {
        return $this->run(__FUNCTION__, ['cache.prefix' => $prefix], fn(): bool => $this->delegate->clear($prefix));
    }

    /**
     * @template T
     * @param (callable(CacheItemInterface,bool):T)|(callable(ItemInterface,bool):T)|CallbackInterface<T> $callback
     * @param array<array-key, mixed>|null $metadata
     *
     * @return T
     * @throws \Throwable
     */
    #[\Override]
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        $delegate = $this->delegate;
        if (!$delegate instanceof CacheInterface) {
            throw new BadMethodCallException(\sprintf(
                'Cannot call "%s::get()": the inner pool does not implement "%s".',
                \get_debug_type($delegate),
                CacheInterface::class,
            ));
        }

        $operation = __FUNCTION__;

        return $this->run(
            $operation,
            ['cache.key' => $key],
            /** @throws InvalidArgumentException */
            function (Span $span) use ($operation, $delegate, $key, $callback, $beta, &$metadata): mixed {
                $hit = true;
                $compute = static function (ItemInterface $item, bool &$save) use ($callback, &$hit): mixed {
                    $hit = $item->isHit();

                    return $callback($item, $save);
                };
                $value = $delegate->get($key, $compute, $beta, $metadata);
                $this->lookup($operation, $hit, $span);

                return $value;
            },
        );
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function delete(string $key): bool
    {
        return $this->run(
            __FUNCTION__,
            ['cache.key' => $key],
            /** @throws InvalidArgumentException */
            fn(): bool => $this->delegate instanceof CacheInterface
                ? $this->delegate->delete($key)
                : $this->delegate->deleteItem($key),
        );
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function hasItem(string $key): bool
    {
        $operation = __FUNCTION__;

        return $this->run(
            $operation,
            ['cache.key' => $key],
            /** @throws InvalidArgumentException */
            function (Span $span) use ($operation, $key): bool {
                $hit = $this->delegate->hasItem($key);
                $this->lookup($operation, $hit, $span);

                return $hit;
            },
        );
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function deleteItem(string $key): bool
    {
        return $this->run(
            __FUNCTION__,
            ['cache.key' => $key],
            /** @throws InvalidArgumentException */
            fn(): bool => $this->delegate->deleteItem($key),
        );
    }

    /**
     * @param array<array-key, string> $keys
     *
     * @throws \Throwable
     */
    #[\Override]
    public function deleteItems(array $keys): bool
    {
        return $this->run(
            __FUNCTION__,
            ['cache.keys' => \array_values($keys)],
            /** @throws InvalidArgumentException */
            fn(): bool => $this->delegate->deleteItems($keys),
        );
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function save(CacheItemInterface $item): bool
    {
        return $this->run(__FUNCTION__, ['cache.key' => $item->getKey()], fn(): bool => $this->delegate->save($item));
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->run(__FUNCTION__, ['cache.key' => $item->getKey()], fn(): bool => $this->delegate->saveDeferred(
            $item,
        ));
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function commit(): bool
    {
        return $this->run(__FUNCTION__, [], $this->delegate->commit(...));
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function prune(): bool
    {
        $delegate = $this->delegate;

        return $delegate instanceof PruneableInterface && $this->run(__FUNCTION__, [], $delegate->prune(...));
    }

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function reset(): void
    {
        // The worker boundary: a batch still unread belongs to a request that is over.
        $this->pending->abandonAll();

        if (!$this->delegate instanceof ResetInterface) {
            return;
        }

        $this->delegate->reset();
    }

    /**
     * @template T
     * @param non-empty-string $operation
     * @param array<non-empty-string, bool|float|int|string|list<string>> $attributes
     * @param \Closure(Span): T $callback
     * @param-immediately-invoked-callable $callback
     *
     * @return T
     * @throws \Throwable
     */
    protected function run(string $operation, array $attributes, \Closure $callback): mixed
    {
        return $this->cacheTelemetry->run($this->poolName, $operation, $attributes, $callback);
    }

    /** @param  non-empty-string  $operation */
    private function lookup(string $operation, bool $hit, ?Span $span = null): void
    {
        $this->cacheTelemetry->lookup($this->poolName, $operation, $hit, $span);
    }
}
