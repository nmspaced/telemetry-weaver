<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Tracing\ActiveTraceIdentity;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextStorageInterface;

/**
 * @internal
 */
final readonly class OtelActiveTraceIdentity implements ActiveTraceIdentity
{
    public function __construct(
        private ContextStorageInterface $contextStorage,
    ) {}

    #[\Override]
    public function current(): ?array
    {
        $span = Span::fromContext($this->contextStorage->current())->getContext();

        if (!$span->isValid()) {
            return null;
        }

        $traceId = $span->getTraceId();
        $spanId = $span->getSpanId();

        if ($traceId === '' || $spanId === '') {
            return null;
        }

        return ['trace_id' => $traceId, 'span_id' => $spanId, 'trace_flags' => $span->getTraceFlags()];
    }
}
