<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\API\Common\Time\ClockInterface;

/** A clock that starts failing once broken. */
final class BreakableClock implements ClockInterface
{
    public bool $broken = false;

    /** @throws \RuntimeException once broken */
    #[\Override]
    public function now(): int
    {
        if ($this->broken) {
            throw new \RuntimeException('clock is gone');
        }

        return 0;
    }
}
