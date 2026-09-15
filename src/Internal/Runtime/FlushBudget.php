<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Internal\Clock\MonotonicClock;
use OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * @internal One deadline for a flush, shared fairly between the collectors it sends to.
 *
 * The deadline bounds how long a flush may block, whatever it sends. Within it, time is
 * divided by destination — the collector's origin, `scheme://host:port` — not by signal,
 * because collectors fail, not signals. Traces, logs and metrics sent to one Alloy share
 * its fate; sent to different collectors, a hung one must not starve the rest. Measured
 * before this existed: with traces pointed at a collector that accepts and never answers,
 * the trace export consumed the whole budget, and logs and metrics reached their healthy
 * collector on no request at all under FPM, where no cooldown survives between requests.
 *
 * The rules, each in the type that owns it:
 *
 *  - Share size (`FlushWindow`): fixed on a destination's first send of the flush, as the time
 *    left divided by the destinations not yet served. Time a fast collector did not use rolls
 *    over to the ones after it. Destinations with a transport but nothing to send still count,
 *    which can only make a share shorter — the flush ends early, never late.
 *  - Exhaustion (`DestinationShare`): a destination that used up its share, or timed out, is
 *    refused for the rest of the flush — every later send to it, from any signal or batch, at
 *    once, instead of waiting out the same timeout again.
 *  - Cooldown (`DestinationCooldowns`): a destination that timed out is skipped by scheduled
 *    boundaries for `failure_cooldown_ms`, but only when the timeout is conclusive — the send
 *    had at least half of an even split of the whole budget. A healthy collector that only
 *    got the scraps a slow one left behind would otherwise sit out thirty seconds of
 *    boundaries for someone else's delay. A final flush tries a cooling destination anyway,
 *    as it ignores the signal schedule.
 *
 * Not readonly: the open window is state. Everything about one flush lives in its window and is
 * dropped whole by `end()`, which the coordinator calls in `finally`. What outlives a flush —
 * registered destinations and their cooldowns — is bounded by the transports this container
 * built, at most one per signal, and holds only strings and integers.
 */
final class FlushBudget
{
    /**
     * A timeout proves a collector unhealthy only if it had a fair chance: at least this part of
     * an even split of the whole budget. Not the full split, because collecting metrics and
     * serializing batches spend some of the budget before the first send even starts.
     */
    private const float CONCLUSIVE_SHARE = 0.5;

    private ?FlushWindow $window = null;

    /** @var array<string, true> */
    private array $destinations = [];

    private readonly DestinationCooldowns $cooldowns;

    /**
     * @param positive-int $timeoutMilliseconds
     * @param int<0, max> $failureCooldownMilliseconds
     */
    public function __construct(
        private readonly int $timeoutMilliseconds = 1000,
        private readonly ClockInterface $clock = new MonotonicClock(),
        int $failureCooldownMilliseconds = 30_000,
    ) {
        $this->cooldowns = new DestinationCooldowns($failureCooldownMilliseconds, $clock);
    }

    /** @param bool $final a final flush tries destinations that are cooling down */
    public function begin(bool $final = false): void
    {
        $timeout = $this->timeoutMilliseconds * ClockInterface::NANOS_PER_MILLISECOND;
        $this->window = new FlushWindow(
            $this->clock->now() + $timeout,
            $final,
            (int) (($timeout / \max(1, \count($this->destinations))) * self::CONCLUSIVE_SHARE),
            $this->cooldowns,
            $this->clock,
        );
    }

    public function end(): void
    {
        $this->window = null;
    }

    public function active(): bool
    {
        return $this->window !== null;
    }

    /** null means no boundary is active; zero means no more work may start. */
    public function remainingSeconds(): ?float
    {
        if ($this->window === null) {
            return null;
        }

        return $this->window->remaining($this->clock->now()) / ClockInterface::NANOS_PER_SECOND;
    }

    public function exhausted(): bool
    {
        return $this->remainingSeconds() === 0.0;
    }

    /** A transport to this destination exists, so it is owed a share of every flush. */
    public function register(string $destination): void
    {
        $this->destinations[$destination] = true;
    }

    /**
     * How long one send to the destination may take. null when it may not start: there is no
     * flush, or the destination is cooling down, exhausted or out of time.
     */
    public function allowance(string $destination): ?SendAllowance
    {
        $window = $this->window;
        $now = $this->clock->now();
        if ($window === null || $window->skips($destination, $now)) {
            return null;
        }

        return $window->share($destination, $now, $this->waiting($destination, $window, $now))->allowance($now);
    }

    /**
     * This destination and every other one this flush has not served yet and will not skip.
     *
     * @return positive-int
     */
    private function waiting(string $destination, FlushWindow $window, int $now): int
    {
        $waiting = 1;
        foreach (\array_keys($this->destinations) as $other) {
            if ($other === $destination || $window->served($other) || $window->skips($other, $now)) {
                continue;
            }

            ++$waiting;
        }

        return $waiting;
    }
}
