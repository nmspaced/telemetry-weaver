<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;

/**
 * The meter provider public telemetry scopes are created from: the container's, or the API
 * no-op when `metrics.enabled` is off. Instruments on a no-op meter record nothing and are
 * never registered, so a switched-off signal exports no empty timeseries.
 */
final readonly class SignalMeterProvider
{
    public static function create(MeterProviderInterface $delegate, bool $metricsEnabled): MeterProviderInterface
    {
        return $metricsEnabled ? $delegate : new NoopMeterProvider();
    }
}
