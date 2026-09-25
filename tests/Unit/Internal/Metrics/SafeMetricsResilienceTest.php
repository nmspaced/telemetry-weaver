<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Internal\Metrics;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Measurement;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Metrics\StartableDuration;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use Nmspaced\TelemetryWeaver\Tests\Support\PublicTelemetryTestCase;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use const INF;
use const NAN;

/**
 * `SafeMetrics` never lets instrument creation, recording, or a broken clock reach the caller, and
 * a disabled duration never reads the clock at all.
 */
final class SafeMetricsResilienceTest extends PublicTelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    #[DataProvider('signals')]
    public function signalSwitchesAreIndependent(bool $traces, bool $metrics): void
    {
        $telemetry = $this->telemetry($traces, $metrics);
        $calls = 0;
        $telemetry
            ->operation('work')
            ->duration($this->duration($telemetry))
            ->run(static function () use (&$calls): void {
                ++$calls;
            });
        self::assertSame(1, $calls);
        self::assertCount($traces ? 1 : 0, $this->exported());
        $this->meters->forceFlush();
        $data = \array_values($this->metricExporter->collect(true));
        self::assertCount($metrics ? 1 : 0, $data);
        if ($metrics) {
            $metric = $data[0] ?? Assert::fail('missing metric');
            self::assertSame(1, MetricPoints::firstHistogram($metric)->count);
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function disabledDurationNeverReadsClockAndWithoutSpanKeepsParent(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::never())->method('now');
        $metrics = new SafeMetrics(new NoopMeter(), $this->reporter, new OtelDurationRecorder(), $clock);
        self::startable($metrics->duration('duration', DurationUnit::Seconds, [0.1, 1]))
            ->start(null)
            ->stop();
        $telemetry = $this->telemetry();
        $parent = $telemetry->operation('parent')->start();
        $id = $parent->span()->spanId();
        $telemetry
            ->boundary('suppressed')
            ->withoutSpan()
            ->duration($this->duration($telemetry))
            ->run(static function () use ($id): void {
                self::assertSame($id, OtelSpan::getCurrent()->getContext()->getSpanId());
            });
        $parent->finish();
        self::assertSame(['parent'], $this->exportedNames());
        self::assertSame(1, $this->metricPoint()->count);
    }

    /** @throws \Throwable */
    #[Test]
    public function brokenMeasurementStartAndCleanupCannotReplaceTheBusinessFailure(): void
    {
        $telemetry = $this->telemetry();
        $measurement = $this->createStub(Measurement::class);
        $measurement->method('stop')->willThrowException(new \RuntimeException('stop failed'));
        $measurement->method('cancel')->willThrowException(new \RuntimeException('cancel failed'));
        $duration = $this->createStub(StartableDuration::class);
        $duration->method('start')->willReturn($measurement);
        $error = new \LogicException('business error');
        try {
            $telemetry
                ->operation('broken')
                ->duration($duration)
                ->run(static function () use ($error): never {
                    throw $error;
                });
        } catch (\LogicException $logicException) {
            self::assertSame($error, $logicException);
        }

        $brokenStart = $this->createStub(StartableDuration::class);
        $brokenStart->method('start')->willThrowException(new \RuntimeException('start failed'));
        self::assertSame(
            42,
            $telemetry
                ->operation('broken-start')
                ->duration($brokenStart)
                ->run(static fn(): int => 42),
        );
        self::assertNull($this->contextStorage->scope());
        self::assertCount(2, $this->exported());
    }

    /** @throws \Throwable */
    #[Test]
    public function instrumentCreationAndRecordingFailuresAreContained(): void
    {
        $meter = $this->createStub(MeterInterface::class);
        $meter->method('createCounter')->willThrowException(new \RuntimeException('counter creation'));
        $meter->method('createHistogram')->willThrowException(new \RuntimeException('histogram creation'));
        $metrics = new SafeMetrics($meter, $this->reporter, new OtelDurationRecorder(), $this->clock);
        $metrics->counter('count')->add(1);
        $metrics->histogram('size')->record(1);
        self::startable($metrics->duration('duration', DurationUnit::Seconds, [0.1, 1]))
            ->start(null)
            ->stop();
        self::assertSame(3, $this->reporter->total());

        $histogram = $this->createStub(HistogramInterface::class);
        $histogram->method('record')->willThrowException(new \RuntimeException('record failed'));
        $histogram->method('isEnabled')->willThrowException(new \RuntimeException('enabled failed'));
        $meter = $this->createStub(MeterInterface::class);
        $meter->method('createHistogram')->willReturn($histogram);
        $metrics = new SafeMetrics($meter, $this->reporter, new OtelDurationRecorder(), $this->clock);
        $metrics->histogram('size')->record(1);
        self::assertFalse($metrics->histogram('size')->isEnabled());
        $measurement = self::startable($metrics->duration('duration', DurationUnit::Seconds, [0.1, 1]))->start(null);
        $measurement->stop();
        $measurement->stop();
        self::assertSame(6, $this->reporter->total());
    }

    /** @throws \Throwable */
    #[Test]
    public function recordingFailuresOfEveryInstrumentKindAreContained(): void
    {
        $failure = new \RuntimeException('record failed');
        $counter = $this->createStub(CounterInterface::class);
        $counter->method('add')->willThrowException($failure);
        $upDown = $this->createStub(UpDownCounterInterface::class);
        $upDown->method('add')->willThrowException($failure);
        // @mago-expect analysis:experimental-usage — the synchronous Gauge is stable in the metrics spec
        $gauge = $this->createStub(GaugeInterface::class);
        $gauge->method('record')->willThrowException($failure);
        $meter = $this->createStub(MeterInterface::class);
        $meter->method('createCounter')->willReturn($counter);
        $meter->method('createUpDownCounter')->willReturn($upDown);
        $meter->method('createGauge')->willReturn($gauge);
        $meter->method('createObservableGauge')->willThrowException(new \RuntimeException('observable creation'));
        $metrics = new SafeMetrics($meter, $this->reporter, new OtelDurationRecorder(), $this->clock);

        $metrics->counter('count')->add(1);
        $metrics->upDownCounter('in.flight')->add(-1);
        $metrics->gauge('depth')->record(3);
        $metrics->observableGauge('memory', static fn(ObserverInterface $_observer): null => null)->detach();

        self::assertSame(4, $this->reporter->total());
    }

    /** @throws \Throwable */
    #[Test]
    public function aFailingObservationIsReportedAndTheCollectionGoesOn(): void
    {
        $metrics = $this->telemetry()->metrics();
        $broken = $metrics->observableCounter(
            'app.broken',
            /** @throws \RuntimeException */ static function (ObserverInterface $_observer): never {
                throw new \RuntimeException('observer crashed');
            },
        );
        $working = $metrics->observableCounter(
            'app.working',
            static fn(ObserverInterface $observer): null => $observer->observe(7) ?? null,
        );

        try {
            $this->meters->forceFlush();
            $names = \array_map(
                static fn(Metric $metric): string => $metric->name,
                \array_filter(
                    $this->metricExporter->collect(true),
                    static fn(Metric $metric): bool => MetricPoints::of($metric) !== [],
                ),
            );
        } finally {
            $broken->detach();
            $working->detach();
        }

        self::assertSame(['app.working'], \array_values($names));
        self::assertStringContainsString('Observation failed at "app.broken"', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function brokenClockDoesNotPreventExecution(): void
    {
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willThrowException(new \RuntimeException('clock failed'));
        $metrics = new SafeMetrics(
            $this->meters->getMeter('test'),
            $this->reporter,
            new OtelDurationRecorder(),
            $clock,
        );
        $duration = $metrics->duration('duration', DurationUnit::Seconds, [0.1, 1]);
        self::assertSame(
            42,
            $this
                ->telemetry()
                ->operation('work')
                ->duration($duration)
                ->run(static fn(): int => 42),
        );
        self::assertNull($this->contextStorage->scope());
    }

    /** @return iterable<string, array{list<mixed>}> */
    public static function invalidBoundaries(): iterable
    {
        yield 'empty' => [[]];
        yield 'descending' => [[1, 0.1]];
        yield 'duplicate' => [[1, 1]];
        yield 'nan' => [[NAN]];
        yield 'infinite' => [[INF]];
        yield 'negative' => [[-1]];
        yield 'string' => [['1']];
    }

    /** @param list<mixed> $boundaries */
    #[Test]
    #[DataProvider('invalidBoundaries')]
    public function invalidStaticDescriptionsAreRejectedEvenWhenDisabled(array $boundaries): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @var non-empty-list<float|int> $boundaries */
        $this->telemetry(false, false)->metrics()->duration('duration', DurationUnit::Seconds, $boundaries);
    }

    /**
     * `Metrics::duration()` hands back the opaque application handle; these tests are about what
     * the package does with it, which is start it.
     */
    private static function startable(Duration $duration): StartableDuration
    {
        self::assertInstanceOf(StartableDuration::class, $duration);

        return $duration;
    }
}
