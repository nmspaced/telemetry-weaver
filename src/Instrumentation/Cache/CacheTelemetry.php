<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Cache;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\CacheOperationBuckets;
use OpenTelemetry\API\Metrics\CounterInterface;

/**
 * @internal Cache semantics; execution ownership belongs to the same API applications use.
 */
final readonly class CacheTelemetry
{
    private CounterInterface $lookups;

    private Duration $duration;

    public function __construct(
        private Telemetry $telemetry,
        CacheOperationBuckets $buckets = new CacheOperationBuckets(),
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
     * @param non-empty-string $operation
     */
    public function lookup(string $pool, string $operation, bool $hit, ?Span $span = null): void
    {
        $span?->attribute('cache.hit', $hit);
        $this->lookups->add(1, ['cache.pool.name' => $pool, 'cache.operation.name' => $operation, 'cache.hit' => $hit]);
    }
}
