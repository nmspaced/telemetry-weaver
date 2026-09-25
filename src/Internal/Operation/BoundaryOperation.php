<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\Operation;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;

/**
 * The operation builder for framework instrumentation: adds process-boundary concerns
 * (incoming trace, links, span suppression) that the public API deliberately hides.
 *
 * @internal
 */
interface BoundaryOperation extends Operation
{
    /**
     * Redeclared so a fluent chain keeps this wider type.
     *
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    #[\Override]
    public function attributes(array $attributes): self;

    #[\Override]
    public function kind(SpanKind $kind): self;

    /**
     * @param array<non-empty-string, string> $entries
     */
    #[\Override]
    public function baggage(array $entries): self;

    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    #[\Override]
    public function duration(Duration $duration, array $attributes = []): self;

    /**
     * Continues a trace from another process. Null keeps the ambient context; an invalid
     * trace starts a new one instead of adopting the ambient context.
     */
    public function from(?IncomingTrace $trace): self;

    /** Links a span this one does not descend from; an invalid trace is ignored. */
    public function linkedTo(IncomingTrace $trace): self;

    /** Links the span that is active when this one opens, as messaging consumers require. */
    public function linkedToActiveSpan(): self;

    /** Records metrics but no span. */
    public function withoutSpan(): self;

    /** Opens a span only inside an existing trace; metrics are recorded either way. */
    public function onlyInsideTrace(): self;

    /** Returns an operation whose activation can be released early. */
    #[\Override]
    public function start(): ScopedOperation;
}
