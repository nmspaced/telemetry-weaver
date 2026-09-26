<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http\Server;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\KnownHttpMethods;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpBodySize;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpServerMetrics;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpServerMetricsSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\RequestMeasurementRegistry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\PhpFileRouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouterRouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateProviderFactory;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ParentContext;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRecorder;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\RootTrace;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelationSource;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelDurationRecorder;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

/** Inputs of the server instrumentation that arrive incomplete, malformed, or from a failing dependency. */
#[CoversClass(HttpBodySize::class)]
#[CoversClass(RouteTemplateProviderFactory::class)]
#[CoversClass(RouterRouteTemplateProvider::class)]
#[CoversClass(ParentContext::class)]
#[CoversClass(RequestMeasurementRegistry::class)]
#[CoversClass(HttpServerMetricsSubscriber::class)]
final class ServerInputTest extends TestCase
{
    private RecordingLogger $logger;

    private InstrumentationFailureReporter $reporter;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->reporter = new InstrumentationFailureReporter($this->logger);
    }

    #[Test]
    public function aDeclaredResponseLengthIsTakenAsIs(): void
    {
        self::assertSame(3, HttpBodySize::ofResponse(new Response('abc', 200, ['Content-Length' => '3'])));
    }

    #[Test]
    public function outsideDebugTheRouteTemplatesComeFromTheWarmedFile(): void
    {
        self::assertInstanceOf(
            PhpFileRouteTemplateProvider::class,
            RouteTemplateProviderFactory::create(\sys_get_temp_dir()),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function aRouterThatCannotListItsRoutesResolvesNothing(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willThrowException(new \RuntimeException('routing is broken'));

        self::assertNull(new RouterRouteTemplateProvider($router)->resolve('orders'));
    }

    /** @throws \Throwable */
    #[Test]
    public function headersWithoutANameOrValueAreNotHandedToThePropagator(): void
    {
        $carriers = [];
        $propagation = $this->createStub(Propagation::class);
        $propagation
            ->method('extract')
            ->willReturnCallback(
                /** @param array<non-empty-string, string> $carrier */
                static function (array $carrier) use (&$carriers): RootTrace {
                    $carriers[] = $carrier;

                    return new RootTrace();
                },
            );
        $request = Request::create('/orders/7');
        $request->headers->replace([]);
        $request->headers->set('traceparent', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');
        $request->headers->set('', 'nameless');
        $request->headers->set('x-empty', null);

        new ParentContext($propagation, $this->reporter)->fromHeaders($request);

        self::assertSame([['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']], $carriers);
    }

    #[Test]
    public function aRequestThatCannotDescribeItselfIsNotMeasured(): void
    {
        $registry = new RequestMeasurementRegistry($this->metrics(new OtelDurationRecorder()), $this->reporter);
        $request = new class extends Request {
            /** @throws \RuntimeException always */
            #[\Override]
            public function getScheme(): string
            {
                throw new \RuntimeException('trusted proxies are misconfigured');
            }
        };

        $registry->open($request, new KnownHttpMethods());

        self::assertNull($registry->of($request));
        self::assertStringContainsString('HTTP measurement setup failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function aRecordingFailureAtTheEndIsReportedNotThrown(): void
    {
        $recorder = $this->createStub(DurationRecorder::class);
        $recorder->method('record')->willThrowException(new \RuntimeException('meter is gone'));
        $registry = new RequestMeasurementRegistry($this->metrics($recorder), $this->reporter);
        $request = Request::create('/orders', 'POST', server: ['CONTENT_LENGTH' => '7']);
        $registry->open($request, new KnownHttpMethods());

        $registry->finish($request);

        self::assertNull($registry->of($request));
        self::assertStringContainsString('HTTP duration recording failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    #[Test]
    public function aSubRequestFailureDoesNotMarkTheMainRequest(): void
    {
        $registry = new RequestMeasurementRegistry($this->metrics(new OtelDurationRecorder()), $this->reporter);
        $request = Request::create('/orders/7');
        $registry->open($request, new KnownHttpMethods());

        new HttpServerMetricsSubscriber($registry)->onException(
            new ExceptionEvent(
                $this->createStub(HttpKernelInterface::class),
                $request,
                HttpKernelInterface::SUB_REQUEST,
                new \RuntimeException('fragment failed'),
            ),
        );

        self::assertFalse($registry->of($request)?->isUnfinishedAfterException() ?? true);
    }

    private function metrics(DurationRecorder $recorder): HttpServerMetrics
    {
        return new HttpServerMetrics(
            new SafeMetrics(
                MeterProvider::builder()->build()->getMeter('test'),
                $this->reporter,
                new OtelDurationRecorder(),
                new FrozenClock(),
            ),
            new class implements TraceCorrelationSource {
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
}
