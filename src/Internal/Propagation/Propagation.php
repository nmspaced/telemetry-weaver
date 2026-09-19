<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Propagation;

use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;

/**
 * Trace context crossing a process boundary, in both directions.
 *
 * Headers rather than carrier objects: the wire format is a small `string => string` map,
 * every caller already has somewhere to put one, and a carrier abstraction would buy
 * nothing while pulling OpenTelemetry's getter and setter interfaces into instrumentation.
 *
 * Both directions are deliberately explicit about their base context, and that is the whole
 * reason this is a port rather than a propagator injected directly. `inject()` defaults to
 * the current context and `extract()` defaults to it as well — which is right in a
 * request-per-process runtime and wrong in a worker, where "current" may be a scope the
 * previous unit of work failed to close. Here the direction decides: outgoing context is
 * whatever this operation is running in, incoming context is always resolved against the
 * root.
 *
 * @internal
 */
interface Propagation
{
    /**
     * The headers an outgoing call should carry to continue this trace.
     *
     * @return array<non-empty-string, string>
     */
    public function injectCurrent(): array;

    /**
     * @param array<non-empty-string, string> $carrier empty when the boundary carried nothing
     */
    public function extract(array $carrier): IncomingTrace;

    /**
     * The header names this propagation owns.
     *
     * For a caller that has to replace them rather than add to them: two `traceparent`
     * headers are not a valid request, and which one a server picks is a coin toss.
     *
     * @return list<non-empty-string>
     */
    public function fields(): array;
}
