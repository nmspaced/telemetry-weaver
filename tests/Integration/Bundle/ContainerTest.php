<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Lifecycle\TelemetryFlushSubscriber;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MeterProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SignalFlusher;
use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(TelemetryWeaverBundle::class)]
#[CoversClass(MeterProviderFactory::class)]
final class ContainerTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theGraphCompilesWithoutACycle(): void
    {
        $container = $this->compile();

        self::assertSame(Context::storage(), $container->get(ContextStorageInterface::class));
        self::assertInstanceOf(SpanOpener::class, $container->get(SpanOpener::class));
        self::assertInstanceOf(MeterInterface::class, $container->get(MeterInterface::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function everyProviderTheContainerHandsOutIsRegisteredForFlushing(): void
    {
        $container = $this->compile();

        $container->get(TracerProviderInterface::class);
        $container->get(MeterProviderInterface::class);
        $container->get('open_telemetry.logger_provider');

        $registry = $container->get(ProviderRegistry::class);
        self::assertInstanceOf(ProviderRegistry::class, $registry);
        self::assertSame(
            ['traces', 'logs', 'metrics'],
            \array_map(
                static fn(SignalFlusher $signal): string => $signal->signal(),
                \iterator_to_array($registry->ordered(), false),
            ),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function theMeterIsBuiltFromTheAssembledProvider(): void
    {
        $container = $this->compile();

        self::assertInstanceOf(MeterProvider::class, $container->get(MeterProviderInterface::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function disablingThePackageWiresNothing(): void
    {
        $container = $this->compile(['enabled' => false]);

        self::assertFalse($container->has(MeterProviderInterface::class));
        self::assertFalse($container->has(MeterInterface::class));
        self::assertFalse($container->has(SpanOpener::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function tracingDoesNotDependOnTheMetricsStack(): void
    {
        $container = $this->compile();

        self::assertInstanceOf(
            InstrumentationFailureReporter::class,
            $container->get(InstrumentationFailureReporter::class),
        );
        self::assertSame(
            [TracerInterface::class, ContextStorageInterface::class, InstrumentationFailureReporter::class],
            \array_map(strval(...), $container->getDefinition(SpanOpener::class)->getArguments()),
        );
        self::assertFalse($container->has('open_telemetry.diagnostics'));
        self::assertFalse($container->has('open_telemetry.diagnostics.anomalies'));
        self::assertFalse($container->has('open_telemetry.context_guard'));
    }

    /** @throws \Throwable */
    #[Test]
    public function anUnavailableExporterFailsClearly(): void
    {
        $_SERVER['OTEL_METRICS_EXPORTER'] = 'nonexistent';

        $this->expectException(\RuntimeException::class);
        $this->compile()->get(MeterProviderInterface::class);
    }

    /** @throws \Throwable */
    #[Test]
    public function theFlushSubscriberIsRegisteredEvenWithoutHttpTracing(): void
    {
        $container = $this->compile(['instrumentation' => ['http_server' => ['traces' => false]]]);

        self::assertInstanceOf(TelemetryFlushSubscriber::class, $container->get(TelemetryFlushSubscriber::class));
        self::assertInstanceOf(ProviderRegistry::class, $container->get(ProviderRegistry::class));
    }
}
