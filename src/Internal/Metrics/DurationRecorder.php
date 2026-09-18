<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * Writes one measurement to one histogram, with the trace it belongs to.
 *
 * The seam exists so that the timer never holds an OpenTelemetry context. Passing the
 * correlation straight to `HistogramInterface::record()` would work and would be shorter,
 * but it would put the one type whose static accessors read ambient state back into the
 * metric layer, where the next author would find it already imported.
 *
 * @internal
 */
interface DurationRecorder
{
    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function record(
        HistogramInterface $histogram,
        float $value,
        array $attributes,
        ?TraceCorrelation $correlation,
    ): void;
}
