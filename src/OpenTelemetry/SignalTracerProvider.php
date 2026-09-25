<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/**
 * The container's tracer provider, or a no-op one when `traces.enabled` is off.
 */
final readonly class SignalTracerProvider
{
    public static function create(TracerProviderInterface $delegate, bool $tracesEnabled): TracerProviderInterface
    {
        if (!$tracesEnabled) {
            return new NoopTracerProvider();
        }

        return $delegate;
    }
}
