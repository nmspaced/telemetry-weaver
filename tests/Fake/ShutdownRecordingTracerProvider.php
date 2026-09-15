<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/** An application's own tracer provider, counting what the pipeline asks of it. */
final class ShutdownRecordingTracerProvider implements TracerProviderInterface
{
    public int $shutdowns = 0;

    /** @param iterable<mixed, mixed> $attributes */
    #[\Override]
    public function getTracer(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        iterable $attributes = [],
    ): TracerInterface {
        return NoopTracer::getInstance();
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    #[\Override]
    public function updateConfigurator(Configurator $configurator): void {}

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        ++$this->shutdowns;

        return true;
    }
}
