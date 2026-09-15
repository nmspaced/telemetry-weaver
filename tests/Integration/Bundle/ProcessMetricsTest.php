<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\Runtime\ProcessMetrics;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

final class ProcessMetricsTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theGaugesReportAndRegisteringTwiceDoesNotDoubleThem(): void
    {
        $exporter = new InMemoryExporter();
        $meterProvider = MeterProvider::builder()->addReader(new ExportingReader($exporter))->build();

        try {
            $container = $this->compile(configure: self::syntheticMeter(...));
            $container->set(MeterInterface::class, $meterProvider->getMeter('test'));

            $process = $container->get(ProcessMetrics::class);
            self::assertInstanceOf(ProcessMetrics::class, $process);

            $process->register();
            // A kernel reboot calls boot() again; the callbacks must not attach twice.
            $process->register();

            $meterProvider->forceFlush();
            $points = self::pointsByName($exporter);

            self::assertArrayHasKey(ProcessMetrics::MEMORY_USAGE, $points);
            self::assertArrayHasKey(ProcessMetrics::UPTIME, $points);
            $memoryPoints = $points[ProcessMetrics::MEMORY_USAGE] ?? Assert::fail('no memory usage points');
            $uptimePoints = $points[ProcessMetrics::UPTIME] ?? Assert::fail('no uptime points');
            self::assertCount(1, $memoryPoints);
            $memoryPoint = $memoryPoints[0] ?? Assert::fail('no memory usage data point');
            $uptimePoint = $uptimePoints[0] ?? Assert::fail('no uptime data point');
            self::assertInstanceOf(NumberDataPoint::class, $memoryPoint);
            self::assertInstanceOf(NumberDataPoint::class, $uptimePoint);
            self::assertGreaterThan(0, $memoryPoint->value);
            self::assertGreaterThanOrEqual(0, $uptimePoint->value);
        } finally {
            $meterProvider->shutdown();
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function switchingRuntimeMetricsOffLeavesNothingToCollect(): void
    {
        $exporter = new InMemoryExporter();
        $meterProvider = MeterProvider::builder()->addReader(new ExportingReader($exporter))->build();

        try {
            $container = $this->compile(
                ['instrumentation' => ['runtime' => ['metrics' => false]]],
                configure: self::syntheticMeter(...),
            );
            $container->set(MeterInterface::class, $meterProvider->getMeter('test'));

            self::assertInstanceOf(NoopMeter::class, $container->get('open_telemetry.runtime.meter'));

            $process = $container->get(ProcessMetrics::class);
            self::assertInstanceOf(ProcessMetrics::class, $process);
            $process->register();

            $meterProvider->forceFlush();
            self::assertSame([], self::pointsByName($exporter));
        } finally {
            $meterProvider->shutdown();
        }
    }

    /** @throws \Throwable */
    #[Test]
    public function theGlobalMetricsSwitchAlsoSilencesThem(): void
    {
        $container = $this->compile(['metrics' => ['enabled' => false]]);

        self::assertInstanceOf(NoopMeter::class, $container->get('open_telemetry.runtime.meter'));
    }

    private static function syntheticMeter(ContainerBuilder $container): void
    {
        $container->register(MeterInterface::class, MeterInterface::class)->setSynthetic(true)->setPublic(true);
    }

    /**
     * @return array<string, list<HistogramDataPoint|NumberDataPoint>> metric name => data points
     */
    private static function pointsByName(InMemoryExporter $exporter): array
    {
        $points = [];

        foreach ($exporter->collect(true) as $metric) {
            $points[$metric->name] = MetricPoints::of($metric);
        }

        return $points;
    }

    /**
     * A one-shot command has no memory curve worth reporting, so nothing must attach the
     * gauges to it. The events this listens to are what say "this process serves work
     * repeatedly": an incoming request, and a Messenger worker loop.
     *
     * @throws \Throwable
     */
    #[Test]
    public function theGaugesAttachToServedWorkAndNotToBootOrToAConsoleCommand(): void
    {
        $container = $this->compile(configure: static function (ContainerBuilder $container): void {
            $container->register('messenger.bus.default', \stdClass::class)->addTag('messenger.bus');
        });

        /** @var list<array<string, mixed>> $listenerTags */
        $listenerTags = $container->getDefinition(ProcessMetrics::class)->getTag('kernel.event_listener');
        $events = \array_column($listenerTags, 'event');

        self::assertContains(KernelEvents::REQUEST, $events);
        self::assertContains(WorkerRunningEvent::class, $events);
        self::assertSame([], $container->getDefinition(ProcessMetrics::class)->getTag('kernel.event_subscriber'));

        foreach ($events as $event) {
            self::assertStringNotContainsString('console', (string) $event);
        }
    }

    /**
     * The conventions make an amount of memory an up-down counter and uptime a gauge, and
     * the difference survives export: a non-monotonic sum can be added across workers, a
     * gauge cannot. Neither carries attributes — which worker a sample belongs to is the
     * resource's `service.instance.id`, not a label on two instruments.
     *
     * @throws \Throwable
     */
    #[Test]
    public function memoryIsANonMonotonicSumUptimeIsAGaugeAndNeitherCarriesAttributes(): void
    {
        $exporter = new InMemoryExporter();
        $meterProvider = MeterProvider::builder()->addReader(new ExportingReader($exporter))->build();

        try {
            $container = $this->compile(configure: self::syntheticMeter(...));
            $container->set(MeterInterface::class, $meterProvider->getMeter('test'));

            $process = $container->get(ProcessMetrics::class);
            self::assertInstanceOf(ProcessMetrics::class, $process);
            $process->register();

            $meterProvider->forceFlush();
            /** @var array<string, Metric> $metrics */
            $metrics = [];

            foreach ($exporter->collect(true) as $metric) {
                $metrics[$metric->name] = $metric;
            }

            $memory = $metrics[ProcessMetrics::MEMORY_USAGE] ?? Assert::fail('no memory usage metric recorded');
            self::assertInstanceOf(Sum::class, $memory->data);
            self::assertFalse($memory->data->monotonic);
            self::assertSame('By', $memory->unit);
            $memoryPoint = MetricPoints::first($memory);
            self::assertInstanceOf(NumberDataPoint::class, $memoryPoint);
            self::assertSame([], $memoryPoint->attributes->toArray());

            $uptime = $metrics[ProcessMetrics::UPTIME] ?? Assert::fail('no uptime metric recorded');
            self::assertInstanceOf(Gauge::class, $uptime->data);
            self::assertSame('s', $uptime->unit);
            $uptimePoint = MetricPoints::first($uptime);
            self::assertInstanceOf(NumberDataPoint::class, $uptimePoint);
            self::assertSame([], $uptimePoint->attributes->toArray());
        } finally {
            $meterProvider->shutdown();
        }
    }
}
