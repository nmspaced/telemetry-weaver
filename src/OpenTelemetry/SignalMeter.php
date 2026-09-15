<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;

/**
 * Picks the meter one signal gets: the real one, or the SDK's no-op when metrics are off.
 *
 * Both switches are read here and both have to be on — the signal's own key and the
 * global `metrics.enabled`, which an instrumentation wired for its tracing half would
 * otherwise ignore.
 *
 * Instruments created on a no-op meter record nothing and are never registered with a
 * provider, so a disabled signal costs neither measurements nor an empty timeseries —
 * without a single `if` in the instrumentation that uses them.
 */
final readonly class SignalMeter
{
    public static function create(MeterInterface $delegate, bool $metricsEnabled, bool $signalEnabled): MeterInterface
    {
        return $metricsEnabled && $signalEnabled ? $delegate : new NoopMeter();
    }
}
