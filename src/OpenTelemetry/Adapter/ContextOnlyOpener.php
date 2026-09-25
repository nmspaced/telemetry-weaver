<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextStorageInterface;

/**
 * The opener of an operation that records no span but still runs in its own context.
 *
 * It is used when tracing is switched off, for the whole application or for one signal,
 * and for work that `only_with_parent` suppresses. Switching off tracing removes spans. It
 * does not remove context. Baggage is independent of tracing under W3C, and an incoming
 * `traceparent` still has to reach the services downstream. Nested operations whose own
 * spans are on still need the right parent. So an operation that records no span still:
 *
 *  - continues the trace its boundary received, or starts a clean root rather than
 *    inheriting a stale one;
 *  - carries the baggage it was given, for its callback and for everything it propagates;
 *  - restores the previous context when it finishes, like any other operation.
 *
 * The context is activated only when it differs from the ambient one. An operation with no
 * boundary and no baggage costs no scope, which is what the plain no-op used to guarantee.
 *
 * The owner it returns wraps the invalid span, not the parent's span context. A caller
 * that asks this operation for its trace or span id gets nothing, because this operation
 * has no span. The upstream ids are still in the active context, where log correlation
 * and propagation read them.
 *
 * @internal
 */
final readonly class ContextOnlyOpener implements SpanOpenerInterface
{
    public function __construct(
        private ContextStorageInterface $contextStorage,
        private InstrumentationFailureReporter $reporter,
    ) {}

    /**
     * @param non-empty-string $name
     */
    #[\Override]
    public function open(string $name, SpanOptions $options): OwnedSpan
    {
        $ambient = $this->contextStorage->current();
        $context = OperationParent::resolve($options, $ambient);

        if ($context === $ambient) {
            return OwnedSpan::inert($name, new OtelTraceCorrelation($ambient));
        }

        try {
            $activation = $this->contextStorage->attach($context);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Context activation failed', $name, $throwable);

            return OwnedSpan::inert($name, new OtelTraceCorrelation($context));
        }

        return OwnedSpan::activated(
            $name,
            Span::getInvalid(),
            $activation,
            $this->reporter,
            new OtelTraceCorrelation($context),
        )->reenterableIn($this->contextStorage, $context);
    }

    #[\Override]
    public function suppressed(): self
    {
        return $this;
    }
}
