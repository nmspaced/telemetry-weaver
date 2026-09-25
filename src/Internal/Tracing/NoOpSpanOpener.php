<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The span opener of a bundle that is switched off entirely.
 *
 * Instrumentation asks for a span and gets one it can safely use: an {@see InertSpan}, which
 * writes nowhere and has nothing to end. Nothing downstream needs to know the bundle is off,
 * and nothing in this path touches an OpenTelemetry object.
 *
 * This is why no instrumentation class carries an `enabled` flag — whether telemetry is
 * produced is decided once, in the container, by which object gets injected. A branch at
 * every call site would say the same thing in more places and on the hot path.
 *
 * Switching *tracing* off is a different case. It removes spans, but it does not remove the
 * context an operation runs in: baggage and the incoming trace still have to reach nested
 * work and downstream services. That is handled by
 * {@see \Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\ContextOnlyOpener}, which is what
 * {@see SpanOpenerInterface::suppressed()} returns while an SDK is present. This class is for
 * when no SDK is present at all.
 */
final readonly class NoOpSpanOpener implements SpanOpenerInterface
{
    private function __construct() {}

    public static function disabled(): self
    {
        return new self();
    }

    /**
     * @param non-empty-string $name
     */
    #[\Override]
    public function open(string $name, SpanOptions $options): SpanOwner
    {
        return new InertSpan($name);
    }

    #[\Override]
    public function suppressed(): SpanOpenerInterface
    {
        return $this;
    }
}
