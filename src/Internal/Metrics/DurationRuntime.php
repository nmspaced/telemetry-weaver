<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * The clock, recorder and failure reporter every duration instrument shares.
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
