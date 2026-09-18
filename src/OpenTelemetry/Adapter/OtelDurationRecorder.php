<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRecorder;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * @internal
 */
final readonly class OtelDurationRecorder implements DurationRecorder
{
    /**
     * `false` rather than `null` for a measurement with no correlation.
     *
     * The two are not the same argument to `record()`: `null` means "use whatever context
     * is current at this instant", which is the behaviour this whole seam exists to stop —
     * a duration finished after its span was released would silently pick up the next
     * request's span. `false` means the measurement names no trace, which is the truth
     * whenever nothing was being traced when it started.
     */
    #[\Override]
    public function record(
        HistogramInterface $histogram,
        float $value,
        array $attributes,
        ?TraceCorrelation $correlation,
    ): void {
        $histogram->record(
            $value,
            $attributes,
            $correlation instanceof OtelTraceCorrelation ? $correlation->context : false,
        );
    }
}
