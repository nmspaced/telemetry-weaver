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
     * Passes `false`, not `null`, when there is no correlation: `null` would make the SDK use
     * whatever context is current, possibly the next request's.
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
