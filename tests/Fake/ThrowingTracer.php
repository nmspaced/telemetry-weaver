<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\TracerInterface;

/**
 * A tracer whose span creation fails, standing in for a broken SDK build.
 */
final readonly class ThrowingTracer implements TracerInterface
{
    public function __construct(
        private \Throwable $failure = new \RuntimeException('tracer is broken'),
    ) {}

    /** @throws \Throwable always */
    #[\Override]
    public function spanBuilder(string $spanName): SpanBuilderInterface
    {
        throw $this->failure;
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return true;
    }
}
