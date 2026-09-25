<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;

/**
 * Create instruments once for static names; record dynamic attributes on those instruments.
 *
 * @api
 */
interface Metrics
{
    /**
     * @param non-empty-string $name
     */
    public function counter(string $name, ?string $unit = null, ?string $description = null): CounterInterface;

    /**
     * A non-monotonic sum, such as work in flight. Pair every `add(1)` with an `add(-1)`,
     * including in `finally`.
     *
     * @param non-empty-string $name
     */
    public function upDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
    ): UpDownCounterInterface;

    /**
     * @param non-empty-string $name
     */
    public function histogram(string $name, ?string $unit = null, ?string $description = null): HistogramInterface;

    /**
     * The current value, recorded when the application has it. Exported as a last value, not
     * summed across workers.
     *
     * @param non-empty-string $name
     */
    public function gauge(string $name, ?string $unit = null, ?string $description = null): GaugeInterface;

    /**
     * @param non-empty-string $name
     * @param non-empty-list<int|float> $boundaries
     */
    public function duration(
        string $name,
        DurationUnit $unit,
        array $boundaries,
        ?string $description = null,
    ): Duration;

    /**
     * Read when metrics are collected. The callback must be cheap and must not use request
     * state; releasing the returned handle detaches it.
     *
     * @param non-empty-string $name
     * @param \Closure(ObserverInterface): void $observe
     */
    public function observableGauge(
        string $name,
        \Closure $observe,
        ?string $unit = null,
        ?string $description = null,
    ): ObservableCallbackInterface;

    /**
     * A cumulative total read at collection time. Report the total, not the increment.
     *
     * @param non-empty-string $name
     * @param \Closure(ObserverInterface): void $observe
     */
    public function observableCounter(
        string $name,
        \Closure $observe,
        ?string $unit = null,
        ?string $description = null,
    ): ObservableCallbackInterface;

    /**
     * A current amount read at collection time, exported as a summable non-monotonic sum.
     *
     * @param non-empty-string $name
     * @param \Closure(ObserverInterface): void $observe
     */
    public function observableUpDownCounter(
        string $name,
        \Closure $observe,
        ?string $unit = null,
        ?string $description = null,
    ): ObservableCallbackInterface;
}
