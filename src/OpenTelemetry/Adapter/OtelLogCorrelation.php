<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Api\TraceContext;
use Nmspaced\TelemetryWeaver\Internal\Tracing\LogCorrelation;
use OpenTelemetry\API\Logs\LogRecordBuilderInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\Context\Context;

/**
 * Gives an OTLP log record an explicit context built from a snapshot, never the current one.
 *
 * An explicit context is the point. An unset one is resolved by the SDK when the record is
 * emitted, which behind a buffering Monolog handler is after the operation that wrote it has
 * ended. The SDK then reads either no trace or an unrelated one. The root context is also an
 * explicit answer: this record belongs to no trace.
 *
 * Neither the span nor its activation is kept alive: the context wraps a non-recording span
 * built from the ids alone.
 *
 * @internal
 */
final readonly class OtelLogCorrelation implements LogCorrelation
{
    #[\Override]
    public function correlate(LogRecordBuilderInterface $record, ?TraceContext $trace): void
    {
        $record->setContext(
            $trace === null
                ? Context::getRoot()
                : Span::wrap(SpanContext::create($trace->traceId, $trace->spanId, $trace->traceFlags))
                    ->storeInContext(Context::getRoot()),
        );
    }
}
