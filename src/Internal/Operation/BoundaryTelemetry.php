<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Telemetry;

/**
 * The public telemetry facade plus `boundary()` and `execution()`, for the bundle's own instrumentation.
 *
 * @internal
 */
interface BoundaryTelemetry extends Telemetry
{
    /**
     * @param non-empty-string $name
     */
    public function boundary(string $name): BoundaryOperation;

    /**
     * A boundary for one unit of work, such as a request. Whatever is still activated inside it
     * when it detaches is released, and the operations among them are abandoned when it ends.
     *
     * @param non-empty-string $name
     */
    public function execution(string $name): BoundaryOperation;
}
