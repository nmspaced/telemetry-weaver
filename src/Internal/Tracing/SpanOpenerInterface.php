<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * @internal
 */
interface SpanOpenerInterface
{
    /**
     * @param non-empty-string $name
     */
    public function open(string $name, SpanOptions $options): SpanOwner;

    /** The same opener without spans; context, baggage and correlation still apply. */
    public function suppressed(): self;

    /**
     * The same opener for an execution such as a request: each operation always activates its
     * context and, when it ends, releases whatever is still activated above it.
     */
    public function confining(): self;
}
