<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;

/**
 * Starts a measurement from the opaque handle an application was given.
 *
 * The narrowing lives here rather than at each call site because there is exactly one way
 * to obtain a `Duration` — `Metrics::duration()` — and everything it returns is startable.
 * A handle that is not can only be one somebody implemented themselves against a marker
 * interface, and the answer to that is a measurement that records nothing, not a crash in
 * the middle of their request.
 *
 * @internal
 */
final readonly class Durations
{
    private function __construct() {}

    /**
     * @param non-empty-string $name the operation being measured, for failure reports
     */
    public static function start(
        Duration $duration,
        ?TraceCorrelation $correlation,
        InstrumentationFailureReporter $reporter,
        string $name,
    ): Measurement {
        if (!$duration instanceof StartableDuration) {
            return new NoopDuration();
        }

        try {
            return $duration->start($correlation);
        } catch (\Throwable $throwable) {
            $reporter->report('Operation measurement start failed', $name, $throwable);

            return new NoopDuration();
        }
    }
}
