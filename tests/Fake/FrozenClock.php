<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * Controllable monotonic time in nanoseconds.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(
        private int $nanoseconds = 0,
    ) {}

    #[\Override]
    public function now(): int
    {
        return $this->nanoseconds;
    }

    public function advanceSeconds(float $seconds): void
    {
        $this->nanoseconds += (int) ($seconds * 1_000_000_000);
    }

    public function advanceNanoseconds(int $nanoseconds): void
    {
        $this->nanoseconds += $nanoseconds;
    }
}
