<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

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
     * @param non-empty-string $name
     */
    public function histogram(string $name, ?string $unit = null, ?string $description = null): HistogramInterface;

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
     * An instrument that is read rather than written: the callback runs when metrics are
     * collected, not when the value changes.
     *
     * For state that exists continuously and has no natural moment to record it — memory
     * in use, a queue's depth, how long the process has been up. The callback must be
     * cheap, must not touch per-request state, and must not depend on anything that only
     * exists during a request: it is invoked at export time, on whatever execution
     * happens to be crossing a flush boundary.
     *
     * Keep the returned handle for as long as the measurements should be reported.
     * Releasing it detaches the callback, which is the only way to stop it.
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
     * The same read-at-collection instrument, for a quantity that is an amount rather than
     * a sample: memory held, connections open, items queued.
     *
     * The two are not interchangeable even though both report the current value. An
     * up-down counter is exported as a non-monotonic sum, so a backend may add it across
     * attributes and instances — the memory of every worker is a meaningful total — while
     * a gauge is exported as a last value that has no sum. The semantic conventions pick
     * the type per metric; follow them rather than whichever reads more naturally.
     *
     * Everything said of `observableGauge()` about the callback and the handle holds here.
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
