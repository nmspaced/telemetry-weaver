<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Api\Measurement;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * @internal Unit and histogram are paired once by the instrument factory.
 */
final readonly class HistogramDuration implements Duration
{
    private function __construct(
        private string $name,
        private HistogramInterface $histogram,
        private DurationUnit $unit,
        private ClockInterface $clock,
        private InstrumentationFailureReporter $reporter,
    ) {}

    public static function forHistogram(
        string $name,
        HistogramInterface $histogram,
        DurationUnit $unit,
        ClockInterface $clock,
        InstrumentationFailureReporter $reporter,
    ): self {
        return new self($name, $histogram, $unit, $clock, $reporter);
    }

    #[\Override]
    public function start(): Measurement
    {
        try {
            return SafeMeasurement::started(
                new DurationTimer($this->histogram, $this->unit, $this->clock),
                $this->reporter,
                $this->name,
            );
        } catch (\Throwable $throwable) {
            $this->reporter->report('Duration start failed', $this->name, $throwable);

            return new NoopDuration();
        }
    }
}
