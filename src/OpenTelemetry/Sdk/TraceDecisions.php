<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Trace\IdGeneratorInterface;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/**
 * What an application decided about tracing that no `OTEL_*` variable can express.
 *
 * One object rather than three arguments on the provider factory, because they are one
 * decision in three parts: whether a span is recorded, what it is called by, and who else sees
 * it. All three are meaningless when the application replaced the whole tracer provider, and
 * the configuration refuses them together for that reason.
 *
 * Each part is absent by default, and absent means the bundle's own: `OTEL_TRACES_SAMPLER`,
 * the SDK's random ids, and no processors but the batch one built in
 * {@see TracerProviderFactory}.
 *
 * @internal
 */
final readonly class TraceDecisions
{
    /**
     * @param iterable<SpanProcessorInterface> $spanProcessors added in front of the bundle's own,
     *                                                         so one that edits a span as it ends
     *                                                         sees it before it is queued
     */
    public function __construct(
        public ?SamplerInterface $sampler = null,
        public ?IdGeneratorInterface $idGenerator = null,
        public iterable $spanProcessors = [],
    ) {}
}
