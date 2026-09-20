<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\RequestPolicy;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RequestRouteTemplateResolver;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\HttpServerTracingSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ParentContext;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\ServerTraceResponseSubscriber;
use Nmspaced\TelemetryWeaver\Internal\Propagation\ResponsePropagation;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelPropagation;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelResponsePropagation;
use Nmspaced\TelemetryWeaver\Tests\Fake\StaticRouteTemplateProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\NoopResponsePropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A real dispatcher and a real HttpKernel.
 *
 * Calling the subscriber's methods directly would prove nothing about the
 * order Symfony actually fires them in, which is where every interesting
 * question in this adapter lives.
 *
 * @internal
 */
abstract class HttpTelemetryTestCase extends TelemetryTestCase
{
    protected RequestTraceRegistry $scopes;

    protected EventDispatcher $dispatcher;

    protected RequestStack $requestStack;

    protected HttpKernel $kernel;

    protected ResponsePropagation $responsePropagation;

    /**
     * @param array<string, string> $routes route name to template
     * @param list<non-empty-string> $excludedPaths
     * @param TextMapPropagatorInterface|null $propagator null is W3C trace context, the real default
     */
    protected function boot(
        array $routes = [],
        array $excludedPaths = [],
        ?TextMapPropagatorInterface $propagator = null,
    ): void {
        $this->responsePropagation ??= new OtelResponsePropagation(
            // @mago-expect analysis:experimental-usage
            NoopResponsePropagator::getInstance(),
            $this->contextStorage,
            $this->reporter,
        );
        $this->scopes = new RequestTraceRegistry(
            TelemetryFactory::tracing($this->spans, $this->reporter),
            $this->reporter,
        );
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(
            new HttpServerTracingSubscriber(
                $this->scopes,
                new ParentContext(
                    new OtelPropagation(
                        $propagator ?? TraceContextPropagator::getInstance(),
                        $this->contextStorage,
                        $this->reporter,
                    ),
                ),
                new RequestPolicy(
                    $excludedPaths,
                    new RequestRouteTemplateResolver(new StaticRouteTemplateProvider($routes)),
                ),
            ),
        );
        $this->dispatcher->addSubscriber(
            new ServerTraceResponseSubscriber(
                $this->responsePropagation,
                new RequestPolicy(
                    $excludedPaths,
                    new RequestRouteTemplateResolver(new StaticRouteTemplateProvider($routes)),
                ),
            ),
        );
        $this->requestStack = new RequestStack();
        $this->kernel = new HttpKernel(
            $this->dispatcher,
            new ControllerResolver(),
            $this->requestStack,
            new ArgumentResolver(),
            handleAllThrowables: true,
        );
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->boot();
    }

    /**
     * @param \Closure(): Response $controller
     * @param array<string, string> $attributes extra request attributes, e.g. _route
     * @param array<string, string> $headers
     *
     * @throws \Throwable
     */
    protected function request(
        \Closure $controller,
        string $uri = '/orders/7',
        array $attributes = [],
        array $headers = [],
    ): Request {
        $request = Request::create($uri);
        $request->attributes->set('_controller', $controller);

        foreach ($attributes as $key => $value) {
            $request->attributes->set($key, $value);
        }

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    /**
     * The full front-controller sequence, terminate included.
     *
     * @throws \Throwable
     */
    protected function handle(Request $request, bool $terminate = true): Response
    {
        $response = $this->kernel->handle($request);

        if ($terminate) {
            $this->kernel->terminate($request, $response);
        }

        return $response;
    }

    /**
     * @param \Closure(): Response $controller
     * @param array<string, string> $headers the main request's headers, when a test needs
     *                                       a sub-request that carries them the way a real
     *                                       forwarded request would
     *
     * @throws \Throwable
     */
    protected function subRequest(\Closure $controller, string $uri = '/fragment', array $headers = []): Response
    {
        return $this->kernel->handle(
            $this->request($controller, $uri, headers: $headers),
            HttpKernelInterface::SUB_REQUEST,
        );
    }
}
