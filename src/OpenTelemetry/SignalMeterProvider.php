<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;

/**
 * The container's meter provider, or a no-op one when `metrics.enabled` is off.
 */
final readonly class SignalMeterProvider
{
    public static function create(MeterProviderInterface $delegate, bool $metricsEnabled): MeterProviderInterface
    {
        if (!$metricsEnabled) {
            return new NoopMeterProvider();
        }

        return $delegate;
    }
}
