<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpServerMetrics;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpServerMetricsSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\RequestMeasurementRegistry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\RequestPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RequestRouteTemplateResolver;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelTraceCorrelationSource;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\StaticRouteTemplateProvider;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;

/**
 * @internal
 */
abstract class HttpMetricsTestCase extends HttpTelemetryTestCase
{
    protected FrozenClock $clock;

    protected MeterProviderInterface $metrics;

    protected RequestMeasurementRegistry $measurements;

    protected InMemoryExporter $metricExporter;

    protected HttpServerMetricsSubscriber $subscriber;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock();
        $this->metricExporter = new InMemoryExporter(temporality: Temporality::CUMULATIVE);
        $this->metrics = MeterProvider::builder()->addReader(new ExportingReader($this->metricExporter))->build();
        $this->subscribe($this->metrics->getMeter('test'));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->measurements->reset();
        $this->scopes->reset();
        $this->metrics->shutdown();
        parent::tearDown();
    }

    protected function subscribe(MeterInterface $meter): void
    {
        if (($this->subscriber ?? null) !== null) {
            $this->dispatcher->removeSubscriber($this->subscriber);
        }

        $this->measurements = new RequestMeasurementRegistry(
            new HttpServerMetrics(
                new SafeMetrics($meter, $this->reporter, new OtelDurationRecorder(), $this->clock),
                new OtelTraceCorrelationSource($this->contextStorage),
                $this->reporter,
            ),
            $this->reporter,
        );
        $this->subscriber = new HttpServerMetricsSubscriber($this->measurements, new RequestPolicy(
            ['/health'],
            new RequestRouteTemplateResolver(new StaticRouteTemplateProvider(['orders' => '/orders/{id}'])),
        ));
        $this->dispatcher->addSubscriber($this->subscriber);
    }

    protected function metric(string $name): Metric
    {
        $this->metrics->forceFlush();
        foreach ($this->metricExporter->collect(true) as $metric) {
            if ($metric->name === $name) {
                return $metric;
            }
        }

        self::fail('Missing metric: ' . $name);
    }
}
