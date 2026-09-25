<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;

/**
 * Returns a signal's real meter, or a no-op one when either its own switch or
 * `metrics.enabled` is off.
 */
final readonly class SignalMeter
{
    public static function create(MeterInterface $delegate, bool $metricsEnabled, bool $signalEnabled): MeterInterface
    {
        return $metricsEnabled && $signalEnabled ? $delegate : new NoopMeter();
    }
}
