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
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * @internal Instruments belong to the SDK; no unbounded name or attribute cache is maintained here.
 */
final readonly class SafeMetrics implements Metrics
{
    public function __construct(
        private MeterInterface $meter,
        private InstrumentationFailureReporter $reporter,
        private ClockInterface $clock = new SystemClock(),
    ) {}

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
    public function observableGauge(
        string $name,
        \Closure $observe,
        ?string $unit = null,
        ?string $description = null,
    ): ObservableCallbackInterface {
        return $this->observable(
            $name,
            $observe,
            static fn(MeterInterface $meter): ObservableGaugeInterface => $meter->createObservableGauge(
                $name,
                $unit,
                $description,
            ),
        );
    }

    #[\Override]
    public function observableUpDownCounter(
        string $name,
        \Closure $observe,
        ?string $unit = null,
        ?string $description = null,
    ): ObservableCallbackInterface {
        return $this->observable(
            $name,
            $observe,
            static fn(MeterInterface $meter): ObservableUpDownCounterInterface => $meter->createObservableUpDownCounter(
                $name,
                $unit,
                $description,
            ),
        );
    }

    /**
     * The callback is wrapped because the SDK calls every registered callback in one
     * collection pass, so a throwing callback would take the whole export down with it —
     * including the instruments that had nothing to do with it.
     *
     * A creation failure falls back to the same instrument on a no-op meter, so the caller
     * still holds a valid handle and has nothing to check.
     *
     * @param \Closure(ObserverInterface): void $observe
     * @param \Closure(MeterInterface): (ObservableGaugeInterface|ObservableUpDownCounterInterface) $create
     */
    private function observable(string $name, \Closure $observe, \Closure $create): ObservableCallbackInterface
    {
        self::validateName($name);

        try {
            return $create($this->meter)->observe(function (ObserverInterface $observer) use ($observe, $name): void {
                try {
                    $observe($observer);
                } catch (\Throwable $throwable) {
                    $this->reporter->report('Observation failed', $name, $throwable);
                }
            });
        } catch (\Throwable $throwable) {
            $this->reporter->report('Observable instrument creation failed', $name, $throwable);

            return $create(new NoopMeter())->observe(static fn(): null => null);
        }
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

            return HistogramDuration::forHistogram($name, $histogram, $unit, $this->clock, $this->reporter);
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
                (!\is_int($boundary) && !\is_float($boundary))
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
