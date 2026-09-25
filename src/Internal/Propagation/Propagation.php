<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Propagation;

use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;

/**
 * Trace context across a process boundary, as header maps. Outgoing context is the current
 * operation's; incoming context is always resolved against the root, never the ambient one.
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
     * The header names this propagation owns, for callers that must replace them.
     *
     * @return list<non-empty-string>
     */
    public function fields(): array;
}
