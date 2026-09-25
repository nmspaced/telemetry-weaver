<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpServerMetricsSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\RequestMeasurementRegistry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\HttpServerTracingSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\HttpKernel;

#[CoversClass(TelemetryWeaverBundle::class)]
final class HttpContainerTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theHttpSubscriberIsInstantiable(): void
    {
        $container = $this->compile();

        self::assertInstanceOf(HttpServerTracingSubscriber::class, $container->get(HttpServerTracingSubscriber::class));
        self::assertInstanceOf(RequestTraceRegistry::class, $container->get(RequestTraceRegistry::class));
        self::assertInstanceOf(HttpServerMetricsSubscriber::class, $container->get(HttpServerMetricsSubscriber::class));
        self::assertInstanceOf(RequestMeasurementRegistry::class, $container->get(RequestMeasurementRegistry::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function theKernelIsNotDecorated(): void
    {
        $container = $this->compile();

        self::assertInstanceOf(HttpKernel::class, $container->get('http_kernel'));
    }

    /** @throws \Throwable */
    #[Test]
    public function metricsWorkWithoutTracing(): void
    {
        $container = $this->compile([
            'traces' => ['enabled' => false],
            'instrumentation' => ['http_server' => ['metrics' => ['excluded_paths' => ['/metrics-health']]]],
        ]);
        self::assertInstanceOf(HttpServerTracingSubscriber::class, $container->get(HttpServerTracingSubscriber::class));
        self::assertInstanceOf(HttpServerMetricsSubscriber::class, $container->get(HttpServerMetricsSubscriber::class));
        self::assertSame(
            ['/metrics-health'],
            $container->getParameter('open_telemetry.instrumentation.http_server.metrics.excluded_paths'),
        );
        self::assertTrue($container->getDefinition(RequestMeasurementRegistry::class)->hasTag('kernel.reset'));
    }

    /** @throws \Throwable */
    #[Test]
    public function disablingMetricsKeepsBothLifecycleSubscribers(): void
    {
        $container = $this->compile(['metrics' => ['enabled' => false]]);
        self::assertInstanceOf(HttpServerTracingSubscriber::class, $container->get(HttpServerTracingSubscriber::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function httpMetricsCanBeNoopSeparately(): void
    {
        $container = $this->compile(['instrumentation' => ['http_server' => ['metrics' => false]]]);
        self::assertInstanceOf(HttpServerMetricsSubscriber::class, $container->get(HttpServerMetricsSubscriber::class));
        self::assertInstanceOf(NoopMeter::class, $container->get('open_telemetry.http_server.meter'));
        self::assertTrue($container->has(HttpServerTracingSubscriber::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function globalDisableRemovesBothSubscribers(): void
    {
        $container = $this->compile(['enabled' => false]);
        self::assertFalse($container->has(HttpServerMetricsSubscriber::class));
        self::assertFalse($container->has(HttpServerTracingSubscriber::class));
    }
}
