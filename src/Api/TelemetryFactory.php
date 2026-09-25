<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * Creates named instrumentation scopes that share the bundle's providers and switches.
 *
 * @api
 */
interface TelemetryFactory
{
    public function scope(string $name, ?string $version = null, ?string $schemaUrl = null): Telemetry;
}
