<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Cache;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Operation\PendingOperations;
use OpenTelemetry\API\Metrics\CounterInterface;
use Symfony\Component\Cache\CacheItem;

/**
 * @internal Cache semantics; execution ownership belongs to the same API applications use.
 */
final readonly class CacheTelemetry
{
    private CounterInterface $lookups;

    private Duration $duration;

    public function __construct(
        private BoundaryTelemetry $telemetry,
        OperationBuckets $buckets = DefaultBuckets::Cache,
    ) {
        $this->lookups = $telemetry->metrics()->counter('cache.lookup.count', '{lookup}', 'Number of cache lookups.');
        $this->duration = $telemetry->metrics()->duration(
            'cache.operation.duration',
            $buckets->unit(),
            $buckets->boundaries(),
            'Duration of cache operations.',
        );
    }

    /**
     * @template T
     * @param non-empty-string $operation
     * @param array<non-empty-string, bool|float|int|string|list<string>> $spanAttributes
     * @param \Closure(Span): T $callback
     * @return T
     * @throws \Throwable
     */
    public function run(string $pool, string $operation, array $spanAttributes, \Closure $callback): mixed
    {
        $attributes = ['cache.pool.name' => $pool, 'cache.operation.name' => $operation];

        return $this->telemetry
            ->operation(\sprintf('cache.%s', $operation))
            ->attributes($attributes + $spanAttributes)
            ->duration($this->duration, attributes: $attributes)
            ->run(static fn(OperationContext $context): mixed => $callback($context->span()));
    }

    /**
     * A batch read measured until the caller has read it; see {@see LazyCacheRead}. An array
     * result ends the operation immediately.
     *
     * @param PendingOperations $pending the pool's own, abandoned by its `reset()`
     * @param non-empty-string $operation
     * @param array<non-empty-string, bool|float|int|string|list<string>> $spanAttributes
     * @param \Closure(): iterable<string, CacheItem> $read
     *
     * @return iterable<string, CacheItem>
     *
     * @throws \Throwable whatever the backend throws, untouched
     */
    public function read(
        PendingOperations $pending,
        string $pool,
        string $operation,
        array $spanAttributes,
        \Closure $read,
    ): iterable {
        $attributes = ['cache.pool.name' => $pool, 'cache.operation.name' => $operation];

        $running = $this->telemetry
            ->boundary(\sprintf('cache.%s', $operation))
            ->attributes($attributes + $spanAttributes)
            ->duration($this->duration, attributes: $attributes)
            ->start();

        try {
            $items = $read();
        } catch (\Throwable $throwable) {
            $running->finish($throwable);

            throw $throwable;
        }

        $onItem = function (CacheItem $item) use ($pool, $operation): void {
            $this->lookup($pool, $operation, $item->isHit());
        };

        if (\is_array($items)) {
            foreach ($items as $item) {
                $onItem($item);
            }

            $running->finish();

            return $items;
        }

        $running->suspend();

        $pending->add($running);

        return new LazyCacheRead($items, $running, $onItem);
    }

    /**
     * @param non-empty-string $operation
     */
    public function lookup(string $pool, string $operation, bool $hit, ?Span $span = null): void
    {
        $span?->attribute('cache.hit', $hit);
        $this->lookups->add(1, ['cache.pool.name' => $pool, 'cache.operation.name' => $operation, 'cache.hit' => $hit]);
    }
}
