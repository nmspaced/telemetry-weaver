<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\BudgetedOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\CustomOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientExporters;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientMetricsExporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\Exporter\ResilientTracesExporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ExporterName;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\LogRecordExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricExporterFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpProtocol;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\RequestMetricPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SpanExporterFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingTransportFactory;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanExporterFactory::class)]
#[CoversClass(MetricExporterFactory::class)]
#[CoversClass(LogRecordExporterFactory::class)]
#[CoversClass(ExporterName::class)]
final class ExporterFactoryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $previousEnvironment = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name]);

                continue;
            }

            $_SERVER[$name] = $value;
        }

        $this->previousEnvironment = [];
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function noneMeansNoExporterRatherThanAnError(): void
    {
        $this->environment([Variables::OTEL_TRACES_EXPORTER => 'none']);

        self::assertNull(new SpanExporterFactory(self::resilient(), self::transports())->create());
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function moreThanOneExporterIsRejected(): void
    {
        $this->environment([Variables::OTEL_METRICS_EXPORTER => 'otlp,memory']);

        $this->expectException(\InvalidArgumentException::class);

        $reporter = new ExportFailureReporter(new RecordingLogger());
        new MetricExporterFactory(
            self::resilient(),
            self::transports(),
            RequestMetricPolicy::forRuntime(
                SymfonyRuntimeProfile::fromKernel(1, true),
                'disabled',
                $reporter,
                ResourceInfo::emptyResource(),
            ),
        )->create();
    }

    /**
     * A non-OTLP exporter has no transport to configure, so it keeps going through the
     * Registry — but it is still wrapped: a dead exporter must not reach the caller,
     * whichever way it was built.
     */
    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function aNonOtlpExporterComesFromTheRegistryAndIsStillWrapped(): void
    {
        $this->environment([Variables::OTEL_TRACES_EXPORTER => 'memory']);

        self::assertInstanceOf(
            ResilientTracesExporter::class,
            new SpanExporterFactory(self::resilient(), self::transports())->create(),
        );
    }

    private static function transports(): OtlpTransports
    {
        return new BudgetedOtlpTransports(Flushers::openGate());
    }

    private static function resilient(): ResilientExporters
    {
        return new ResilientExporters(new ExportFailureReporter(new RecordingLogger()), Flushers::openGate());
    }

    #[Test]
    public function anApplicationsMetricExporterIsHeldToTheRequestPipelinesRules(): void
    {
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $factory = static fn(SymfonyRuntimeProfile $runtime): MetricExporterFactory => new MetricExporterFactory(
            self::resilient(),
            self::transports(),
            RequestMetricPolicy::forRuntime($runtime, 'disabled', $reporter, ResourceInfo::emptyResource()),
        );

        self::assertNull($factory(SymfonyRuntimeProfile::fromKernel(0, true))->adopt(new InMemoryExporter()));
        self::assertInstanceOf(
            ResilientMetricsExporter::class,
            $factory(SymfonyRuntimeProfile::fromKernel(1, true))->adopt(new InMemoryExporter()),
        );
    }

    /**
     * The seam an application's transport arrives through: the OTLP exporter is built on the
     * factory the container handed over, and the bundle's retry override is not in the path.
     *
     * @throws \RuntimeException
     */
    #[Test]
    public function theOtlpExporterIsBuiltOnTheTransportsItWasGiven(): void
    {
        $this->environment([
            Variables::OTEL_TRACES_EXPORTER => 'otlp',
            Variables::OTEL_EXPORTER_OTLP_PROTOCOL => 'http/json',
        ]);
        $factory = new RecordingTransportFactory();

        new SpanExporterFactory(self::resilient(), new CustomOtlpTransports([
            OtlpProtocol::HTTP => $factory,
        ], self::transports()))->create();

        self::assertSame(3, $factory->argument('maxRetries'), 'the SDK default, not the bundle override');
    }

    /**
     * @throws \RuntimeException
     */
    #[Test]
    public function logsFollowTheSameRule(): void
    {
        $this->environment([Variables::OTEL_LOGS_EXPORTER => 'none']);

        self::assertNull(new LogRecordExporterFactory(self::resilient(), self::transports())->create());
    }

    /** @param array<string, string> $variables */
    private function environment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            $this->previousEnvironment[$name] = $_SERVER[$name] ?? null;
            $_SERVER[$name] = $value;
        }
    }
}
