<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\RequestPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\NullRouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RequestRouteTemplateResolver;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\HttpServerTracingSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ParentContext;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelPropagation;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\Tests\Fake\CountingSpanExporter;
use Nmspaced\TelemetryWeaver\Tests\Fake\FlakySpanProcessor;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use Nmspaced\TelemetryWeaver\Tests\Support\TelemetryFactory;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\ExecutionContextAwareInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;

/** One set of services handling many requests in a row, as a FrankenPHP worker does. */
#[CoversClass(RequestTraceRegistry::class)]
#[CoversClass(HttpServerTracingSubscriber::class)]
final class HttpWorkerLifecycleTest extends TestCase
{
    private const int REQUESTS = 1_000;

    private ContextStorageInterface&ExecutionContextAwareInterface $previousStorage;

    private CountingSpanExporter $exporter;

    private TracerProvider $provider;

    private RecordingLogger $logger;

    private RequestTraceRegistry $scopes;

    private HttpKernel $kernel;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousStorage = Context::storage();
        Context::setStorage(new ContextStorage());

        $this->exporter = new CountingSpanExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->logger = new RecordingLogger();
        $context = Context::storage();
        $reporter = new InstrumentationFailureReporter($this->logger);
        $this->scopes = new RequestTraceRegistry(
            TelemetryFactory::tracing(
                new SpanOpener($this->provider->getTracer('worker'), $context, $reporter),
                $reporter,
            ),
            $reporter,
        );

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            KernelEvents::EXCEPTION,
            static fn(ExceptionEvent $event): null => $event->setResponse(new Response('', 500)),
            -100,
        );
        $dispatcher->addSubscriber(
            new HttpServerTracingSubscriber(
                $this->scopes,
                new ParentContext(
                    new OtelPropagation(TraceContextPropagator::getInstance(), $context, $reporter),
                    $reporter,
                ),
                new RequestPolicy(['/health'], new RequestRouteTemplateResolver(new NullRouteTemplateProvider())),
            ),
        );
        $this->kernel = new HttpKernel(
            $dispatcher,
            new ControllerResolver(),
            new RequestStack(),
            new ArgumentResolver(),
            handleAllThrowables: true,
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        while (($scope = Context::storage()->scope()) !== null) {
            $scope->detach();
        }

        $this->provider->shutdown();
        Context::setStorage($this->previousStorage);
    }

    /** @throws \Throwable */
    #[Test]
    public function aLongRunOfRequestsLeavesNothingBehind(): void
    {
        $instrumented = 0;

        for ($i = 0; $i < self::REQUESTS; ++$i) {
            $kind = $i % 4;
            $uri = $kind === 1 ? '/health/live' : '/orders/' . $i;
            $request = Request::create($uri);
            $request->attributes->set(
                '_controller',
                /** @throws \RuntimeException for the failing quarter */
                static fn(): Response => $kind === 2
                    ? throw new \RuntimeException('handled failure')
                    : new Response('', 200),
            );

            $response = $this->kernel->handle($request);

            if ($kind !== 3) {
                $this->kernel->terminate($request, $response);
            }

            if ($kind !== 1) {
                ++$instrumented;
            }

            $this->scopes->reset();
        }

        $this->scopes->reset();

        self::assertSame($instrumented, $this->exporter->exported);
        self::assertSame(
            [],
            $this->exporter->parentedTraceIds,
            'no request without traceparent may become a child of the previous one',
        );
        self::assertNull(Context::storage()->scope(), 'the context stack is back to where it started');
        self::assertSame([], $this->logger->records, 'a clean run reports nothing');
    }

    /** @throws \Throwable */
    #[Test]
    public function aFinishedRequestIsFullyReleased(): void
    {
        $request = Request::create('/orders/7');
        $request->attributes->set('_controller', static fn(): Response => new Response());

        $this->kernel->handle($request);
        $trace = $this->scopes->of($request);
        self::assertNotNull($trace);

        $requestRef = \WeakReference::create($request);
        $traceRef = \WeakReference::create($trace);

        $this->kernel->terminate($request, new Response());
        unset($request, $trace);
        \gc_collect_cycles();

        self::assertNull($requestRef->get(), 'the store must release the completed request');
        self::assertNull($traceRef->get(), 'the store must release the completed operation');
        self::assertSame(1, $this->exporter->exported);
    }

    /** @throws \Throwable */
    #[Test]
    public function oneFailingOperationDoesNotStrandTheRest(): void
    {
        $this->provider = new TracerProvider(new FlakySpanProcessor(new SimpleSpanProcessor($this->exporter), 'GET'));
        $reporter = new InstrumentationFailureReporter($this->logger);
        $scopes = new RequestTraceRegistry(
            TelemetryFactory::tracing(
                new SpanOpener($this->provider->getTracer('worker'), Context::storage(), $reporter),
                $reporter,
            ),
            $reporter,
        );

        $requests = [];

        for ($i = 0; $i < 3; ++$i) {
            $request = Request::create('/orders/' . $i);
            $requests[] = $request;
            $scopes->open($request, HttpMethod::from($request));
        }

        $scopes->reset();

        self::assertSame(3, $this->exporter->exported, 'every operation was attempted');
        self::assertCount(3, $this->logger->records, 'each failure is reported, not swallowed');
        self::assertNull(Context::storage()->scope());
    }
}
