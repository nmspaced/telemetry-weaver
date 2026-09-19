<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Telemetry;

/**
 * The telemetry facade as the bundle's own instrumentation sees it.
 *
 * Identical to the public one plus a wider way to open an operation. Instrumentation asks
 * for this type instead of {@see Telemetry} where it has a boundary to describe; everywhere
 * else it uses the same public API an application does, on purpose — the two go through one
 * runtime, and a bug in the lifecycle is a bug in both.
 *
 * @internal
 */
interface BoundaryTelemetry extends Telemetry
{
    /**
     * @param non-empty-string $name
     */
    public function boundary(string $name): BoundaryOperation;
}
