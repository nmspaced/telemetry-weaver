<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

use OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * Collectors that timed out recently, remembered across flushes of one container.
 *
 * @internal
 */
final class DestinationCooldowns
{
    /** @var array<string, int> monotonic nanoseconds */
    private array $until = [];

    /** @param int<0, max> $milliseconds */
    public function __construct(
        private readonly int $milliseconds,
        private readonly ClockInterface $clock,
    ) {}

    public function start(string $destination): void
    {
        $this->until[$destination] =
            $this->clock->now() + ($this->milliseconds * ClockInterface::NANOS_PER_MILLISECOND);
    }

    public function active(string $destination, int $now): bool
    {
        return $now < ($this->until[$destination] ?? 0);
    }
}
