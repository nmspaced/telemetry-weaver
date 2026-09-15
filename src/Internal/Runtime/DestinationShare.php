<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

use OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * @internal The part of one flush a destination may spend, across all its signals and batches.
 *
 * What a send's outcome costs the destination is decided here:
 *
 *  - A send that used its whole allowance exhausts the share, whether it succeeded or not:
 *    there is no time left for that collector in this flush.
 *  - A send that failed after nearly all of it timed out, and the share is exhausted. "Nearly"
 *    is 90%, because a client gives up a little before the deadline it was handed — curl
 *    reported 492 ms of a 500 ms allowance. The destination also cools down, if the allowance
 *    was long enough for the timeout to be about the collector (see `FlushBudget`).
 *  - An allowance under 5 ms is not granted: clients count timeouts in whole milliseconds
 *    (the gRPC transport truncates to them), so such a send could only fail, and its failure
 *    would look like a timeout. The share is exhausted instead, without a cooldown.
 *  - A send that failed fast — a refused connection, a 4xx for one signal's payload — is cheap
 *    and says nothing about the next signal, so it costs nothing beyond its own time.
 *
 * Not readonly: being exhausted is state, and it must be — it is what refuses the second
 * signal bound for a collector that has already cost this flush its share.
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
        $nanoseconds = $this->exhausted ? 0 : $this->end - $now;
        if ($nanoseconds < self::MINIMUM_ALLOWANCE) {
            $this->exhausted = true;

            return null;
        }

        return new SendAllowance($this, $now, $nanoseconds);
    }

    /**
     * Reported by `SendAllowance`, once per send.
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
