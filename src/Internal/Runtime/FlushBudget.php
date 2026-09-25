<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

use Nmspaced\TelemetryWeaver\Internal\Clock\MonotonicClock;
use OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * One deadline per flush, divided between destinations (collector origins) rather than
 * signals, so a hung collector cannot starve a healthy one. Share size lives in
 * `FlushWindow`, exhaustion in `DestinationShare`, cooldowns in `DestinationCooldowns`.
 *
 * @internal
 */
final class FlushBudget
{
    /** Part of an even split a send must have had for its timeout to trigger a cooldown. */
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

    /** How long one send may take; null when it may not start. */
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
     * This destination plus every one not yet served or skipped.
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
