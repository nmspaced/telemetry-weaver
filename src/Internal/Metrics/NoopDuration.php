<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\Measurement;

/**
 * @internal No clock, context capture or per-measurement allocation for a disabled instrument.
 */
final readonly class NoopDuration implements Duration, Measurement
{
    #[\Override]
    public function start(): Measurement
    {
        return $this;
    }

    #[\Override]
    public function stop(array $attributes = []): void {}

    #[\Override]
    public function cancel(): void {}
}
