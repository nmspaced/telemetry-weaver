<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Tracing\BaggageReader;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Entry;

/**
 * Reads W3C baggage out of the context an operation was started in.
 *
 * Entries whose value is not a string are dropped rather than rendered. Baggage crosses
 * process boundaries as a header, so a value that is not already a string never survived
 * the trip in the first place; producing one here would invent data that no other service
 * in the trace can see.
 *
 * @internal
 */
final readonly class OtelBaggageReader implements BaggageReader
{
    // @mago-expect analysis:mixed-assignment — Entry::getValue() is `mixed` by contract; the narrowing below is the point
    #[\Override]
    public function of(?TraceCorrelation $correlation): array
    {
        if (!$correlation instanceof OtelTraceCorrelation) {
            return [];
        }

        $entries = [];

        /** @var mixed $entry */
        foreach (Baggage::fromContext($correlation->context)->getAll() as $key => $entry) {
            if (!\is_string($key) || $key === '' || !$entry instanceof Entry) {
                continue;
            }

            $value = $entry->getValue();

            if (\is_string($value)) {
                $entries[$key] = $value;
            }
        }

        return $entries;
    }
}
