<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Lifecycle\TelemetryFlushSubscriber;
use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RequestRuntimeTest extends ContainerTestCase
{
    /** @return iterable<string, array{int, bool, bool, bool}> */
    public static function modes(): iterable
    {
        yield 'FPM' => [0, true, true, false];
        yield 'shared kernel' => [1, true, false, true];
        yield 'reset kernel' => [2, true, true, true];
        yield 'console' => [0, false, false, true];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('modes')]
    public function runtimeParametersSelectLifetimeAndResource(
        int $worker,
        bool $web,
        bool $request,
        bool $identity,
    ): void {
        $container = $this->compile(configure: static function (ContainerBuilder $container) use ($worker, $web): void {
            $container->setParameter('kernel.runtime_mode.worker', $worker);
            $container->setParameter('kernel.runtime_mode.web', $web);
        });
        $profile = $container->get(SymfonyRuntimeProfile::class);
        self::assertInstanceOf(SymfonyRuntimeProfile::class, $profile);
        self::assertSame($request, $profile->finishesAfterRequest());
        $resource = $container->get(ResourceInfo::class);
        self::assertInstanceOf(ResourceInfo::class, $resource);
        self::assertSame($identity, $resource->getAttributes()->has('service.instance.id'));
        self::assertSame($request, $container->get(MeterProviderInterface::class) instanceof NoopMeterProvider);
        $container->get(TracerProviderInterface::class);
        $subscriber = $container->get(TelemetryFlushSubscriber::class);
        self::assertInstanceOf(TelemetryFlushSubscriber::class, $subscriber);
        $subscriber->onTerminate();
        $gate = $container->get(ExportGate::class);
        self::assertInstanceOf(ExportGate::class, $gate);
        self::assertSame($request, $gate->isClosed());
    }

    /** @throws \Throwable */
    #[Test]
    public function unusedPipelineDoesNotCreateProvidersAtTermination(): void
    {
        $container = $this->compile();
        $registry = $container->get(ProviderRegistry::class);
        self::assertInstanceOf(ProviderRegistry::class, $registry);
        self::assertSame([], \iterator_to_array($registry->ordered()));
        $flusher = $container->get(TelemetryFlusher::class);
        self::assertInstanceOf(TelemetryFlusher::class, $flusher);
        $flusher->atShutdown();
        self::assertSame([], \iterator_to_array($registry->ordered()));
        self::assertFalse($container->initialized(TracerProviderInterface::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function deltaOptInCreatesARealRequestMeterProvider(): void
    {
        $container = $this->compile([
            'runtime' => ['request_metrics' => ['mode' => 'delta']],
            'sdk' => ['resource_attributes' => ['service.instance.id' => 'explicit']],
        ], configure: static function (ContainerBuilder $container): void {
            $container->setParameter('kernel.runtime_mode.worker', 0);
        });
        self::assertNotInstanceOf(NoopMeterProvider::class, $container->get(MeterProviderInterface::class));
        $resource = $container->get(ResourceInfo::class);
        self::assertInstanceOf(ResourceInfo::class, $resource);
        self::assertSame('explicit', $resource->getAttributes()->get('service.instance.id'));
        self::assertTrue($container->initialized(MetricExporterFactory::class));
    }

    /**
     * FRANKENPHP_RESET_KERNEL builds a container per request like FPM does, so it takes the same
     * opt-in, while its resource keeps the worker thread's detected id.
     *
     * @throws \Throwable
     */
    #[Test]
    public function deltaOptInExportsFromAResetKernelWorker(): void
    {
        $container = $this->compile([
            'runtime' => ['request_metrics' => ['mode' => 'delta']],
        ], configure: static function (ContainerBuilder $container): void {
            $container->setParameter('kernel.runtime_mode.worker', 2);
        });

        self::assertNotInstanceOf(NoopMeterProvider::class, $container->get(MeterProviderInterface::class));
        $resource = $container->get(ResourceInfo::class);
        self::assertInstanceOf(ResourceInfo::class, $resource);
        self::assertTrue($resource->getAttributes()->has('service.instance.id'));
    }

    /** @throws \Throwable */
    #[Test]
    public function deltaOptInUnderFpmDerivesTheWriterIdentity(): void
    {
        $container = $this->compile([
            'runtime' => ['request_metrics' => ['mode' => 'delta']],
        ], configure: static function (ContainerBuilder $container): void {
            $container->setParameter('kernel.runtime_mode.worker', 0);
        });

        self::assertNotInstanceOf(NoopMeterProvider::class, $container->get(MeterProviderInterface::class));
        $resource = $container->get(ResourceInfo::class);
        self::assertInstanceOf(ResourceInfo::class, $resource);
        self::assertIsString($resource->getAttributes()->get('service.instance.id'));
    }
}
