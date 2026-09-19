<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeCounter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeGauge;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeObservables;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeUpDownCounter;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * That each facade method reaches the OpenTelemetry instrument it claims to.
 *
 * Which instrument a name is created as is not cosmetic: it decides how a backend is
 * allowed to aggregate the series. A monotonic sum may be turned into a rate and a
 * non-monotonic one may not; a gauge is a last value and is never added across workers.
 * Getting that wrong produces a chart that is wrong rather than an error, so the shape is
 * read back off a real `MeterProvider` rather than asserted against a mock.
 */
#[CoversClass(SafeMetrics::class)]
#[CoversClass(SafeObservables::class)]
#[CoversClass(SafeUpDownCounter::class)]
#[CoversClass(SafeGauge::class)]
#[CoversClass(SafeCounter::class)]
final class MetricInstrumentsTest extends PublicTelemetryTestCase
{
    #[Test]
    public function anUpDownCounterIsExportedAsANonMonotonicSum(): void
    {
        $metrics = $this->telemetry()->metrics();

        $inFlight = $metrics->upDownCounter('app.jobs.active', '{job}', 'Jobs being processed.');
        $inFlight->add(3);
        $inFlight->add(-1);

        $sum = $this->sum('app.jobs.active');
        self::assertFalse($sum->monotonic, 'work in flight goes both ways and cannot be turned into a rate');
        self::assertSame(2, $this->value('app.jobs.active'));
    }

    /**
     * The distinction the facade previously forced applications to give up: without an
     * up-down counter they would have reached for `counter()`, whose sum a backend is
     * entitled to differentiate.
     */
    #[Test]
    public function aCounterIsExportedAsAMonotonicSum(): void
    {
        $this->telemetry()->metrics()->counter('app.jobs.completed')->add(2);

        self::assertTrue($this->sum('app.jobs.completed')->monotonic);
    }

    #[Test]
    public function aGaugeIsExportedAsALastValue(): void
    {
        $depth = $this->telemetry()->metrics()->gauge('app.queue.depth', '{message}', 'Depth the broker reported.');
        $depth->record(40);
        $depth->record(7);

        self::assertInstanceOf(Gauge::class, $this->metricNamed('app.queue.depth')->data);
        self::assertSame(7, $this->value('app.queue.depth'), 'a gauge keeps the last value, it does not add them up');
    }

    #[Test]
    public function anObservableCounterIsExportedAsAMonotonicSum(): void
    {
        $handle = $this
            ->telemetry()
            ->metrics()
            ->observableCounter(
                'app.bytes.written',
                static fn(ObserverInterface $observer): null => $observer->observe(1_024) ?? null,
                'By',
                'Bytes written since the worker started.',
            );

        try {
            self::assertTrue($this->sum('app.bytes.written')->monotonic);
            self::assertSame(1_024, $this->value('app.bytes.written'));
        } finally {
            $handle->detach();
        }
    }

    /**
     * A failing instrument must still hand back something recordable, as everywhere else in
     * the facade — an application does not check what it was given.
     */
    #[Test]
    public function theNewInstrumentsStillAnswerWhenMetricsAreOff(): void
    {
        $metrics = $this->telemetry(metrics: false)->metrics();

        $metrics->upDownCounter('off.updown')->add(1);
        $metrics->gauge('off.gauge')->record(1);
        $handle = $metrics->observableCounter('off.observable', self::observesNothing(...));
        $handle->detach();

        self::assertSame([], $this->metricExporter->collect(true));
        $this->assertNoReports();
    }

    /** An observable that is never expected to be collected, because metrics are off. */
    private static function observesNothing(ObserverInterface $_observer): never
    {
        self::fail('a disabled meter must not collect');
    }

    /** @param non-empty-string $name */
    private function sum(string $name): Sum
    {
        $data = $this->metricNamed($name)->data;
        self::assertInstanceOf(Sum::class, $data);

        return $data;
    }

    /** @param non-empty-string $name */
    private function value(string $name): float|int
    {
        $point = MetricPoints::first($this->metricNamed($name));
        self::assertInstanceOf(NumberDataPoint::class, $point);

        return $point->value;
    }

    /** @param non-empty-string $name */
    private function metricNamed(string $name): Metric
    {
        $this->meters->forceFlush();

        foreach ($this->metricExporter->collect(true) as $metric) {
            self::assertInstanceOf(Metric::class, $metric);

            if ($metric->name === $name) {
                return $metric;
            }
        }

        self::fail(\sprintf('no metric named "%s" was exported', $name));
    }
}
