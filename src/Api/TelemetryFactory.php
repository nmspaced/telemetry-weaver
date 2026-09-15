<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * Create named instrumentation scopes once in DI, sharing the bundle's providers and global signal switches.
 *
 * @api
 */
interface TelemetryFactory
{
    public function scope(string $name, ?string $version = null, ?string $schemaUrl = null): Telemetry;
}
