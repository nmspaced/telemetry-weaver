<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextStorageInterface;

final readonly class TraceContextProcessor implements ProcessorInterface
{
    public function __construct(
        private ContextStorageInterface $contextStorage,
    ) {}

    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = Span::fromContext($this->contextStorage->current())->getContext();

        if (!$context->isValid()) {
            return $record;
        }

        return $record->with(extra: [
            ...$record->extra,
            'trace_id' => $context->getTraceId(),
            'span_id' => $context->getSpanId(),
            'trace_flags' => \sprintf('%02x', $context->getTraceFlags()),
        ]);
    }
}
