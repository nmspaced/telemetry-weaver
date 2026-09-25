<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * Registers observable instruments with callbacks that cannot throw into the SDK's
 * collection pass, where one failure would break every other instrument's export.
 *
 * @internal
 */
final readonly class SafeObservables
{
    public function __construct(
        private MeterInterface $meter,
        private InstrumentationFailureReporter $reporter,
    ) {}

    /**
     * @param non-empty-string $name
     * @param \Closure(ObserverInterface): void $observe
     */
    public function counter(
        string $name,
        \Closure $observe,
        ?string $unit = null,
        ?string $description = null,
    ): ObservableCallbackInterface {
        return $this->register(
            $name,
            $observe,
            static fn(MeterInterface $meter): ObservableCounterInterface => $meter->createObservableCounter(
                $name,
                $unit,
                $description,
            ),
        );
    }

    /**
     * @param non-empty-string $name
     * @param \Closure(ObserverInterface): void $observe
     */
    public function gauge(
        string $name,
        \Closure $observe,
        ?string $unit = null,
        ?string $description = null,
    ): ObservableCallbackInterface {
        return $this->register(
            $name,
            $observe,
            static fn(MeterInterface $meter): ObservableGaugeInterface => $meter->createObservableGauge(
                $name,
                $unit,
                $description,
            ),
        );
    }

    /**
     * @param non-empty-string $name
     * @param \Closure(ObserverInterface): void $observe
     */
    public function upDownCounter(
        string $name,
        \Closure $observe,
        ?string $unit = null,
        ?string $description = null,
    ): ObservableCallbackInterface {
        return $this->register(
            $name,
            $observe,
            static fn(MeterInterface $meter): ObservableUpDownCounterInterface => $meter
                ->createObservableUpDownCounter($name, $unit, $description),
        );
    }

    /**
     * Falls back to a no-op instrument on failure, so the caller always gets a valid handle.
     *
     * @param non-empty-string $name
     * @param \Closure(ObserverInterface): void $observe
     * @param \Closure(MeterInterface): (ObservableCounterInterface|ObservableGaugeInterface|ObservableUpDownCounterInterface) $create
     */
    private function register(string $name, \Closure $observe, \Closure $create): ObservableCallbackInterface
    {
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
}
