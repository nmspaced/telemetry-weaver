<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Telemetry;

/**
 * The public telemetry facade plus `boundary()`, for the bundle's own instrumentation.
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
