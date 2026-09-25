<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Nmspaced\TelemetryWeaver\Api\ActiveTrace;

/**
 * Stamps the running trace onto every log record, so a line can be found from a trace and
 * the other way round.
 *
 * It asks for the trace's identity rather than for the context that holds it. A processor
 * has no operation and no lifecycle — it is handed a record and must answer immediately —
 * which is the case {@see ActiveTrace} exists for, and taking the values keeps this from
 * being a doorway to the rest of the context model.
 */
final readonly class TraceContextProcessor implements ProcessorInterface
{
    public function __construct(
        private ActiveTrace $trace,
    ) {}

    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $current = $this->trace->current();

        if ($current === null) {
            return $record;
        }

        return TraceContextSnapshot::write($record, $current);
    }
}
