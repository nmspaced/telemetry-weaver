<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrineMiddleware;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrinePolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\DoctrineTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Fake\FakeDbalDriver;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * A real DBAL stack with the instrumentation middleware over a fake driver.
 *
 * @internal
 */
abstract class DoctrineTelemetryTestCase extends TestCase
{
    protected ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    protected InMemoryExporter $spans;

    protected TracerProvider $tracers;

    protected InMemoryMetricExporter $metrics;

    protected ExportingReader $reader;

    protected MeterProviderInterface $meters;

    protected RecordingLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousStorage = Context::storage();
        Context::setStorage(new ContextStorage());

        $this->spans = new InMemoryExporter();
        $this->tracers = new TracerProvider(new SimpleSpanProcessor($this->spans));

        $this->metrics = new InMemoryMetricExporter();
        $this->reader = new ExportingReader($this->metrics);
        $this->meters = MeterProvider::builder()->addReader($this->reader)->build();

        $this->logger = new RecordingLogger();
    }

    #[\Override]
    protected function tearDown(): void
    {
        while (($scope = Context::storage()->scope()) !== null) {
            $scope->detach();
        }

        $this->meters->shutdown();
        $this->tracers->shutdown();
        Context::setStorage($this->previousStorage);
    }

    /**
     * @param 'pdo_mysql'|'pdo_pgsql'|'pdo_sqlite'|'pdo_sqlsrv'|'pdo_oci'|'ibm_db2' $driver the name the instrumentation reads; the fake driver runs either way
     *
     * @throws Exception
     */
    protected function connection(
        ?DoctrinePolicy $policy = null,
        ?MeterInterface $meter = null,
        ?SpanOpenerInterface $spanOpener = null,
        string $driver = 'pdo_pgsql',
    ): Connection {
        $reporter = new InstrumentationFailureReporter($this->logger);

        $telemetry = new DoctrineTelemetry(
            TelemetryFactory::create(
                $meter ?? $this->meters->getMeter('test'),
                $spanOpener ?? new SpanOpener($this->tracers->getTracer('test'), Context::storage(), $reporter),
                $reporter,
                new FrozenClock(),
            ),
            $policy ?? new DoctrinePolicy(onlyWithParent: false),
        );

        return DriverManager::getConnection(
            [
                'driver' => $driver,
                'driverClass' => FakeDbalDriver::class,
                'dbname' => 'app',
                'host' => 'db.internal',
                'port' => 5432,
            ],
            new Configuration()->setMiddlewares([new DoctrineMiddleware($telemetry)]),
        );
    }

    /** @return list<string> */
    protected function exportedNames(): array
    {
        return \array_map(
            static fn(ImmutableSpan $span): string => $span->getName(),
            \array_values($this->spans->getSpans()),
        );
    }

    protected function exportedSpan(int $index = 0): ImmutableSpan
    {
        $span = \array_values($this->spans->getSpans())[$index] ?? null;
        self::assertInstanceOf(ImmutableSpan::class, $span, 'no exported span at index ' . $index);

        return $span;
    }

    /** @return list<string> */
    protected function recordedMetricNames(): array
    {
        return \array_map(static fn(Metric $metric): string => $metric->name, \array_values($this->metrics->collect()));
    }

    /**
     * @param non-empty-string $name
     *
     * @return list<HistogramDataPoint>
     */
    protected function histogramPoints(string $name): array
    {
        foreach ($this->metrics->collect() as $metric) {
            if ($metric->name !== $name) {
                continue;
            }

            return \array_values(\array_filter(
                MetricPoints::of($metric),
                static fn(HistogramDataPoint|NumberDataPoint $point): bool => $point instanceof HistogramDataPoint,
            ));
        }

        return [];
    }

    protected function histogram(string $name): HistogramDataPoint
    {
        foreach ($this->metrics->collect() as $metric) {
            if ($metric->name !== $name) {
                continue;
            }

            $point = MetricPoints::first($metric);
            self::assertInstanceOf(HistogramDataPoint::class, $point);

            return $point;
        }

        Assert::fail(\sprintf('no "%s" metric was recorded', $name));
    }
}
