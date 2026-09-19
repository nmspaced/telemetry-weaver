<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The ids of the trace currently running, for the one consumer that needs them as values.
 *
 * Log correlation is not telemetry the package produces; it is a field another system reads.
 * A Monolog processor cannot be handed a span — it has no operation and no lifecycle — so
 * what it gets is the smallest possible read: three strings, or nothing.
 *
 * @internal
 */
interface ActiveTraceIdentity
{
    /**
     * @return array{trace_id: non-empty-string, span_id: non-empty-string, trace_flags: int}|null
     *                                                                                             null whenever nothing valid is being traced
     */
    public function current(): ?array;
}
