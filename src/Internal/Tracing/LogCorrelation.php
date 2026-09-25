<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Api\TraceContext;
use OpenTelemetry\API\Logs\LogRecordBuilderInterface;

/**
 * Puts an OTLP log record into the trace it was written in, or into none.
 *
 * A port, because the record's context is an OpenTelemetry context, and only the adapter may
 * build one. The log handler knows which trace a record belongs to, because the record
 * carries a snapshot of it. It does not know how that becomes a context.
 *
 * @internal
 */
interface LogCorrelation
{
    /**
     * @param TraceContext|null $trace null states that the record was written outside any
     *                                 trace. It is not left to the SDK to guess, because
     *                                 that guess is whatever trace happens to be running
     *                                 when the record is exported
     */
    public function correlate(LogRecordBuilderInterface $record, ?TraceContext $trace): void;
}
