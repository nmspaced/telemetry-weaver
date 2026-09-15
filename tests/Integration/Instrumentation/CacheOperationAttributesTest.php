<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Cache\CacheTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Cache\TraceableCachePool;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryFactory;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryTestCase;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CacheOperationAttributesTest extends TelemetryTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function operationDetailsStayOnSpansAndDoNotSplitMetrics(): void
    {
        $metrics = new InMemoryExporter();
        $meters = MeterProvider::builder()->addReader(new ExportingReader($metrics))->build();
        $telemetry = new CacheTelemetry(TelemetryFactory::create(
            $meters->getMeter('test'),
            $this->spans,
            $this->reporter,
        ));
        $delegate = new ArrayAdapter();
        $pool = new TraceableCachePool($delegate, $telemetry, 'cache.test');
        $pool->save($delegate->getItem('first')->set('value'));
        $pool->save($delegate->getItem('second')->set('value'));
        \iterator_to_array($pool->getItems(['first', 'second']));
        $pool->deleteItems(['first', 'second']);
        $pool->clear('prefix');

        self::assertSame('first', $this->exportedSpan()->getAttributes()->get('cache.key'));
        self::assertSame('second', $this->exportedSpan(1)->getAttributes()->get('cache.key'));
        self::assertSame(['first', 'second'], $this->exportedSpan(2)->getAttributes()->get('cache.keys'));
        self::assertSame(['first', 'second'], $this->exportedSpan(3)->getAttributes()->get('cache.keys'));
        self::assertSame('prefix', $this->exportedSpan(4)->getAttributes()->get('cache.prefix'));

        $meters->forceFlush();
        $saveSamples = 0;
        foreach ($metrics->collect(true) as $metric) {
            self::assertTrue($metric->data instanceof Histogram || $metric->data instanceof Sum);
            foreach ($metric->data->dataPoints as $point) {
                $attributes = $point->attributes->toArray();
                foreach (['cache.key', 'cache.keys', 'cache.tags', 'cache.prefix'] as $key) {
                    self::assertArrayNotHasKey($key, $attributes);
                }

                if ($point instanceof HistogramDataPoint && ($attributes['cache.operation.name'] ?? null) === 'save') {
                    self::assertSame(2, $point->count);
                    ++$saveSamples;
                }
            }
        }

        $meters->shutdown();
        self::assertSame(1, $saveSamples);
    }
}
