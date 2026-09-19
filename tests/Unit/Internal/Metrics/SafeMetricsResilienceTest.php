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
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use const INF;
use const NAN;

/**
 * `SafeMetrics` never lets instrument creation, recording, or a broken clock reach the
 * caller, and a disabled duration never reads the clock at all. Also covers the two
 * signal switches (traces/metrics independently on or off) and the runtime rejection of
 * invalid static duration boundaries. Error/`fail()` outcomes live in
 * {@see PublicTelemetryFailureTest}.
 */
final class SafeMetricsResilienceTest extends PublicTelemetryTestCase
{
    /**
     * @throws \Throwable
     */
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

    /**
     * @throws \Throwable
     */
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

    /**
     * @throws \Throwable
     */
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

    /**
     * @throws \Throwable
     */
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

    /**
     * @throws \Throwable
     */
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
        // Deliberately wrong shapes and values: proving the runtime check rejects what the
        // static type alone would not (an empty list, descending or duplicate bounds, NAN/INF).
        /** @var non-empty-list<float|int> $boundaries */
        $this->telemetry(false, false)->metrics()->duration('duration', DurationUnit::Seconds, $boundaries);
    }

    /**
     * `Metrics::duration()` hands back the opaque application handle; these tests are about
     * what the package does with it, which is start it.
     */
    private static function startable(Duration $duration): StartableDuration
    {
        self::assertInstanceOf(StartableDuration::class, $duration);

        return $duration;
    }
}
