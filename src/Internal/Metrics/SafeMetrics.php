<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;

/**
 * @internal Instruments belong to the SDK; no unbounded name or attribute cache is maintained here.
 */
// @mago-expect lint:too-many-methods — one method per instrument the OpenTelemetry metrics API
// defines, plus the duration helper. Splitting the facade would only move the count into a
// class an application then has to find.
final readonly class SafeMetrics implements Metrics
{
    private DurationRuntime $durations;

    private SafeObservables $observables;

    public function __construct(
        private MeterInterface $meter,
        private InstrumentationFailureReporter $reporter,
        DurationRecorder $recorder,
        ClockInterface $clock = new SystemClock(),
    ) {
        $this->durations = new DurationRuntime($recorder, $reporter, $clock);
        $this->observables = new SafeObservables($meter, $reporter);
    }

    #[\Override]
    public function counter(string $name, ?string $unit = null, ?string $description = null): CounterInterface
    {
        self::validateName($name);
        try {
            return new SafeCounter($this->meter->createCounter($name, $unit, $description), $this->reporter, $name);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Counter creation failed', $name, $throwable);

            return new NoopMeter()->createCounter($name);
        }
    }

    #[\Override]
    public function histogram(string $name, ?string $unit = null, ?string $description = null): HistogramInterface
    {
        self::validateName($name);
        try {
            return new SafeHistogram($this->meter->createHistogram($name, $unit, $description), $this->reporter, $name);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Histogram creation failed', $name, $throwable);

            return new NoopMeter()->createHistogram($name);
        }
    }

    #[\Override]
    public function upDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
    ): UpDownCounterInterface {
        self::validateName($name);

        try {
            return new SafeUpDownCounter(
                $this->meter->createUpDownCounter($name, $unit, $description),
                $this->reporter,
                $name,
            );
        } catch (\Throwable $throwable) {
            $this->reporter->report('UpDownCounter creation failed', $name, $throwable);

            return new NoopMeter()->createUpDownCounter($name);
        }
    }

    /**
     * `createGauge()` is upstream-experimental; the instrument itself is a stable part of
     * the metrics API, and the alternative — leaving applications to reach past the facade
     * into the meter — costs them the fail-open wrapper for no gain.
     */
    #[\Override]
    public function gauge(string $name, ?string $unit = null, ?string $description = null): GaugeInterface
    {
        self::validateName($name);

        try {
            return new SafeGauge($this->meter->createGauge($name, $unit, $description), $this->reporter, $name);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Gauge creation failed', $name, $throwable);

            return new NoopMeter()->createGauge($name);
        }
    }

    #[\Override]
    public function observableCounter(
        string $name,
        \Closure $observe,
        ?string $unit = null,
        ?string $description = null,
    ): ObservableCallbackInterface {
        self::validateName($name);

        return $this->observables->counter($name, $observe, $unit, $description);
    }

    #[\Override]
    public function observableGauge(
        string $name,
        \Closure $observe,
        ?string $unit = null,
        ?string $description = null,
    ): ObservableCallbackInterface {
        self::validateName($name);

        return $this->observables->gauge($name, $observe, $unit, $description);
    }

    #[\Override]
    public function observableUpDownCounter(
        string $name,
        \Closure $observe,
        ?string $unit = null,
        ?string $description = null,
    ): ObservableCallbackInterface {
        self::validateName($name);

        return $this->observables->upDownCounter($name, $observe, $unit, $description);
    }

    #[\Override]
    public function duration(string $name, DurationUnit $unit, array $boundaries, ?string $description = null): Duration
    {
        self::validateName($name);
        self::validateBoundaries($boundaries);
        if ($this->meter instanceof NoopMeter) {
            return new NoopDuration();
        }

        try {
            $histogram = $this->meter->createHistogram($name, $unit->value, $description, advisory: [
                'ExplicitBucketBoundaries' => $boundaries,
            ]);

            return HistogramDuration::forHistogram($name, $histogram, $unit, $this->durations);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Duration instrument creation failed', $name, $throwable);

            return new NoopDuration();
        }
    }

    private static function validateName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('An instrument name must not be empty.');
        }
    }

    /** @param array<array-key, mixed> $boundaries */
    private static function validateBoundaries(array $boundaries): void
    {
        if ($boundaries === [] || !\array_is_list($boundaries)) {
            throw new \InvalidArgumentException('Duration boundaries must be a non-empty list.');
        }

        $previous = -1;
        /** @var mixed $boundary */
        foreach ($boundaries as $boundary) {
            if (
                !\is_int($boundary) && !\is_float($boundary)
                || !\is_finite((float) $boundary)
                || $boundary < 0
                || $boundary <= $previous
            ) {
                throw new \InvalidArgumentException(
                    'Duration boundaries must be finite, non-negative and strictly increasing.',
                );
            }

            $previous = $boundary;
        }
    }
}
