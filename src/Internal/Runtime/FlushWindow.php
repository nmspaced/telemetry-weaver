<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

use OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * One flush: its deadline and each destination's share. Dropped when the flush ends.
 *
 * @internal
 */
final class FlushWindow
{
    /** @var array<string, DestinationShare> */
    private array $shares = [];

    /**
     * @param int $deadline monotonic nanoseconds
     * @param bool $final a final flush tries destinations that are cooling down
     * @param int $conclusiveAllowance nanoseconds; a shorter send that times out starts no cooldown
     */
    public function __construct(
        private readonly int $deadline,
        private readonly bool $final,
        private readonly int $conclusiveAllowance,
        private readonly DestinationCooldowns $cooldowns,
        private readonly ClockInterface $clock,
    ) {}

    /** @return int<0, max> nanoseconds */
    public function remaining(int $now): int
    {
        return \max(0, $this->deadline - $now);
    }

    /** Whether the destination is skipped by this flush without being tried. */
    public function skips(string $destination, int $now): bool
    {
        return !$this->final && $this->cooldowns->active($destination, $now);
    }

    public function served(string $destination): bool
    {
        return \array_key_exists($destination, $this->shares);
    }

    /** @param positive-int $waiting destinations still owed a share, this one included */
    public function share(string $destination, int $now, int $waiting): DestinationShare
    {
        return $this->shares[$destination] ??= new DestinationShare(
            $destination,
            $now + \intdiv($this->remaining($now), $waiting),
            $this->conclusiveAllowance,
            $this->cooldowns,
            $this->clock,
        );
    }
}
