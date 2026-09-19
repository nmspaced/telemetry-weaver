<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * What every duration instrument needs and none of them chooses: a clock to measure with,
 * a recorder to write through, and somewhere to report a failure that must not reach the
 * application.
 *
 * Grouped because the three always travel together and always come from the same place —
 * the metric factory that created the instrument. Threading them individually made the
 * instrument's own arguments, its name and histogram and unit, the minority of its
 * signature.
 *
 * @internal
 */
final readonly class DurationRuntime
{
    public function __construct(
        public DurationRecorder $recorder,
        public InstrumentationFailureReporter $reporter,
        public ClockInterface $clock = new SystemClock(),
    ) {}
}
