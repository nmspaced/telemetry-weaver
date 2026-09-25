<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

use OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * The part of one flush a destination may spend across all its sends. A send that uses its
 * whole allowance, or fails after 90% of it (a timeout), exhausts the share; a conclusive
 * timeout also starts a cooldown. Allowances under 5 ms are refused.
 *
 * @internal
 */
final class DestinationShare
{
    private const float TIMEOUT_SHARE = 0.9;

    private const int MINIMUM_ALLOWANCE = 5 * ClockInterface::NANOS_PER_MILLISECOND;

    private bool $exhausted = false;

    /**
     * @param int $end monotonic nanoseconds
     * @param int $conclusiveAllowance nanoseconds; a shorter send that times out starts no cooldown
     */
    public function __construct(
        private readonly string $destination,
        private readonly int $end,
        private readonly int $conclusiveAllowance,
        private readonly DestinationCooldowns $cooldowns,
        private readonly ClockInterface $clock,
    ) {}

    public function allowance(int $now): ?SendAllowance
    {
        if ($this->exhausted) {
            return null;
        }

        $nanoseconds = $this->end - $now;
        if ($nanoseconds < self::MINIMUM_ALLOWANCE) {
            $this->exhausted = true;

            return null;
        }

        return new SendAllowance($this, $now, $nanoseconds);
    }

    /**
     * Called by `SendAllowance` once per send.
     *
     * @param int $startedAt monotonic nanoseconds
     * @param int $allowed nanoseconds, as granted by `allowance()`
     */
    public function settle(int $startedAt, int $allowed, bool $failed): void
    {
        $elapsed = $this->clock->now() - $startedAt;
        $timedOut = $failed && $elapsed >= ($allowed * self::TIMEOUT_SHARE);
        if ($timedOut && $allowed >= $this->conclusiveAllowance) {
            $this->cooldowns->start($this->destination);
        }

        $this->exhausted = $this->exhausted || $timedOut || $elapsed >= $allowed;
    }
}
