<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use PHPUnit\Framework\Assert;

/**
 * `Metric::$data` is `DataInterface`, which does not declare `dataPoints` — every concrete
 * data shape (`Histogram`, `Sum`, `Gauge`) does, under a different point type. Centralizing the
 * narrowing here keeps it out of every test that reads a recorded metric.
 *
 * @internal
 */
final class MetricPoints
{
    private function __construct() {}

    /**
     * @return list<HistogramDataPoint|NumberDataPoint>
     */
    public static function of(Metric $metric): array
    {
        $data = $metric->data;
        if ($data instanceof Histogram) {
            return \array_values(\iterator_to_array($data->dataPoints));
        }

        if ($data instanceof Sum) {
            return \array_values(\iterator_to_array($data->dataPoints));
        }

        if ($data instanceof Gauge) {
            return \array_values(\iterator_to_array($data->dataPoints));
        }

        Assert::fail('Unsupported metric data type: ' . $data::class);
    }

    public static function first(Metric $metric): HistogramDataPoint|NumberDataPoint
    {
        $points = self::of($metric);

        return $points[0] ?? Assert::fail('Metric "' . $metric->name . '" has no data points.');
    }

    public static function firstHistogram(Metric $metric): HistogramDataPoint
    {
        $point = self::first($metric);
        Assert::assertInstanceOf(HistogramDataPoint::class, $point);

        return $point;
    }
}
