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
 * Registering the read-at-collection instruments, apart from the ones written to.
 *
 * Separate because the failure modes are: a synchronous instrument fails where the caller
 * is standing, and the worst it can do is lose one measurement. An observable's callback
 * runs later, inside the SDK's single collection pass over every registered callback, so
 * one that throws takes down the export of instruments that have nothing to do with it —
 * and it hands back a registration handle whose lifetime the caller now owns.
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
     * A creation failure falls back to the same instrument on a no-op meter, so the caller
     * still holds a valid handle and has nothing to check.
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
