<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http\Server;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\KnownHttpMethods;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpServerMetrics;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\RequestMeasurement;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\RequestMeasurementRegistry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\RequestPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RequestRouteTemplateResolver;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelationSource;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\MetricPoints;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Request metrics stay correct, and silent towards the application, when their inputs misbehave. */
#[CoversClass(RequestMeasurement::class)]
#[CoversClass(RequestMeasurementRegistry::class)]
#[CoversClass(HttpServerMetrics::class)]
final class RequestMeasurementTest extends TestCase
{
    private RecordingLogger $logger;

    private InstrumentationFailureReporter $reporter;

    private InMemoryExporter $exporter;

    private MeterProviderInterface $meters;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->reporter = new InstrumentationFailureReporter($this->logger);
        $this->exporter = new InMemoryExporter();
        $this->meters = MeterProvider::builder()->addReader(new ExportingReader($this->exporter))->build();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->meters->shutdown();
    }

    /** @throws \Throwable */
    #[Test]
    public function anUnreadableTraceLeavesTheMeasurementUncorrelated(): void
    {
        $correlations = $this->createStub(TraceCorrelationSource::class);
        $correlations->method('current')->willThrowException(new \RuntimeException('context is gone'));

        new RequestMeasurement($this->metrics($correlations), [HttpAttributes::HTTP_REQUEST_METHOD => 'GET'])
            ->complete();

        self::assertSame(1, $this->reporter->total());
        self::assertStringContainsString('Trace correlation read failed', $this->logger->messageAt(0));
        self::assertSame(1, $this->durationPoints());
    }

    #[Test]
    public function anUnknownRouteAddsNoLabel(): void
    {
        $measurement = new RequestMeasurement($this->metrics(), [HttpAttributes::HTTP_REQUEST_METHOD => 'GET']);

        $measurement->route(null);
        $measurement->route('');

        self::assertArrayNotHasKey(HttpAttributes::HTTP_ROUTE, $measurement->attributes());
        $measurement->route('/orders/{id}');
        self::assertSame('/orders/{id}', $measurement->attributes()[HttpAttributes::HTTP_ROUTE] ?? null);
    }

    #[Test]
    public function aRequestIsRecordedOnceHoweverOftenItCompletes(): void
    {
        $measurement = new RequestMeasurement($this->metrics(), [HttpAttributes::HTTP_REQUEST_METHOD => 'GET']);

        $measurement->complete();
        $measurement->complete();

        self::assertSame(1, $this->durationPoints());
    }

    #[Test]
    public function routingARequestThatWasNeverMeasuredDoesNothing(): void
    {
        $registry = new RequestMeasurementRegistry($this->metrics(), $this->reporter);

        $registry->route(new Request(), new RequestPolicy());

        self::assertSame(0, $this->reporter->total());
    }

    /** @throws \Throwable */
    #[Test]
    public function aFailingRouteLookupIsReportedAndTheRequestStillCounts(): void
    {
        $routes = $this->createStub(RouteTemplateProvider::class);
        $routes->method('resolve')->willThrowException(new \RuntimeException('route cache is gone'));
        $registry = new RequestMeasurementRegistry($this->metrics(), $this->reporter);
        $request = Request::create('/orders/7');
        $request->attributes->set('_route', 'orders');

        $registry->open($request, new KnownHttpMethods());
        $registry->route($request, new RequestPolicy([], new RequestRouteTemplateResolver($routes)));
        $registry->finish($request);

        self::assertSame(1, $this->reporter->total());
        self::assertStringContainsString('HTTP metric route resolution failed', $this->logger->messageAt(0));
        self::assertSame(1, $this->durationPoints());
    }

    private function metrics(?TraceCorrelationSource $correlations = null): HttpServerMetrics
    {
        $recorder = new OtelDurationRecorder();

        return new HttpServerMetrics(
            new SafeMetrics($this->meters->getMeter('test'), $this->reporter, $recorder, new FrozenClock()),
            $correlations
            ?? new class implements TraceCorrelationSource {
                #[\Override]
                public function current(): null
                {
                    return null;
                }
            },
            $this->reporter,
            $recorder,
        );
    }

    private function durationPoints(): int
    {
        $this->meters->forceFlush();

        foreach ($this->exporter->collect(true) as $metric) {
            if ($metric->name === HttpMetrics::HTTP_SERVER_REQUEST_DURATION) {
                return MetricPoints::firstHistogram($metric)->count;
            }
        }

        return 0;
    }
}
