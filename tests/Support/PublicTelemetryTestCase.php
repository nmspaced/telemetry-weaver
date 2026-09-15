<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\Assert;

/**
 * Builds a real `DefaultTelemetry` over the shared `SpanOpener`/context storage from
 * {@see TelemetryTestCase} plus a fresh `SafeMetrics`, letting every `PublicTelemetryTest`
 * split carry only the scenarios specific to it.
 *
 * @internal
 */
abstract class PublicTelemetryTestCase extends TelemetryTestCase
{
    protected MeterProviderInterface $meters;

    protected InMemoryExporter $metricExporter;

    protected FrozenClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock();
        $this->metricExporter = new InMemoryExporter(temporality: Temporality::CUMULATIVE);
        $this->meters = MeterProvider::builder()->addReader(new ExportingReader($this->metricExporter))->build();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->meters->shutdown();
        parent::tearDown();
    }

    protected function telemetry(bool $traces = true, bool $metrics = true): Telemetry
    {
        return new DefaultTelemetry(
            $traces ? $this->spans : new NoOpSpanOpener(),
            new SafeMetrics(
                $metrics ? $this->meters->getMeter('test') : new NoopMeter(),
                $this->reporter,
                $this->clock,
            ),
            $this->reporter,
            $this->contextStorage,
        );
    }

    protected function duration(Telemetry $telemetry): Duration
    {
        return $telemetry->metrics()->duration('duration', DurationUnit::Seconds, [0.1, 0.5, 1]);
    }

    protected function metric(): Metric
    {
        $this->meters->forceFlush();

        return $this->metricExporter->collect(true)[0] ?? Assert::fail('no metric was recorded');
    }

    protected function metricPoint(): HistogramDataPoint
    {
        return MetricPoints::firstHistogram($this->metric());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function signals(): iterable
    {
        yield 'both' => [true, true];
        yield 'traces' => [true, false];
        yield 'metrics' => [false, true];
        yield 'neither' => [false, false];
    }
}
