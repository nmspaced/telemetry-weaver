<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/**
 * The tracer provider public telemetry scopes are created from: the container's, or the API
 * no-op when `traces.enabled` is off.
 *
 * The switch is read here, so `ScopedTelemetryFactory` carries no flag. It turns the no-op into
 * a no-op span opener, so a switched-off signal activates no context scope on the hot path.
 */
final readonly class SignalTracerProvider
{
    public static function create(TracerProviderInterface $delegate, bool $tracesEnabled): TracerProviderInterface
    {
        return $tracesEnabled ? $delegate : new NoopTracerProvider();
    }
}
