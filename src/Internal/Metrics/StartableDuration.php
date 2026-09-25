<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;

/**
 * The internal side of `Api\Duration`: starts a measurement for a trace chosen at start.
 *
 * @internal
 */
interface StartableDuration extends Duration
{
    public function start(?TraceCorrelation $correlation): Measurement;
}
