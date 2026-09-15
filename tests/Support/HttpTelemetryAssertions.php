<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use PHPUnit\Framework\Assert;

/**
 * Reads recorded metric points and span attributes out of an {@see InMemoryTelemetry}, asserting
 * they exist rather than letting a missing one surface as a confusing type error further down.
 *
 * @internal
 */
final class HttpTelemetryAssertions
{
    private function __construct() {}

    /** @return list<HistogramDataPoint|NumberDataPoint> */
    public static function pointsNamed(InMemoryTelemetry $telemetry, string $name): array
    {
        return self::pointsNamedIn($telemetry->measurements(), $name);
    }

    /**
     * @param list<Metric> $metrics
     *
     * @return list<HistogramDataPoint|NumberDataPoint>
     */
    public static function pointsNamedIn(array $metrics, string $name): array
    {
        foreach ($metrics as $metric) {
            if ($metric->name === $name) {
                return MetricPoints::of($metric);
            }
        }

        return [];
    }

    /** @param list<HistogramDataPoint|NumberDataPoint> $points */
    public static function point(array $points, int $index = 0): HistogramDataPoint|NumberDataPoint
    {
        return $points[$index] ?? Assert::fail('no data point at index ' . $index);
    }

    public static function firstHistogramPoint(InMemoryTelemetry $telemetry, int $index = 0): HistogramDataPoint
    {
        $measurement = $telemetry->measurements()[$index] ?? Assert::fail('no measurement at index ' . $index);
        $point = MetricPoints::first($measurement);
        Assert::assertInstanceOf(HistogramDataPoint::class, $point);

        return $point;
    }

    public static function histogramPointNamed(
        InMemoryTelemetry $telemetry,
        string $name,
        int $index = 0,
    ): HistogramDataPoint {
        return self::histogramPointNamedIn($telemetry->measurements(), $name, $index);
    }

    /** @param list<Metric> $metrics */
    public static function histogramPointNamedIn(array $metrics, string $name, int $index = 0): HistogramDataPoint
    {
        $point = self::point(self::pointsNamedIn($metrics, $name), $index);
        Assert::assertInstanceOf(HistogramDataPoint::class, $point);

        return $point;
    }

    /** @param array<array-key, mixed> $attributes */
    public static function attribute(array $attributes, string $key): mixed
    {
        if (!\array_key_exists($key, $attributes)) {
            Assert::fail('missing attribute: ' . $key);
        }

        return $attributes[$key];
    }
}
