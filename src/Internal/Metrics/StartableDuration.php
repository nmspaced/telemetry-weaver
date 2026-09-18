<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;

/**
 * The half of a duration instrument that the package keeps to itself.
 *
 * `Api\Duration` is the handle an application is given; this is what can be done with one,
 * and the correlation argument is why the two are separate. Which trace a measurement
 * belongs to is decided when it starts, by whoever started it — not read from the
 * execution at the moment it is recorded, which by then may be a different request.
 *
 * @internal
 */
interface StartableDuration extends Duration
{
    public function start(?TraceCorrelation $correlation): Measurement;
}
