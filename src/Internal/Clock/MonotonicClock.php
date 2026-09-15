<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Clock;

use OpenTelemetry\API\Common\Time\ClockInterface;

/** @internal Elapsed time is independent of wall-clock adjustments. */
final readonly class MonotonicClock implements ClockInterface
{
    #[\Override]
    public function now(): int
    {
        return (int) \hrtime(true);
    }
}
