<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Trace\IdGeneratorInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/**
 * Tracing choices no `OTEL_*` variable can express: sampler, id generator and extra span
 * processors. Each defaults to the bundle's own.
 *
 * @internal
 */
final readonly class TraceDecisions
{
    /**
     * @param iterable<SpanProcessorInterface> $spanProcessors run before the bundle's batch processor
     */
    public function __construct(
        public ?SamplerInterface $sampler = null,
        public ?IdGeneratorInterface $idGenerator = null,
        public iterable $spanProcessors = [],
    ) {}
}
