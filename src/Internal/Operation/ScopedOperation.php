<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;

/**
 * A running operation whose context activation can be released before it finishes.
 *
 * Internal because the two things that need it are framework lifecycles, not application
 * code: an HTTP server span stops being ambient at the end of the request but is only
 * finished at terminate, and a lazy HttpClient response does the same in reverse. Both are
 * cases where the span outlives the stack frame that opened it — which is exactly the shape
 * an application should not be encouraged to reach for.
 *
 * @internal
 */
interface ScopedOperation extends RunningOperation
{
    public function detach(): void;

    /**
     * Hands control back to the caller in the middle of the operation: the context stops
     * being ambient and the duration clock stops. The span stays open.
     *
     * For a lazy result handed back piece by piece. Between two pieces the caller runs its
     * own code, which is neither a child of the operation nor time the operation took. The
     * span still covers the whole read, which is what a trace should show.
     */
    public function suspend(): void;

    /**
     * Takes control back after `suspend()`: the operation's context is ambient again, so
     * backend work done now is its child, and the clock runs.
     */
    public function resume(): void;

    /**
     * The trace a measurement taken for this operation belongs to, or null when nothing
     * was being traced.
     *
     * For the second and third measurement of one unit of work. An operation correlates
     * its own duration by capturing this when it starts, which is what keeps the exemplar
     * pointing at the span actually being measured; an instrumentation that records a
     * *further* value for the same work — an HTTP body size, read when the caller finally
     * consumes a lazy response — has no way to say the same thing without asking. Letting
     * it record without a context instead resolves the exemplar against whatever is
     * ambient at that instant, which in a worker is some later request's span.
     *
     * Valid until the operation finishes: the correlation holds the span, and an owner
     * that kept one past its span would retain the SDK graph for as long as it lived.
     */
    public function correlation(): ?TraceCorrelation;
}
