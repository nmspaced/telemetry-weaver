<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Diagnostics;

use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use OpenTelemetry\API\Common\Time\ClockInterface;

final class RateLimiter
{
    private int $total = 0;

    private int $passed = 0;

    private int $nextAllowedAtNanos = 0;

    /** @var int<0, max> */
    private readonly int $minIntervalNanos;

    /**
     * @param int $burst how many initial events pass without delay
     * @param float $minIntervalSeconds minimum pause between subsequent events
     * @param ClockInterface $clock monotonic time; swapped in tests
     */
    public function __construct(
        private readonly int $burst = 10,
        float $minIntervalSeconds = 60.0,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->minIntervalNanos = \max(0, (int) ($minIntervalSeconds * ClockInterface::NANOS_PER_SECOND));
    }

    /**
     * Records an event and reports whether it should be shown.
     */
    public function allow(): bool
    {
        ++$this->total;

        $now = $this->clock->now();

        if ($this->passed >= $this->burst && $now < $this->nextAllowedAtNanos) {
            return false;
        }

        ++$this->passed;
        $this->nextAllowedAtNanos = $now + $this->minIntervalNanos;

        return true;
    }

    /**
     * Total events, including suppressed ones.
     */
    public function total(): int
    {
        return $this->total;
    }
}
