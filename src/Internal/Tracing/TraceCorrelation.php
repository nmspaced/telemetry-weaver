<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * An opaque token for the trace an operation runs in, so a measurement recorded after the
 * span stopped being current still gets the right exemplar.
 *
 * @internal
 */
interface TraceCorrelation {}
