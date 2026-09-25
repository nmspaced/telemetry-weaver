<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Instrumentation\Cache\CacheTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableCachePool;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A cache pool over real span and metric pipelines, with a clock the test drives.
 *
 * @internal
 */
abstract class CacheTelemetryTestCase extends TelemetryTestCase
{
    protected FrozenClock $clock;

    protected CacheTelemetry $cacheTelemetry;

    private InMemoryExporter $metrics;

    private MeterProviderInterface $meters;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new InMemoryExporter();
        $this->meters = MeterProvider::builder()->addReader(new ExportingReader($this->metrics))->build();
        $this->clock = new FrozenClock();
        $this->cacheTelemetry = new CacheTelemetry(TelemetryFactory::create(
            $this->meters->getMeter('test'),
            $this->spans,
            $this->reporter,
            $this->clock,
        ));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->meters->shutdown();
        parent::tearDown();
    }

    protected function pool(?AdapterInterface $delegate = null): TraceableCachePool
    {
        return new TraceableCachePool($delegate ?? new ArrayAdapter(), $this->cacheTelemetry, 'cache.test');
    }

    protected function metric(string $name): Metric
    {
        $this->meters->forceFlush();

        foreach ($this->metrics->collect(true) as $metric) {
            if ($metric->name === $name) {
                return $metric;
            }
        }

        self::fail('Missing metric: ' . $name);
    }

    /**
     * What was recorded since the last read, from one snapshot: collecting clears the
     * exporter, so reading the two metrics separately would lose the second.
     *
     * @return array{durations: int, lookups: int} how many durations were recorded, and
     *                                             how many lookups were counted
     */
    protected function recorded(): array
    {
        $this->meters->forceFlush();
        $recorded = ['durations' => 0, 'lookups' => 0];

        foreach ($this->metrics->collect(true) as $metric) {
            foreach (MetricPoints::of($metric) as $point) {
                if ($metric->name === 'cache.operation.duration' && $point instanceof HistogramDataPoint) {
                    $recorded['durations'] += $point->count;
                }

                if ($metric->name === 'cache.lookup.count' && $point instanceof NumberDataPoint) {
                    $recorded['lookups'] += (int) $point->value;
                }
            }
        }

        return $recorded;
    }
}
