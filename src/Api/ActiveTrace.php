<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * The trace running right now, for code without an operation, such as a log processor.
 *
 * It returns identifiers only, so it cannot end or keep alive a span it does not own.
 *
 * @api
 */
interface ActiveTrace
{
    /**
     * The innermost valid span, including remote or unsampled ones; null when there is none.
     */
    public function current(): ?TraceContext;
}
