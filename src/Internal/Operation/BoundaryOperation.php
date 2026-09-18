<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\Operation;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;

/**
 * What framework instrumentation may say about an operation that application code may not.
 *
 * Everything here answers a question that only arises at a process boundary or inside the
 * bundle's own policy: which trace this work continues, what else it is related to, and
 * whether a span is worth opening at all. An application has one unit of work and one
 * caller; it does not need to describe any of that, and offering it the vocabulary was what
 * made the public API require an understanding of OpenTelemetry's context model.
 *
 * It is the same runtime underneath — the same plan, the same starter, the same lifecycle.
 * The package does not grow a second telemetry system for its own instrumentation; it grows
 * a wider constructor for it.
 *
 * @internal
 */
interface BoundaryOperation extends Operation
{
    /**
     * The inherited fluent steps are redeclared so that a chain keeps the wider type.
     * Without them `boundary($name)->kind(...)` narrows back to {@see Operation} and the
     * methods below stop being reachable halfway through a builder call.
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
     * Continue a trace that arrived from another process.
     *
     * Null keeps the ambient context, which is what a local operation wants. An incoming
     * trace replaces it even when invalid: a boundary that carried nothing starts a new
     * trace rather than adopting whatever the worker was doing a moment ago.
     */
    public function from(?IncomingTrace $trace): self;

    /**
     * Relate this span to one it does not descend from. An invalid trace is ignored.
     */
    public function linkedTo(IncomingTrace $trace): self;

    /**
     * Relate this span to whatever is running when it opens.
     *
     * For a consumer that takes its parent from the message's creation context while running
     * inside a span of its own — a worker loop somebody traces. The messaging conventions
     * ask for the ambient context to be kept as a link in exactly that case.
     */
    public function linkedToActiveSpan(): self;

    /**
     * Keep the metrics, drop the span, and leave the ambient parent in place.
     *
     * A configuration decision — an excluded HttpClient host, a signal switched off for one
     * component — made before anything starts.
     */
    public function withoutSpan(): self;

    /**
     * Open a span only if something is already being traced.
     *
     * The `only_with_parent` filter, as a declaration rather than a question: the opener
     * owns the context, so the opener answers it. Metrics are unaffected, which is the
     * point — a background poll is real database load whether or not it belongs to a trace.
     */
    public function onlyInsideTrace(): self;

    /**
     * Covariant: instrumentation gets an operation whose activation it can release.
     */
    #[\Override]
    public function start(): ScopedOperation;
}
