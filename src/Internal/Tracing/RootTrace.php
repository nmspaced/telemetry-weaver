<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * @internal An explicit root when incoming propagation failed; never inherit ambient context.
 */
final readonly class RootTrace implements IncomingTrace
{
    #[\Override]
    public function isValid(): bool
    {
        return false;
    }
}
