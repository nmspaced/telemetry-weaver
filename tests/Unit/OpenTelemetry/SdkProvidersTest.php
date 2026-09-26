<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\ExportFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\FlushPolicy;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MeterProviderFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\MetricView;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ProviderRegistry;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SignalFlusher;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\SpanSuppressionStrategyFactory;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TelemetryFlusher;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TracerProviderFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\BreakableClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Metrics\View\SelectionCriteria\InstrumentNameCriteria;
use OpenTelemetry\SDK\Metrics\View\ViewTemplate;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** How the providers are assembled from the environment, and how the registry guards them. */
#[CoversClass(ProviderRegistry::class)]
#[CoversClass(MeterProviderFactory::class)]
#[CoversClass(TracerProviderFactory::class)]
#[CoversClass(TelemetryFlusher::class)]
final class SdkProvidersTest extends TestCase
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
        FlushPolicy::resetProcessState();
    }

    #[Test]
    public function aSecondProviderForTheSameSignalIsRefusedAndReported(): void
    {
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $registry = new ProviderRegistry(Flushers::openGate(), $reporter);
        $second = new TracerProvider();

        $registry->traces(new TracerProvider());

        self::assertSame($second, $registry->traces($second), 'the caller still gets its provider back');
        self::assertCount(1, \iterator_to_array($registry->ordered(), false));
        self::assertSame(1, $reporter->total());
    }

    #[Test]
    public function withoutAnExporterThereIsNothingToMeasureInto(): void
    {
        $provider = new MeterProviderFactory(ResourceInfoFactory::emptyResource(), null)->create();

        self::assertInstanceOf(NoopMeterProvider::class, $provider);
    }

    /** @return iterable<string, array{string}> */
    public static function exemplarFilters(): iterable
    {
        yield 'all' => ['all'];
        yield 'none' => ['none'];
        yield 'sampled traces' => ['with_sampled_trace'];
    }

    #[Test]
    #[DataProvider('exemplarFilters')]
    public function everyExemplarFilterBuildsAWorkingProvider(string $filter): void
    {
        $this->environment([Variables::OTEL_METRICS_EXEMPLAR_FILTER => $filter]);
        $exporter = new InMemoryMetricExporter();

        $provider = new MeterProviderFactory(ResourceInfoFactory::emptyResource(), $exporter)->create();
        self::assertInstanceOf(MeterProvider::class, $provider);
        $provider->getMeter('test')->createCounter('orders')->add(1);
        $provider->forceFlush();

        self::assertCount(1, $exporter->collect());
    }

    #[Test]
    public function configuredViewsShapeWhatIsExported(): void
    {
        $exporter = new InMemoryMetricExporter();
        $views = [new MetricView(new InstrumentNameCriteria('orders'), ViewTemplate::create()->withName('sales'))];

        $provider = new MeterProviderFactory(ResourceInfoFactory::emptyResource(), $exporter, $views)->create();
        self::assertInstanceOf(MeterProvider::class, $provider);
        $provider->getMeter('test')->createCounter('orders')->add(1);
        $provider->forceFlush();

        self::assertSame(
            ['sales'],
            \array_map(static fn(Metric $metric): string => $metric->name, $exporter->collect()),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function internalSdkMetricsGoToTheBundlesMeterProviderOnlyWhenEnabled(): void
    {
        $this->environment([Variables::OTEL_PHP_INTERNAL_METRICS_ENABLED => 'true']);
        $meters = $this->createMock(MeterProviderInterface::class);
        $meters->expects(self::atLeastOnce())->method('getMeter')->willReturn(new NoopMeterProvider()->getMeter('sdk'));

        new TracerProviderFactory(
            ResourceInfoFactory::emptyResource(),
            $meters,
            new InMemoryExporter(),
            SpanSuppressionStrategyFactory::create(),
        )->create();
    }

    #[Test]
    public function aFlushThatBreaksBeforeReachingTheSdkIsReportedAndEndsTheBudget(): void
    {
        $reporter = new ExportFailureReporter(new RecordingLogger());
        $budget = new FlushBudget(100);
        $clock = new BreakableClock();
        $clock->broken = true;
        $signal = new SignalFlusher(
            new TracerProvider(),
            FlushPolicy::onSdkSchedule('traces'),
            $reporter,
            clock: $clock,
        );

        Flushers::coordinating($signal, $signal, $signal, $budget, $reporter)->atBoundary();

        self::assertFalse($budget->active());
        self::assertGreaterThanOrEqual(1, $reporter->total());
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
