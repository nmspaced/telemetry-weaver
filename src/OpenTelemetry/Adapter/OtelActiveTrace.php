<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\Api\TraceContext;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextStorageInterface;

/**
 * @internal
 *
 * Reads the active trace from the injected storage, the one spans are activated in.
 */
final readonly class OtelActiveTrace implements ActiveTrace
{
    public function __construct(
        private ContextStorageInterface $contextStorage,
    ) {}

    /**
     * Returns null for an invalid context rather than the all-zero ids.
     */
    #[\Override]
    public function current(): ?TraceContext
    {
        try {
            $span = Span::fromContext($this->contextStorage->current())->getContext();

            if (!$span->isValid()) {
                return null;
            }

            return new TraceContext($span->getTraceId(), $span->getSpanId(), $span->getTraceFlags());
        } catch (\Throwable) {
            return null;
        }
    }
}
