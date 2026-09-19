<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * A trace that arrived from somewhere else, as a token nothing outside the adapter can read.
 *
 * Opaque on purpose. Instrumentation's job at a process boundary is to find the carrier —
 * a header bag, a message stamp — and hand it over; deciding what a trace context *is*, and
 * what to do with an unusable one, belongs to the propagation adapter. A type instrumentation
 * could inspect would put that decision back at every call site.
 *
 * An invalid one is not an error and not "no parent": it means the boundary was real and
 * carried nothing, so the operation starts a new trace. That is deliberately different from
 * passing no incoming trace at all, which continues whatever is already running. A worker
 * that just finished a message must not adopt its context for the next one.
 *
 * @internal
 */
interface IncomingTrace
{
    public function isValid(): bool;
}
