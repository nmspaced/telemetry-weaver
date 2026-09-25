<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Propagation;

/**
 * Headers a server writes back about the trace it served.
 *
 * @internal
 */
interface ResponsePropagation
{
    /**
     * Empty when there is no propagator or no valid trace.
     *
     * @return array<non-empty-string, string>
     */
    public function headers(): array;
}
