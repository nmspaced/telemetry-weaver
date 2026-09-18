<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The trace an operation runs in, as a token nothing outside the adapter can read.
 *
 * It exists for one reason: a measurement may be recorded after the operation's span has
 * stopped being the current one. An HTTP server span releases its activation at the end
 * of the response and is finished at terminate; a split-lifecycle operation may do the
 * same for reasons of its own. A histogram recorded at that moment must still be
 * correlated with the operation's own span, which is what a trace-based exemplar filter
 * reads out of the context supplied to `record()`.
 *
 * Deliberately empty. The alternative — handing out the context and letting each caller
 * decide what to do with it — is the ambient-state dependency this package is removing;
 * a token that can only be carried cannot grow one.
 *
 * @internal
 */
interface TraceCorrelation {}
