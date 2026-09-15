<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Execution;

/**
 * One unit of state that lives for exactly one execution.
 *
 * The two endings are not the same thing, and the distinction is the reason
 * this interface has two methods instead of a single close():
 *
 *  - complete() is the execution reaching its end. The outcome was observed
 *    and belongs in the telemetry.
 *  - abandon() is the worker resetting over an entry that never got there.
 *    Whatever the entry owns still has to be released, but an outcome nobody
 *    observed must not be reported as if it had been.
 *
 * A span ends either way — an unended span is a leak and keeps its context
 * scope activated. A duration does not: recording one for an abandoned
 * request would put a number into the histogram that never happened.
 *
 * @internal
 */
interface ExecutionEntry
{
    public function complete(): void;

    public function abandon(): void;
}
