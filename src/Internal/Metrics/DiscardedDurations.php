<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * The recorder of a disabled bundle; records nothing.
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
