<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

use OpenTelemetry\API\Common\Time\ClockInterface;

/**
 * Permission for one send: how long it may take; its outcome is reported once.
 *
 * @internal
 */
final class SendAllowance
{
    private bool $settled = false;

    /**
     * @param int $startedAt monotonic nanoseconds
     * @param int $nanoseconds at least `DestinationShare`'s minimum allowance
     */
    public function __construct(
        private readonly DestinationShare $share,
        private readonly int $startedAt,
        private readonly int $nanoseconds,
    ) {}

    public function seconds(): float
    {
        return $this->nanoseconds / ClockInterface::NANOS_PER_SECOND;
    }

    public function succeeded(): void
    {
        $this->settle(false);
    }

    public function failed(): void
    {
        $this->settle(true);
    }

    private function settle(bool $failed): void
    {
        if ($this->settled) {
            return;
        }

        $this->settled = true;
        $this->share->settle($this->startedAt, $this->nanoseconds, $failed);
    }
}
