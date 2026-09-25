<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * Builds a minimal valid Metric: exporter test batches are typed.
 */
final readonly class Metrics
{
    /** @param non-empty-string $name */
    public static function metric(string $name): Metric
    {
        return self::withPoints($name, [new NumberDataPoint(1, Attributes::create([]), 0, 1)]);
    }

    /**
     * A cumulative metric created but never recorded.
     *
     * @param non-empty-string $name
     */
    public static function empty(string $name): Metric
    {
        return self::withPoints($name, []);
    }

    /**
     * @param non-empty-string $name
     * @param list<NumberDataPoint> $points
     */
    private static function withPoints(string $name, array $points): Metric
    {
        return new Metric(
            new InstrumentationScope('test', null, null, Attributes::create([])),
            ResourceInfo::emptyResource(),
            $name,
            null,
            null,
            new Gauge($points),
        );
    }

    /**
     * @param non-empty-list<non-empty-string> $names
     *
     * @return non-empty-list<Metric>
     */
    public static function batch(array $names): array
    {
        $batch = [];

        foreach ($names as $name) {
            $batch[] = self::metric($name);
        }

        return $batch;
    }

    /**
     * @param list<Metric> $batch
     *
     * @return list<string>
     */
    public static function names(array $batch): array
    {
        return \array_map(static fn(Metric $metric): string => $metric->name, $batch);
    }
}
