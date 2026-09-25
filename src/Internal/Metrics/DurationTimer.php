<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * One measurement of one interval.
 *
 * Not readonly: whether the interval is already closed is state, and it has
 * to survive past construction — without it a caller that closes twice would
 * record the same interval twice and skew the distribution.
 *
 * The clock is monotonic nanoseconds; the unit comes from the same
 * OperationBuckets that supplied the boundaries, so the two cannot diverge.
 *
 * The correlation is handed in rather than read from the execution. It used to be the
 * ambient context at construction, which happened to be right only because the operation
 * activated its span first — an ordering nothing stated and nothing checked. Now whoever
 * starts the measurement says which trace it belongs to, and an interval that outlives its
 * span's activation still records against the span that was actually being measured.
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

    /**
     * Named because building one is not a neutral act: the interval begins here, on the
     * clock this runtime carries. The correlation is the trace the eventual measurement
     * belongs to, captured now rather than read from the execution when it is recorded.
     */
    public static function started(
        HistogramInterface $histogram,
        DurationUnit $unit,
        DurationRuntime $runtime,
        ?TraceCorrelation $correlation = null,
    ): self {
        return new self($histogram, $unit, $runtime, $correlation);
    }

    /**
     * Silently ignores a repeated call: telemetry has no right to break the caller.
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

    /** A monotonic clock cannot go backwards, but a test double can; never negative. */
    private function sinceResumed(): int
    {
        return $this->runningSince === null ? 0 : \max(0, $this->runtime->clock->now() - $this->runningSince);
    }
}
