<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Api\TraceContext;
use OpenTelemetry\API\Logs\LogRecordBuilderInterface;

/**
 * Sets an OTLP log record's trace context from a snapshot; only the adapter can build one.
 *
 * @internal
 */
interface LogCorrelation
{
    /**
     * @param TraceContext|null $trace null: written outside any trace
     */
    public function correlate(LogRecordBuilderInterface $record, ?TraceContext $trace): void;
}
