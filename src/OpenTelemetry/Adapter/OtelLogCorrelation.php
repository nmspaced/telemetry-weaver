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
 * Gives an OTLP log record an explicit context built from its trace IDs, never the current
 * one; behind a buffered handler the current context belongs to another operation. The root
 * context marks a record written outside any trace.
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
