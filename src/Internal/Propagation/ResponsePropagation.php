<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Propagation;

/**
 * What a server should write back about the trace it just served.
 *
 * The counterpart of incoming propagation, and the half a framework has to supply: the
 * SDK can build the headers, but only the HTTP integration knows where a response is and
 * when it is still safe to touch.
 *
 * Headers rather than a carrier object. The values are a small `string => string` map by
 * specification, every caller ends up putting them somewhere that already has a header
 * bag, and an abstraction over "somewhere to set strings" would earn nothing while
 * pulling OpenTelemetry's setter interfaces into instrumentation.
 *
 * @internal
 */
interface ResponsePropagation
{
    /**
     * Empty whenever there is nothing to say — no propagator configured, or no valid
     * trace to describe — so a caller never has to ask which of the two it is.
     *
     * @return array<non-empty-string, string>
     */
    public function headers(): array;
}
