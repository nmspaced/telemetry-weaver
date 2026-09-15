<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Clock;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\ClockInterface;

final class SystemClock implements ClockInterface
{
    #[\Override]
    public function now(): int
    {
        return Clock::getDefault()->now();
    }
}
