<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelationSource;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextStorageInterface;

/**
 * Reads the current context from the injected storage. Returns null when it has no valid
 * span, so no exemplar points at an all-zero id.
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

        if (!Span::fromContext($context)->getContext()->isValid()) {
            return null;
        }

        return new OtelTraceCorrelation($context);
    }
}
