<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientMetricsExporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricTemporality;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\SDK\Metrics\Data\DataInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What reaches the exporter when the bundle's selector is in place — collected by a real reader.
 */
#[CoversClass(MetricTemporality::class)]
final class DeltaPreferenceExportTest extends TestCase
{
    #[Test]
    public function underADeltaPreferenceStateStaysAbsoluteAndNoInstrumentDisappears(): void
    {
        $exporter = new InMemoryExporter();
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $provider = MeterProvider::builder()
            ->addReader(new ExportingReader(
                new ResilientMetricsExporter($exporter, $reporter, Flushers::openGate(), MetricTemporality::delta()),
            ))
            ->build();
        $meter = $provider->getMeter('test');
        $observations = new \ArrayObject(['heap' => 100, 'observed' => 10]);
        $requests = $meter->createCounter('requests');
        $active = $meter->createUpDownCounter('active');
        $duration = $meter->createHistogram('duration');
        $meter->createGauge('temperature')->record(21);
        $heap = $meter->createObservableUpDownCounter(
            'heap',
            null,
            null,
            [],
            static function (ObserverInterface $observer) use ($observations): void {
                $observer->observe($observations['heap']);
            },
        );
        $observed = $meter->createObservableCounter(
            'observed',
            null,
            null,
            [],
            static function (ObserverInterface $observer) use ($observations): void {
                $observer->observe($observations['observed']);
            },
        );
        $uptime = $meter->createObservableGauge(
            'uptime',
            null,
            null,
            [],
            static function (ObserverInterface $observer): void {
                $observer->observe(5);
            },
        );

        $requests->add(3);
        $active->add(5);
        $duration->record(0.1);
        $provider->forceFlush();
        $first = self::byName($exporter->collect(true));

        $requests->add(2);
        $active->add(2);
        $duration->record(0.2);
        $observations['heap'] = 120;
        $observations['observed'] = 15;
        $provider->forceFlush();
        $second = self::byName($exporter->collect(true));

        $all = ['requests', 'active', 'duration', 'temperature', 'heap', 'observed', 'uptime'];
        self::assertEqualsCanonicalizing($all, \array_keys($first), 'an instrument was dropped at the reader');
        self::assertEqualsCanonicalizing($all, \array_keys($second));

        self::assertSum($second, 'requests', Temporality::DELTA, 2);
        self::assertSum($second, 'observed', Temporality::DELTA, 5);
        self::assertSum($second, 'active', Temporality::CUMULATIVE, 7);
        self::assertSum($second, 'heap', Temporality::CUMULATIVE, 120);
        $histogram = self::data($second, 'duration');
        self::assertInstanceOf(Histogram::class, $histogram);
        self::assertSame(Temporality::DELTA, $histogram->temporality);
        $counts = [];
        foreach ($histogram->dataPoints as $point) {
            self::assertInstanceOf(HistogramDataPoint::class, $point);
            $counts[] = $point->count;
        }

        self::assertSame([1], $counts);
        self::assertInstanceOf(Gauge::class, self::data($second, 'uptime'));
        $temperature = self::data($second, 'temperature');
        self::assertInstanceOf(Gauge::class, $temperature);
        self::assertSame([21], self::values($temperature->dataPoints));
        self::assertSame(0, $reporter->total());

        $provider->shutdown();
        unset($heap, $observed, $uptime);
    }

    /**
     * @param iterable<Metric> $batch
     * @return array<string, Metric>
     */
    private static function byName(iterable $batch): array
    {
        $metrics = [];
        foreach ($batch as $metric) {
            $metrics[$metric->name] = $metric;
        }

        return $metrics;
    }

    /** @param array<string, Metric> $metrics */
    private static function data(array $metrics, string $name): DataInterface
    {
        return ($metrics[$name] ?? Assert::fail('missing ' . $name))->data;
    }

    /**
     * @param iterable<mixed> $points
     * @return list<float|int>
     */
    private static function values(iterable $points): array
    {
        $values = [];
        foreach ($points as $point) {
            self::assertInstanceOf(NumberDataPoint::class, $point);
            $values[] = $point->value;
        }

        return $values;
    }

    /** @param array<string, Metric> $metrics */
    private static function assertSum(array $metrics, string $name, string $temporality, int $value): void
    {
        $sum = self::data($metrics, $name);
        self::assertInstanceOf(Sum::class, $sum);
        self::assertSame($temporality, $sum->temporality, $name);
        self::assertSame([$value], self::values($sum->dataPoints), $name);
    }
}
