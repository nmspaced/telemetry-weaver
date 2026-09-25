<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The package's one ambient read: the running trace, as an opaque token, for request
 * metrics that have no operation of their own.
 *
 * @internal
 */
interface TraceCorrelationSource
{
    /** Null when nothing is being traced. */
    public function current(): ?TraceCorrelation;
}
