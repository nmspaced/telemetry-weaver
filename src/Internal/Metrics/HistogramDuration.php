<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * @internal Unit and histogram are paired once by the instrument factory.
 */
final readonly class HistogramDuration implements StartableDuration
{
    private function __construct(
        private string $name,
        private HistogramInterface $histogram,
        private DurationUnit $unit,
        private DurationRuntime $runtime,
    ) {}

    /**
     * @param non-empty-string $name
     */
    public static function forHistogram(
        string $name,
        HistogramInterface $histogram,
        DurationUnit $unit,
        DurationRuntime $runtime,
    ): self {
        return new self($name, $histogram, $unit, $runtime);
    }

    #[\Override]
    public function start(?TraceCorrelation $correlation): Measurement
    {
        try {
            return SafeMeasurement::started(
                DurationTimer::started($this->histogram, $this->unit, $this->runtime, $correlation),
                $this->runtime->reporter,
                $this->name,
            );
        } catch (\Throwable $throwable) {
            $this->runtime->reporter->report('Duration start failed', $this->name, $throwable);

            return new NoopDuration();
        }
    }
}
