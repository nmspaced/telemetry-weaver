<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Api\TelemetryFactory;

/**
 * @internal Keeps application autowiring valid even when the entire bundle is switched off.
 */
final readonly class DisabledTelemetryFactory implements TelemetryFactory
{
    #[\Override]
    public function scope(string $name, ?string $version = null, ?string $schemaUrl = null): Telemetry
    {
        if ($name === '') {
            throw new \InvalidArgumentException('An instrumentation scope name must not be empty.');
        }

        return DefaultTelemetry::disabled();
    }
}
