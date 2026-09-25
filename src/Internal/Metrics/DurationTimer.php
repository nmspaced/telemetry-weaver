<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * One measured interval on a monotonic clock, recorded at most once. The trace it belongs
 * to is given at start, not read from the ambient context when it is recorded.
 *
 * @internal
 */
final class DurationTimer
{
    /** When the clock last started, or null while it is paused. */
    private ?int $runningSince;

    /** Time measured before the last pause, in nanoseconds. */
    private int $elapsed = 0;

    private bool $stopped = false;

    private ?TraceCorrelation $correlation;

    private function __construct(
        private readonly HistogramInterface $histogram,
        private readonly DurationUnit $unit,
        private readonly DurationRuntime $runtime,
        ?TraceCorrelation $correlation,
    ) {
        $this->runningSince = $runtime->clock->now();
        $this->correlation = $correlation;
    }

    /** Starts the interval now. */
    public static function started(
        HistogramInterface $histogram,
        DurationUnit $unit,
        DurationRuntime $runtime,
        ?TraceCorrelation $correlation = null,
    ): self {
        return new self($histogram, $unit, $runtime, $correlation);
    }

    /**
     * Records the interval; later calls do nothing.
     *
     * @param array<non-empty-string, string|int|float|bool|list<string|int|float|bool>|null> $attributes
     */
    public function stop(array $attributes = []): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;

        $correlation = $this->correlation;
        $this->correlation = null;

        $this->runtime->recorder->record(
            $this->histogram,
            $this->unit->fromNanoseconds($this->elapsed + $this->sinceResumed()),
            $attributes,
            $correlation,
        );
    }

    public function pause(): void
    {
        if ($this->stopped || $this->runningSince === null) {
            return;
        }

        $this->elapsed += $this->sinceResumed();
        $this->runningSince = null;
    }

    public function resume(): void
    {
        if ($this->stopped || $this->runningSince !== null) {
            return;
        }

        $this->runningSince = $this->runtime->clock->now();
    }

    public function cancel(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $this->correlation = null;
    }

    /** Never negative, even with a test clock that goes backwards. */
    private function sinceResumed(): int
    {
        if ($this->runningSince === null) {
            return 0;
        }

        return \max(0, $this->runtime->clock->now() - $this->runningSince);
    }
}
