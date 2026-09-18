<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Nmspaced\TelemetryWeaver\Internal\Tracing\ActiveTraceIdentity;

/**
 * Stamps the running trace onto every log record, so a line can be found from a trace and
 * the other way round.
 *
 * It asks for three strings rather than for the context that holds them. A processor has no
 * operation and no lifecycle — it is handed a record and must answer immediately — so it is
 * the one place that genuinely needs to read whatever is running. Narrowing that read to the
 * ids keeps it from being a doorway to the rest of the context model.
 */
final readonly class TraceContextProcessor implements ProcessorInterface
{
    public function __construct(
        private ActiveTraceIdentity $trace,
    ) {}

    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $current = $this->trace->current();

        if ($current === null) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            'trace_id' => $current['trace_id'],
            'span_id' => $current['span_id'],
            'trace_flags' => \sprintf('%02x', $current['trace_flags']),
        ]);
    }
}
