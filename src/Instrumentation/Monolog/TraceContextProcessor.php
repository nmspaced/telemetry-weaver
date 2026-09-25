<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Nmspaced\TelemetryWeaver\Api\ActiveTrace;

/**
 * Adds the running trace's IDs to every log record.
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
