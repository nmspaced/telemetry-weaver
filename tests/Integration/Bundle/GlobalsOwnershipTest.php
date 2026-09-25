<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\OpenTelemetry\GlobalsRegistrar;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SdkDiagnostics;
use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\LoggerHolder;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Globals is process state and its resolution is memoised on first read, so every one of these has
 * to own its process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(TelemetryWeaverBundle::class)]
#[CoversClass(GlobalsRegistrar::class)]
#[CoversClass(SdkDiagnostics::class)]
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

    /** @throws \Throwable */
    #[Test]
    public function bootHandsGlobalsTheContainersResponsePropagator(): void
    {
        $container = $this->boot();

        // @mago-expect analysis:experimental-usage — response propagation is experimental upstream and wired deliberately
        self::assertSame($container->get(ResponsePropagatorInterface::class), Globals::responsePropagator());
    }

    /** @throws \Throwable */
    #[Test]
    public function bootHandsGlobalsTheContainersLoggerProvider(): void
    {
        $container = $this->boot();

        self::assertSame($container->get('open_telemetry.logger_provider'), Globals::loggerProvider());
    }

    /** @throws \Throwable */
    #[Test]
    public function theBundleOverridesAnEarlierInitializer(): void
    {
        Globals::registerInitializer(static fn(Configurator $configurator): Configurator => $configurator->withTracerProvider(
            new NoopTracerProvider(),
        ));

        $container = $this->boot();

        self::assertSame($container->get(TracerProviderInterface::class), Globals::tracerProvider());
    }

    /** @throws \Throwable */
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
    /**
     * @param array<string, mixed> $config
     *
     * @throws \Throwable
     */
    private function boot(array $config = []): ContainerBuilder
    {
        $bundle = new TelemetryWeaverBundle();
        $container = $this->compile($config);
        $bundle->setContainer($container);
        $bundle->boot();

        return $container;
    }

    /** @throws \Throwable */
    #[Test]
    public function bootRoutesTheSdksOwnDiagnosticsIntoTheApplicationsLogger(): void
    {
        self::assertFalse(LoggerHolder::isSet(), 'the SDK starts with no logger of its own');

        $container = $this->boot();

        self::assertSame($container->get('open_telemetry.diagnostics.logger'), LoggerHolder::get());
    }

    /** @throws \Throwable */
    #[Test]
    public function diagnosticsOffSilencesTheSdkRatherThanReleasingIt(): void
    {
        $this->boot(['diagnostics' => ['enabled' => false]]);

        self::assertInstanceOf(NullLogger::class, LoggerHolder::get());
    }
}
