<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * The recorder of a facade that is switched off.
 *
 * Unreachable in practice — a no-op meter hands back a `NoopDuration`, which never starts
 * a timer — and present so that the disabled facade can be assembled without naming the
 * OpenTelemetry adapter.
 *
 * @internal
 */
final readonly class DiscardedDurations implements DurationRecorder
{
    #[\Override]
    public function record(
        HistogramInterface $histogram,
        float $value,
        array $attributes,
        ?TraceCorrelation $correlation,
    ): void {}
}
