<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelationSource;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextStorageInterface;

/**
 * Reads the current execution's context out of the storage it was given.
 *
 * The storage is injected rather than reached through `Context::getCurrent()` so that the
 * execution model stays configurable — a fiber-bound storage is a different object, not a
 * different static call — and so that a test can install its own without touching process
 * state it does not own.
 *
 * A context with no valid span correlates with nothing, and answering `null` for it keeps
 * the invalid case out of every caller: a measurement taken outside a trace simply has no
 * exemplar rather than one pointing at an all-zero span id.
 *
 * @internal
 */
final readonly class OtelTraceCorrelationSource implements TraceCorrelationSource
{
    public function __construct(
        private ContextStorageInterface $contextStorage,
    ) {}

    #[\Override]
    public function current(): ?TraceCorrelation
    {
        $context = $this->contextStorage->current();

        return Span::fromContext($context)->getContext()->isValid() ? new OtelTraceCorrelation($context) : null;
    }
}
