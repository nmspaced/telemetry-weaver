<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;

/**
 * @internal Mutable ownership ends before recording, including when the clock or SDK throws.
 */
final class SafeMeasurement implements Measurement
{
    private function __construct(
        private ?DurationTimer $timer,
        private readonly InstrumentationFailureReporter $reporter,
        private readonly string $name,
    ) {}

    public static function started(DurationTimer $timer, InstrumentationFailureReporter $reporter, string $name): self
    {
        return new self($timer, $reporter, $name);
    }

    #[\Override]
    public function stop(array $attributes = []): void
    {
        $timer = $this->timer;
        $this->timer = null;
        try {
            $timer?->stop($attributes);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Duration recording failed', $this->name, $throwable);
        } finally {
            $timer?->cancel();
        }
    }

    #[\Override]
    public function cancel(): void
    {
        $timer = $this->timer;
        $this->timer = null;
        $timer?->cancel();
    }
}
