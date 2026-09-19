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
     * A quantity that goes both ways: work in flight, connections open, items queued.
     *
     * Not a counter with negative amounts — the two are exported differently. A counter is
     * a monotonic sum, so a backend may compute a rate from it; this is a non-monotonic
     * one, where the current value is the point and a rate is meaningless. Pair every
     * `add(1)` with an `add(-1)` on the path that undoes the work, `finally` included, or
     * the series drifts up and never comes back.
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
     * The current value of something, recorded at a moment the application chooses.
     *
     * The synchronous counterpart of `observableGauge()`, and the choice between them is
     * about when the value exists rather than what it means. Use this when a value arrives
     * as an event — a queue depth a broker just told you, a temperature a device reported.
     * Use the observable one when the value can be read at any time and there is no natural
     * moment to record it.
     *
     * Exported as a last value, so it is not summed across workers or attribute sets. If
     * the number is an amount that should add up, it is an up-down counter, not a gauge.
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
     * The read-at-collection counterpart of `counter()`: a total that only grows and can be
     * read at any time — bytes a process has written since it started, work it has
     * completed.
     *
     * Reports the cumulative total, not the change since the last collection. The SDK
     * computes deltas from it where the temporality calls for them, so a callback that
     * returns an increment produces a series that climbs far too fast.
     *
     * Everything said of `observableGauge()` about the callback and the handle holds here.
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
