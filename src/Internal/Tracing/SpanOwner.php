<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Api\Span;

/**
 * One span and its activation, as the operation that asked for it sees them.
 *
 * The operation layer needs five things from a span it owns: somewhere to write, the trace a
 * measurement taken for it belongs to, a name for diagnostics, a way to stop being ambient,
 * and a way to end. None of those is an OpenTelemetry concept, and this interface is what
 * keeps the OpenTelemetry ones — `SpanInterface`, `ScopeInterface`, `SpanContextInterface` —
 * on the other side of the perimeter, in
 * {@see \Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OwnedSpan}.
 *
 * Owning is the whole point: instrumentation may *find* an active span, but only what Weaver
 * created is ended and detached here. A view handed out by `view()` is borrowed and revoked
 * when the owner finishes, so a caller that keeps one cannot write into the next request.
 *
 * The error type is on the owner rather than the view because it outlives the view's
 * usefulness: a suppressed operation records no span at all and still has to label its
 * duration metric with whatever `error.type` the description carried.
 *
 * @internal
 */
interface SpanOwner
{
    /** @return non-empty-string the operation's name, for diagnostics */
    public function name(): string;

    /**
     * The borrowed view. Enrichment only — writing through it never transfers ownership,
     * and it stops working once the owner has finished.
     */
    public function view(): Span;

    /**
     * The `error.type` the operation was described with, if any.
     *
     * @return non-empty-string|null
     */
    public function errorType(): ?string;

    /**
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function rememberErrorType(array $attributes): void;

    /**
     * The trace a measurement taken for this span belongs to.
     *
     * Survives `detach()` on purpose: the activation is what makes the span ambient, and an
     * operation that has stopped being ambient has not stopped being the one being measured.
     */
    public function correlation(): ?TraceCorrelation;

    /** Releases the activation, leaving the span open. */
    public function detach(): void;

    /** Detaches and ends. Idempotent. */
    public function finish(): void;
}
