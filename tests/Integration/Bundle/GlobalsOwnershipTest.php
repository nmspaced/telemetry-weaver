<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Globals is process state and its resolution is memoised on first read, so
 * every one of these has to own its process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(TelemetryWeaverBundle::class)]
final class GlobalsOwnershipTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function bootHandsGlobalsTheContainersProviders(): void
    {
        $container = $this->boot();

        self::assertSame($container->get(TracerProviderInterface::class), Globals::tracerProvider());
        self::assertSame($container->get(MeterProviderInterface::class), Globals::meterProvider());
        self::assertSame($container->get(TextMapPropagatorInterface::class), Globals::propagator());
    }

    /**
     * Logs are the third signal of the same pipeline. Left out, a Logs API consumer —
     * an auto-instrumentation's Monolog handler, or application code following the docs —
     * would get the SDK's no-op, or a second provider with its own resource and exporter.
     *
     * @throws \Throwable
     */
    #[Test]
    public function bootHandsGlobalsTheContainersLoggerProvider(): void
    {
        $container = $this->boot();

        self::assertSame($container->get('open_telemetry.logger_provider'), Globals::loggerProvider());
    }

    /**
     * An auto-instrumentation registered before the kernel booted must not win:
     * the bundle is the owner, and it registers last.
     *
     * @throws \Throwable
     */
    #[Test]
    public function theBundleOverridesAnEarlierInitializer(): void
    {
        Globals::registerInitializer(static fn(Configurator $configurator): Configurator => $configurator->withTracerProvider(
            new NoopTracerProvider(),
        ));

        $container = $this->boot();

        self::assertSame($container->get(TracerProviderInterface::class), Globals::tracerProvider());
    }

    /**
     * Booting twice — a worker recycling its kernel — must not leave a second
     * initializer behind resolving to the same providers.
     *
     * @throws \Throwable
     */
    #[Test]
    public function repeatedBootKeepsTheSameProviders(): void
    {
        $bundle = new TelemetryWeaverBundle();
        $container = $this->compile();
        $bundle->setContainer($container);

        $bundle->boot();

        $provider = Globals::tracerProvider();

        $bundle->boot();

        self::assertSame($provider, Globals::tracerProvider());
    }

    /** @throws \Throwable */
    #[Test]
    public function aDisabledBundleLeavesGlobalsAlone(): void
    {
        $bundle = new TelemetryWeaverBundle();
        $container = $this->compile(['enabled' => false]);
        $bundle->setContainer($container);

        $bundle->boot();

        self::assertInstanceOf(NoopTracerProvider::class, Globals::tracerProvider());
    }

    /** @throws \Throwable */
    #[Test]
    public function newContainerReplacesCachedGlobalsAndReleasesTheOldContainer(): void
    {
        $first = $this->boot();
        $provider = Globals::tracerProvider();
        $reference = \WeakReference::create($first);
        $second = $this->boot();
        self::assertNotSame($provider, Globals::tracerProvider());
        self::assertSame($second->get(TracerProviderInterface::class), Globals::tracerProvider());
        unset($first, $provider);
        \gc_collect_cycles();
        self::assertNull($reference->get());
    }

    /** @throws \Throwable */
    private function boot(): ContainerBuilder
    {
        $bundle = new TelemetryWeaverBundle();
        $container = $this->compile();
        $bundle->setContainer($container);
        $bundle->boot();

        return $container;
    }
}
