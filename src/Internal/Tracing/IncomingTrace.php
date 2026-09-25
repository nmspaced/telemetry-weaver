<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * An opaque trace from another process. An invalid one starts a new trace, unlike
 * passing none, which continues the ambient one.
 *
 * @internal
 */
interface IncomingTrace
{
    public function isValid(): bool;
}
