<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * The trace running right now, for code that has no operation to ask.
 *
 * The rest of this API is handle-based on purpose: a caller receives a {@see Span} inside
 * the closure that owns it, and ownership is what keeps a worker's units of work apart.
 * Some code cannot be reached that way at all. A Monolog processor is handed a record and
 * must answer immediately; a Doctrine driver middleware is called by DBAL from inside the
 * statement it is instrumenting. Neither has an operation in scope, and neither can be
 * given one.
 *
 * So this is the one ambient read the package offers, and it is narrowed to the shape that
 * cannot grow into the rest of the context model: {@see TraceContext} is three values, not
 * a handle. Nothing here can end a span someone else owns, keep a context activation alive
 * past its boundary, or reach the operation the ids belong to — the two worker bugs this
 * package exists to prevent are both unreachable through it.
 *
 * Reading it is still a decision. What is current is whatever the innermost enclosing span
 * happens to be, which is a different span depending on where the call sits: inside a
 * Doctrine middleware it is the statement span only if that middleware runs inside the
 * bundle's own, and inside a Messenger handler it is the process span rather than the
 * dispatch one. A caller that needs a specific span must be inside it; this cannot tell it
 * which one it found.
 *
 * @api
 */
interface ActiveTrace
{
    /**
     * A snapshot of the current valid span, including unsampled or remote contexts.
     * Returns null when no valid span is active, reading fails, or the bundle is disabled.
     * Disabling or suppressing a new span does not hide an already active parent,
     * including one created by another instrumentation library.
     */
    public function current(): ?TraceContext;
}
