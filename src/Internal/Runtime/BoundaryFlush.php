<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

/**
 * Delivers telemetry when a unit of work or the process ends. Callers stay on the Symfony
 * side; the SDK work lives in {@see \Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TelemetryFlusher}.
 *
 * @internal
 */
interface BoundaryFlush
{
    /** A unit of work ended: deliver each signal on its schedule within the shared budget. */
    public function atBoundary(): void;

    /** The process is ending: collect once more, then seal the pipeline. */
    public function atShutdown(): void;
}
