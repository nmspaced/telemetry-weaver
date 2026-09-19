<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The baggage reader of a facade that is switched off.
 *
 * There is no trace to read from, so there is nothing to answer but an empty map — and
 * saying that here rather than reaching for the OpenTelemetry adapter keeps a disabled
 * bundle from constructing any part of the SDK integration at all, which is the promise
 * `enabled: false` makes.
 *
 * @internal
 */
final readonly class NoBaggage implements BaggageReader
{
    #[\Override]
    public function of(?TraceCorrelation $correlation): array
    {
        return [];
    }
}
