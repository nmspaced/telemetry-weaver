<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use PHPUnit\Framework\Assert;

/**
 * Reads recorded metrics out of the Messenger telemetry tests' in-memory metric exporter,
 * asserting they exist and narrowing `Metric::$data` via {@see MetricPoints} rather than
 * letting a missing metric or the wrong data shape surface as a confusing type error.
 *
 * @internal
 */
final class MessengerMetricAssertions
{
    private function __construct() {}

    /** @return list<string> */
    public static function recordedMetricNames(InMemoryExporter $metrics): array
    {
        return \array_map(static fn(Metric $metric): string => $metric->name, \array_values($metrics->collect()));
    }

    /**
     * @param non-empty-string $name
     *
     * @return list<HistogramDataPoint|NumberDataPoint>
     */
    public static function dataPointsOf(InMemoryExporter $metrics, string $name): array
    {
        foreach ($metrics->collect() as $metric) {
            if ($metric->name !== $name) {
                continue;
            }

            return MetricPoints::of($metric);
        }

        return [];
    }

    /** @param non-empty-string $name */
    public static function counter(InMemoryExporter $metrics, string $name): NumberDataPoint
    {
        foreach ($metrics->collect() as $metric) {
            if ($metric->name !== $name) {
                continue;
            }

            $point = MetricPoints::first($metric);
            Assert::assertInstanceOf(NumberDataPoint::class, $point);

            return $point;
        }

        Assert::fail(\sprintf('no "%s" metric was recorded', $name));
    }

    /** @param non-empty-string $name */
    public static function histogram(InMemoryExporter $metrics, string $name): HistogramDataPoint
    {
        foreach ($metrics->collect() as $metric) {
            if ($metric->name === $name) {
                return MetricPoints::firstHistogram($metric);
            }
        }

        Assert::fail(\sprintf('no "%s" metric was recorded', $name));
    }
}
