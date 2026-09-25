<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The baggage of one operation, read from its correlation token rather than the ambient
 * context.
 *
 * @internal
 */
interface BaggageReader
{
    /**
     * @return array<non-empty-string, string> empty when nothing was propagated
     */
    public function of(?TraceCorrelation $correlation): array;
}
