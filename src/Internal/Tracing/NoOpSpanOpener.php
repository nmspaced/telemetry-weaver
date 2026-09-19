<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The span opener of a signal whose tracing is switched off.
 *
 * Instrumentation asks for a span and gets one it can safely use: an {@see InertSpan}, which
 * writes nowhere and has nothing to end. Nothing downstream needs to know the signal is off,
 * and nothing in this path touches an OpenTelemetry object.
 *
 * This is why no instrumentation class carries an `enabled` flag — whether telemetry is
 * produced is decided once, in the container, by which object gets injected. A branch at
 * every call site would say the same thing in more places and on the hot path.
 *
 * It still carries a correlation source, when one is available. No span of its own does
 * not mean no trace: a suppressed Doctrine query runs inside the request that issued it,
 * and its duration should still be able to point an exemplar at that request. Without a
 * source — a signal switched off wholesale in the container — there is nothing to point at
 * and the measurements simply carry none.
 */
final readonly class NoOpSpanOpener implements SpanOpenerInterface
{
    private function __construct(
        private ?TraceCorrelationSource $correlations,
    ) {}

    /**
     * The signal is off wholesale, so there is no trace to point anything at.
     */
    public static function disabled(): self
    {
        return new self(null);
    }

    /**
     * This operation opens no span of its own, but it is still running inside a trace —
     * a Doctrine query under `only_with_parent`, an excluded HttpClient host. Its duration
     * belongs to that trace, and the source is how it keeps saying so.
     */
    public static function suppressing(TraceCorrelationSource $correlations): self
    {
        return new self($correlations);
    }

    /**
     * @param non-empty-string $name
     */
    #[\Override]
    public function open(string $name, SpanOptions $options): SpanOwner
    {
        return new InertSpan($name, $this->correlations?->current());
    }

    #[\Override]
    public function suppressed(): SpanOpenerInterface
    {
        return $this;
    }
}
