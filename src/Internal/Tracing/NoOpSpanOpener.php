<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The span opener of a signal whose tracing is switched off.
 *
 * Instrumentation asks for a span and gets one it can safely use: `OwnedSpan::inert()`
 * wraps an invalid span, so `enrich()` does nothing and `finish()` has nothing to end.
 * Nothing downstream needs to know the signal is off.
 *
 * This is why no instrumentation class carries an `enabled` flag — whether telemetry is
 * produced is decided once, in the container, by which object gets injected. A branch at
 * every call site would say the same thing in more places and on the hot path.
 */
final readonly class NoOpSpanOpener implements SpanOpenerInterface
{
    /**
     * @param non-empty-string $name
     */
    #[\Override]
    public function open(string $name, SpanOptions $options): OwnedSpan
    {
        return OwnedSpan::inert($name);
    }
}
