<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The baggage visible inside one operation, read from the trace it is running in.
 *
 * Keyed off the correlation token rather than the current execution, for the same reason
 * the duration recorder is: an operation that has released its activation is still the
 * operation being asked about, and answering from whatever is ambient at the moment of
 * the call would describe a different unit of work.
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
