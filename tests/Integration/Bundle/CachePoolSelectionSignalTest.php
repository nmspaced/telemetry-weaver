<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as SpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * `cache.pools`/`excluded_pools` select which tagged pools get wrapped, an alias to a selected pool
 * must not be wrapped twice, and the wrapping must both trace under the ambient span and record
 * metrics without disturbing context.
 */
final class CachePoolSelectionSignalTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function selectedCachePoolsExportBothSignalsAndPreserveAmbientContext(): void
    {
        $spanExporter = new SpanExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($spanExporter));
        $metricExporter = new MetricExporter();
        $meterProvider = MeterProvider::builder()->addReader(new ExportingReader($metricExporter))->build();
        try {
            $container = $this->compile([
                'instrumentation' => [
                    'http_server' => ['traces' => false, 'metrics' => false],
                    'cache' => ['pools' => ['*', 'metrics.alias'], 'excluded_pools' => ['none']],
                ],
            ], configure: static function (ContainerBuilder $container): void {
                foreach (['both', 'traces', 'metrics', 'none'] as $id) {
                    $container->register($id, ArrayAdapter::class)->addTag('cache.pool')->setPublic(true);
                }

                $container->setAlias('metrics.alias', 'metrics')->setPublic(true);
                $container
                    ->register(TracerInterface::class, TracerInterface::class)
                    ->setSynthetic(true)
                    ->setPublic(true);
                $container->register(MeterInterface::class, MeterInterface::class)->setSynthetic(true)->setPublic(true);
            });
            $tracer = $tracerProvider->getTracer('test');
            $container->set(TracerInterface::class, $tracer);
            $container->set(MeterInterface::class, $meterProvider->getMeter('test'));
            $parent = $tracer->spanBuilder('parent')->startSpan();
            $scope = $parent->activate();
            try {
                $context = Context::getCurrent();
                foreach (['both', 'traces', 'metrics', 'none'] as $id) {
                    $pool = $container->get($id);
                    self::assertInstanceOf(CacheInterface::class, $pool);
                    self::assertSame($id, $pool->get('key', static fn(): string => $id));
                    self::assertSame($context, Context::getCurrent());
                }

                self::assertSame($container->get('metrics'), $container->get('metrics.alias'));
                self::assertInstanceOf(ArrayAdapter::class, $container->get('none'));
            } finally {
                $scope->detach();
                $parent->end();
            }

            $spanPools = [];
            /** @var ImmutableSpan $span */
            foreach ($spanExporter->getSpans() as $span) {
                if ($span->getName() === 'parent') {
                    continue;
                }

                $spanPools[] = $span->getAttributes()->get('cache.pool.name');
                self::assertSame($parent->getContext()->getSpanId(), $span->getParentSpanId());
            }

            \sort($spanPools);
            self::assertSame(['both', 'metrics', 'traces'], $spanPools);
            $meterProvider->forceFlush();
            $metricPools = [];
            foreach ($metricExporter->collect(true) as $metric) {
                foreach (MetricPoints::of($metric) as $point) {
                    $metricPools[] = $point->attributes->get('cache.pool.name');
                }
            }

            $metricPools = \array_values(\array_unique($metricPools));
            \sort($metricPools);
            self::assertSame(['both', 'metrics', 'traces'], $metricPools);
        } finally {
            $tracerProvider->shutdown();
            $meterProvider->shutdown();
        }
    }
}
