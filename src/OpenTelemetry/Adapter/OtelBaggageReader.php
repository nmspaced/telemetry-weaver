<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Tracing\BaggageReader;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Entry;

/**
 * Reads W3C baggage from the context an operation was started in; non-string values are
 * dropped.
 *
 * @internal
 */
final readonly class OtelBaggageReader implements BaggageReader
{
    // @mago-expect analysis:mixed-assignment — Entry::getValue() is mixed
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
