<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;

/**
 * @internal No clock, correlation or per-measurement allocation for a disabled instrument.
 */
final readonly class NoopDuration implements Measurement, StartableDuration
{
    #[\Override]
    public function start(?TraceCorrelation $correlation): Measurement
    {
        return $this;
    }

    #[\Override]
    public function stop(array $attributes = []): void {}

    #[\Override]
    public function cancel(): void {}
}
