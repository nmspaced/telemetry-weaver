<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The baggage reader of a disabled bundle: always empty.
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
