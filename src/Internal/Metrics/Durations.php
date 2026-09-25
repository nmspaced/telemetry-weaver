<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;

/**
 * Starts a measurement from an application's `Duration` handle; a foreign handle records
 * nothing instead of failing.
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
