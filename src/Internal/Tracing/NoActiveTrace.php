<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\Api\TraceContext;

/**
 * Keeps ActiveTrace autowirable with the bundle disabled, without reading external
 * context or constructing the OpenTelemetry integration.
 *
 * @internal
 */
final readonly class NoActiveTrace implements ActiveTrace
{
    #[\Override]
    public function current(): ?TraceContext
    {
        return null;
    }
}
