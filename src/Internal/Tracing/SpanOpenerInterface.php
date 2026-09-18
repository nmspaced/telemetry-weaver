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

    /**
     * The same opener with the span suppressed, for an operation that keeps its metrics.
     *
     * A method rather than `NoOpSpanOpener::disabled()` at the call site, because the two
     * openers are not interchangeable: a suppressed operation still runs inside whatever
     * trace its caller opened, and its duration still belongs to that trace. Only an
     * opener knows how to answer that, so only an opener can make its own silent twin.
     */
    public function suppressed(): self;
}
