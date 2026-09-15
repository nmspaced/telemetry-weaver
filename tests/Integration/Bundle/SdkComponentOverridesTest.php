<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\SdkComponentsCompilerPass;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientLogsExporter;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientMetricsExporter;
use Nmspaced\TelemetryWeaver\Internal\Exporter\ResilientTracesExporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\Tests\Fake\CountingSpanExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingTransportFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\ShutdownRecordingTracerProvider;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * An application's own link of the export pipeline, configured by service id. What each test
 * asks is what the bundle still guarantees around the replaced link.
 */
#[CoversClass(SdkComponentsCompilerPass::class)]
final class SdkComponentOverridesTest extends ContainerTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER[Variables::OTEL_EXPORTER_OTLP_PROTOCOL]);
    }

    /**
     * The bundle's transport overrides the SDK's retry default; seeing the SDK's 3 in place of the
     * configured 2 is what proves nothing of the bundle's transport layer sits in the path. The
     * setting itself is accepted: gRPC signals keep the bundle's transport, and it still applies
     * there. Which family keeps what is `OtlpTransportsTest`'s question.
     *
     * @throws \Throwable
     */
    #[Test]
    public function anApplicationsTransportFactoryReachesTheOtlpExportersOfItsProtocolFamily(): void
    {
        $_SERVER[Variables::OTEL_TRACES_EXPORTER] = 'otlp';
        $_SERVER[Variables::OTEL_EXPORTER_OTLP_PROTOCOL] = 'http/json';

        $container = $this->compile([
            'sdk' => [
                'otlp' => ['transport_factories' => ['http' => 'app.http']],
                'export' => ['max_retries' => 2],
            ],
        ], configure: static function (ContainerBuilder $container): void {
            $container->register('app.http', RecordingTransportFactory::class);
        });

        self::assertInstanceOf(ResilientTracesExporter::class, $container->get(SpanExporterInterface::class));
        $http = $container->get('app.http');
        self::assertInstanceOf(RecordingTransportFactory::class, $http);
        self::assertSame(3, $http->argument('maxRetries'));
    }

    /** @throws \Throwable */
    #[Test]
    public function anApplicationsExporterIsStillWrappedAndStillReceivesTheSpans(): void
    {
        $container = $this->compile([
            'sdk' => [
                'traces' => ['exporter' => 'app.spans'],
                'metrics' => ['exporter' => 'app.metrics'],
                'logs' => ['exporter' => 'app.logs'],
            ],
        ], configure: static function (ContainerBuilder $container): void {
            $container->register('app.spans', CountingSpanExporter::class);
            $container->register('app.metrics', InMemoryMetricExporter::class);
            $container->register('app.logs', InMemoryLogExporter::class);
        });

        self::assertInstanceOf(ResilientTracesExporter::class, $container->get(SpanExporterInterface::class));
        self::assertInstanceOf(ResilientMetricsExporter::class, $container->get(MetricExporterInterface::class));
        self::assertInstanceOf(ResilientLogsExporter::class, $container->get(LogRecordExporterInterface::class));

        $tracers = $container->get(TracerProviderInterface::class);
        self::assertInstanceOf(TracerProviderInterface::class, $tracers);
        $tracers->getTracer('probe')->spanBuilder('work')->startSpan()->end();
        $tracers->forceFlush();

        $spans = $container->get('app.spans');
        self::assertInstanceOf(CountingSpanExporter::class, $spans);
        self::assertGreaterThan(0, $spans->exported);
    }

    /** @throws \Throwable */
    #[Test]
    public function anApplicationsProviderIsHandedOutAndFinishedByThePipeline(): void
    {
        $container = $this->compile([
            'sdk' => ['traces' => ['provider' => 'app.tracers']],
        ], configure: static function (ContainerBuilder $container): void {
            $container->register('app.tracers', ShutdownRecordingTracerProvider::class);
        });

        $provider = $container->get('app.tracers');
        self::assertInstanceOf(ShutdownRecordingTracerProvider::class, $provider);
        self::assertSame($provider, $container->get(TracerProviderInterface::class));

        $flusher = $container->get(TelemetryFlusher::class);
        self::assertInstanceOf(TelemetryFlusher::class, $flusher);
        $flusher->atShutdown();

        self::assertSame(1, $provider->shutdowns);
    }

    #[Test]
    public function anIdThatNamesNoServiceFailsTheCompile(): void
    {
        $this->assertCompileFails(
            InvalidArgumentException::class,
            'open_telemetry.sdk.traces.exporter names "app.missing", which is not a service id',
            ['sdk' => ['traces' => ['exporter' => 'app.missing']]],
        );
    }

    #[Test]
    public function aServiceOfTheWrongKindFailsTheCompile(): void
    {
        $this->assertCompileFails(
            InvalidArgumentException::class,
            'does not implement ' . TracerProviderInterface::class,
            ['sdk' => ['traces' => ['provider' => 'app.wrong']]],
            static function (ContainerBuilder $container): void {
                $container->register('app.wrong', \ArrayObject::class);
            },
        );
    }

    #[Test]
    public function aProviderAndAnExporterForOneSignalAreRejected(): void
    {
        $this->assertCompileFails(
            InvalidArgumentException::class,
            'sdk.metrics.exporter is ignored when sdk.metrics.provider is set',
            ['sdk' => ['metrics' => ['provider' => 'app.meters', 'exporter' => 'app.metrics']]],
        );
    }

    #[Test]
    public function transportSettingsNextToApplicationFactoriesForEveryProtocolAreRejected(): void
    {
        $this->assertCompileFails(
            InvalidArgumentException::class,
            'ignored when sdk.otlp.transport_factories names a factory for every protocol',
            [
                'sdk' => [
                    'otlp' => ['transport_factories' => ['grpc' => 'app.grpc', 'http' => 'app.http']],
                    'export' => ['max_retries' => 2],
                ],
            ],
            static function (ContainerBuilder $container): void {
                $container->register('app.grpc', RecordingTransportFactory::class);
                $container->register('app.http', RecordingTransportFactory::class);
            },
        );
    }

    #[Test]
    public function aServiceReferenceWithALeadingAtIsNotAServiceId(): void
    {
        $this->assertCompileFails(
            InvalidArgumentException::class,
            'names "@app.logs", which is not a service id',
            ['sdk' => ['logs' => ['exporter' => '@app.logs']]],
        );
    }

    /**
     * @param class-string<\Throwable> $type
     * @param array<string, mixed> $config
     * @param (\Closure(ContainerBuilder): void)|null $configure
     */
    private function assertCompileFails(string $type, string $message, array $config, ?\Closure $configure = null): void
    {
        try {
            $this->compile($config, configure: $configure);
        } catch (\Throwable $throwable) {
            self::assertInstanceOf($type, $throwable, $throwable->getMessage());
            self::assertStringContainsString($message, $throwable->getMessage());

            return;
        }

        self::fail('The compile must fail with: ' . $message);
    }
}
